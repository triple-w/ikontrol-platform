<?php
declare(strict_types=1);

namespace App\Services;

use App\Services\Fiscal\FiscalDecimal;
use App\Services\Fiscal\FiscalIssueDateService;
use RuntimeException;
use Throwable;

final class PaymentComplementFiscalSnapshotService
{
    public function __construct(private mixed $db)
    {
    }

    public function compose(int $id, bool $preferFrozen = true): array
    {
        if ($preferFrozen && ($frozen = $this->frozen($id))) return $frozen;
        $complement = $this->db->table('payment_complements')->where(['id' => $id, 'deleted' => 0])->get(1)->getRow();
        if (!$complement || !in_array($complement->status, ['draft', 'complete_draft', 'stamped'], true)) throw new RuntimeException('El complemento no existe.');
        $issuerResolver = new \App\Services\Fiscal\FiscalIssuerResolver($this->db);
        $issuer = $complement->issuer_profile_id ? $issuerResolver->resolveById((int) $complement->issuer_profile_id) : $issuerResolver->resolve();
        $receiver = $this->db->table('fiscal_profiles fp')->select('fp.*,tr.code tax_regime_code')
            ->join('sat_tax_regimes tr', 'tr.id=fp.tax_regime_id', 'left')
            ->where(['fp.client_id' => $complement->client_id, 'fp.profile_type' => 'receiver'])
            ->whereIn('fp.status', ['active', 'ready'])->orderBy('fp.is_default', 'DESC')->get(1)->getRow();
        if (!$issuer) throw new RuntimeException('No existe un emisor fiscal con CSD vigente.');
        if (!$receiver) throw new RuntimeException('El cliente no tiene un perfil fiscal receptor vigente.');

        $payments = $this->db->table('payment_complement_payments p')
            ->select('p.*,fm.sat_payment_form_code mapped_payment_form')
            ->join('invoice_payments ip', 'ip.id=p.source_invoice_payment_id')
            ->join('fiscal_payment_method_mappings fm', 'fm.payment_method_id=ip.payment_method_id AND fm.is_active=1', 'left')
            ->where(['p.payment_complement_id' => $id, 'p.deleted' => 0])->get()->getResult();
        $out = [
            'version' => 8,
            'complement_id' => $id,
            'issue_date' => (new FiscalIssueDateService())->formatForXml((string) $complement->issue_date),
            'client_id' => (int) $complement->client_id,
            'issuer' => ['id'=>(int)$issuer->id,'certificate_id'=>(int)$issuer->certificate_id,'rfc'=>$issuer->rfc,'legal_name'=>$issuer->legal_name,'tax_regime_code'=>$issuer->tax_regime_code,'expedition_postal_code'=>$issuer->fiscal_postal_code],
            'receiver' => ['id'=>(int)$receiver->id,'rfc'=>$receiver->rfc,'legal_name'=>$receiver->legal_name,'tax_regime_code'=>$receiver->tax_regime_code,'fiscal_postal_code'=>$receiver->fiscal_postal_code,'cfdi_use_code'=>'CP01'],
            'payments' => [],
            'totals' => ['MontoTotalPagos'=>'0.000000','TrasladosP'=>[],'RetencionesP'=>[]],
        ];
        $normalizer = new PaymentComplementRelatedDocumentNormalizer($this->db);
        foreach ($payments as $payment) {
            $documents = $normalizer->forPayment($id, (int) $payment->id);
            if (!$documents) throw new RuntimeException('El pago fiscal no contiene documentos relacionados.');
            $entry = [
                'id'=>(int)$payment->id,'source_invoice_payment_id'=>(int)$payment->source_invoice_payment_id,
                'FechaPago'=>$payment->payment_date.'T12:00:00','FormaDePagoP'=>$payment->payment_form_code?:$payment->mapped_payment_form,
                'MonedaP'=>$payment->currency_code?:'MXN','TipoCambioP'=>(string)$payment->exchange_rate,'Monto'=>(string)$payment->amount,
                'NumOperacion'=>$payment->operation_number,'RfcEmisorCtaOrd'=>$payment->ordering_bank_rfc,'NomBancoOrdExt'=>$payment->ordering_bank_name,
                'CtaOrdenante'=>$payment->ordering_account,'RfcEmisorCtaBen'=>$payment->beneficiary_bank_rfc,'CtaBeneficiario'=>$payment->beneficiary_account,
                'TipoCadPago'=>$payment->payment_chain_type,'CertPago'=>$payment->payment_certificate,'CadPago'=>$payment->payment_chain,'SelloPago'=>$payment->payment_signature,
                'DoctoRelacionado'=>$documents,
            ];
            foreach ($documents as $document) $this->aggregateDocumentTaxes($out['totals'], $document, (string) $entry['MonedaP']);
            $out['payments'][] = $entry;
            $out['totals']['MontoTotalPagos'] = FiscalDecimal::add($out['totals']['MontoTotalPagos'], (string) $payment->amount);
        }
        if (!$out['payments']) throw new RuntimeException('El complemento no contiene un pago administrativo derivado.');
        $out['sat_totals'] = $this->satTotals($out['totals']);
        $out['xml'] = (new PaymentComplementCfdiMaterializer())->materialize($out);
        $hash = $out; unset($hash['xml']);
        $out['sha256'] = hash('sha256', json_encode($hash, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $out;
    }

    public function build(int $id, ?int $actor = null): array
    {
        $this->db->transBegin();
        try {
            $table = $this->db->protectIdentifiers($this->db->prefixTable('payment_complements'));
            $header = $this->db->query("SELECT * FROM {$table} WHERE id=? AND deleted=0 FOR UPDATE", [$id])->getRow();
            if (!$header || !in_array($header->status, ['draft', 'complete_draft'], true)) throw new RuntimeException('El complemento no admite snapshot fiscal.');
            if ($frozen = $this->frozen($id)) { $this->db->transCommit(); return $frozen; }
            $out = $this->compose($id, false);
            $version = (int) ($this->db->table('payment_complement_fiscal_snapshots')->selectMax('version', 'v')->where('payment_complement_id', $id)->get()->getRow()->v ?? 0) + 1;
            $this->db->table('payment_complement_fiscal_snapshots')->insert([
                'payment_complement_id'=>$id,'version'=>$version,'status'=>'frozen',
                'payload_json'=>json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                'xml_content'=>$out['xml'],'sha256'=>$out['sha256'],'created_by'=>$actor,'created_at'=>get_current_utc_time(),
            ]);
            if (!$this->db->transStatus()) throw new RuntimeException('No fue posible congelar el snapshot fiscal.');
            $this->db->transCommit();
            return $out;
        } catch (Throwable $e) { $this->db->transRollback(); throw $e; }
    }

    private function frozen(int $id): ?array
    {
        $row = $this->db->table('payment_complement_fiscal_snapshots')->where('payment_complement_id', $id)->orderBy('version', 'DESC')->get(1)->getRow();
        if (!$row) return null;
        $payload = json_decode((string) $row->payload_json, true);
        if (!is_array($payload) || !hash_equals((string) $row->sha256, (string) ($payload['sha256'] ?? ''))) throw new RuntimeException('El snapshot fiscal congelado no supera integridad.');
        $integrity = $payload;
        unset($integrity['xml'], $integrity['sha256']);
        $calculated = hash('sha256', json_encode($integrity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if (!hash_equals((string) $row->sha256, $calculated)) throw new RuntimeException('El contenido del snapshot fiscal congelado fue alterado.');
        if (!hash_equals(hash('sha256', (string) $row->xml_content), hash('sha256', (string) ($payload['xml'] ?? '')))) throw new RuntimeException('El XML congelado no coincide con el snapshot.');
        return $payload;
    }

    private function aggregateDocumentTaxes(array &$totals, array $document, string $paymentCurrency): void
    {
        foreach (['TrasladosDR'=>'TrasladosP','RetencionesDR'=>'RetencionesP'] as $source=>$target) {
            foreach ($document['ImpuestosDR'][$source] ?? [] as $tax) {
                $key = implode('|', [$tax['ImpuestoDR'], $tax['TipoFactorDR'], (string)($tax['TasaOCuotaDR'] ?? '')]);
                $base = (string) $tax['BaseDR'];
                $amount = (string) ($tax['ImporteDR'] ?? '0.000000');
                if (strtoupper((string)$document['MonedaDR']) !== strtoupper($paymentCurrency)) {
                    $base = PaymentComplementAmountConverter::toPaymentCurrency($base, (string)$document['MonedaDR'], (string)$document['EquivalenciaDR'], $paymentCurrency);
                    $amount = PaymentComplementAmountConverter::toPaymentCurrency($amount, (string)$document['MonedaDR'], (string)$document['EquivalenciaDR'], $paymentCurrency);
                }
                $totals[$target][$key]['BaseP'] = FiscalDecimal::add($totals[$target][$key]['BaseP'] ?? '0.000000', $base);
                $totals[$target][$key]['ImporteP'] = FiscalDecimal::add($totals[$target][$key]['ImporteP'] ?? '0.000000', $amount);
                $totals[$target][$key]['ImpuestoP'] = $tax['ImpuestoDR'];
                $totals[$target][$key]['TipoFactorP'] = $tax['TipoFactorDR'];
                $totals[$target][$key]['TasaOCuotaP'] = $tax['TasaOCuotaDR'] ?? null;
            }
        }
    }

    private function satTotals(array $totals): array
    {
        $out=['MontoTotalPagos'=>$totals['MontoTotalPagos']];
        foreach($totals['TrasladosP'] as $tax){if($tax['ImpuestoP']!=='002')continue;$suffix=$tax['TipoFactorP']==='Exento'?'Exento':match((string)$tax['TasaOCuotaP']){'0.160000'=>'16','0.080000'=>'8','0.000000'=>'0',default=>null};if($suffix===null)continue;$out['TotalTrasladosBaseIVA'.$suffix]=$tax['BaseP'];if($suffix!=='Exento')$out['TotalTrasladosImpuestoIVA'.$suffix]=$tax['ImporteP'];}
        foreach($totals['RetencionesP'] as $tax){$name=['001'=>'ISR','002'=>'IVA','003'=>'IEPS'][$tax['ImpuestoP']]??null;if($name)$out['TotalRetenciones'.$name]=FiscalDecimal::add($out['TotalRetenciones'.$name]??'0.000000',$tax['ImporteP']);}
        foreach($out as$key=>$value)$out[$key]=$this->satMoney((string)$value);
        return$out;
    }

    private function satMoney(string $value): string
    {
        $micros=FiscalDecimal::micros($value);if($micros<0)throw new RuntimeException('Los totales Pago 2.0 no pueden ser negativos.');$cents=intdiv($micros+5000,10000);return intdiv($cents,100).'.'.str_pad((string)($cents%100),2,'0',STR_PAD_LEFT);
    }
}
