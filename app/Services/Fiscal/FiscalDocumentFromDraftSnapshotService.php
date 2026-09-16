<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use RuntimeException;
use Throwable;

final class FiscalDocumentFromDraftSnapshotService
{
    public function __construct(private mixed $db = null, private ?FiscalDraftStampingPreflightService $preflight = null)
    {
        $this->db ??= db_connect();
        $this->preflight ??= new FiscalDraftStampingPreflightService($this->db);
    }

    public function materialize(int $draftId, int $userId, bool $saleFlow = false): int
    {
        $this->db->transBegin();
        try {
            $draftTable = $this->db->prefixTable('fiscal_drafts');
            $this->db->query("SELECT id FROM {$draftTable} WHERE id=? FOR UPDATE", [$draftId]);
            $snapshot = $saleFlow ? $this->preflight->requireReadyForSaleFlow($draftId) : $this->preflight->requireReady($draftId);
            $draft = $snapshot['draft'];
            $seriesTable = $this->db->prefixTable('fiscal_series');
            $series = $this->db->query(
                "SELECT * FROM {$seriesTable} WHERE id=? AND issuer_profile_id=? AND is_active=1 AND deleted=0 FOR UPDATE",
                [(int)$draft['fiscal_series_id'], (int)$draft['issuer_id']]
            )->getRowArray();
            if (!$series) throw new RuntimeException('La serie fiscal del snapshot ya no está disponible.');
            $folio = max((int)$series['initial_folio'], (int)$series['current_folio'] + 1);
            $now = get_current_utc_time();
            $sourceHash = (new FiscalDraftSnapshotHashService())->hash($snapshot);
            $firstSale = (int)$snapshot['allocations'][0]['sale_id'];
            $currencyTotals=$this->currencyTotals($snapshot);
            $document = [
                'invoice_id' => $firstSale, 'source_draft_id' => $draftId,
                'environment'=>(string)($draft['environment']??config('Fiscal')->environment),
                'data_origin'=>(string)($draft['data_origin']??'operational'),'is_test_fixture'=>0,
                'issuer_profile_id' => (int)$draft['issuer_id'],
                'receiver_profile_id' => (int)$draft['receiver_profile_id'],
                'fiscal_series_id' => (int)$draft['fiscal_series_id'],
                'document_type' => 'income', 'status' => 'locked', 'version' => 1,
                'series' => (string)$series['series'], 'folio' => $folio,
                'issue_date' => (string)$draft['issue_date'],
                'expedition_postal_code' => (string)$draft['expedition_postal_code'],
                'currency_code' => (string)$draft['currency_code'],
                'exchange_rate' => (string)$draft['exchange_rate'],
                'payment_form_code' => (string)$draft['payment_form_code'],
                'payment_method_code' => (string)$draft['payment_method_code'],
                'cfdi_use_code' => (string)$draft['cfdi_use_code'], 'export_code' => '01',
                'subtotal'=>$currencyTotals['subtotal'],'discount'=>$currencyTotals['discount'],
                'transferred_tax_total'=>$currencyTotals['transferred'],
                'withheld_tax_total'=>$currencyTotals['withheld'],'total'=>$currencyTotals['total'],
                'administrative_total_reference' => $snapshot['totals']['total'],
                'pricing_mode' => (string)($snapshot['issuer_snapshot']['tax_pricing_mode'] ?? 'snapshot'),
                'source_snapshot_hash' => $sourceHash, 'created_by' => $userId,
                'created_at' => $now, 'updated_at' => $now, 'locked_at' => $now, 'deleted' => 0,
            ];
            if (!$this->db->table('fiscal_documents')->insert($document)) throw new RuntimeException('No fue posible crear el documento fiscal.');
            $documentId = (int)$this->db->insertID();
            $this->insertParties($documentId, $snapshot, $now);
            $this->insertItems($documentId, $snapshot, $now);
            $this->insertTaxTotals($documentId, $snapshot['item_taxes'], $now);
            $this->db->table('fiscal_document_metadata')->insert([
                'fiscal_document_id' => $documentId,
                'metadata_json' => json_encode([
                    'source' => 'fiscal_draft_snapshot_v2', 'draft_id' => $draftId,
                    'snapshot_hash' => $sourceHash, 'snapshot_version' => 2,
                ], JSON_UNESCAPED_SLASHES),
                'warnings_json' => '[]', 'rules_version' => 'ikontrol-draft-snapshot-v2',
                'payment_total_snapshot' => $snapshot['totals']['total'], 'created_at' => $now,
            ]);
            $this->db->table('fiscal_series')->where('id', $series['id'])->update(['current_folio' => $folio, 'updated_at' => $now]);
            $this->db->table('fiscal_drafts')->where('id', $draftId)->update([
                'fiscal_document_id' => $documentId, 'status' => 'stamping',
                'updated_by' => $userId, 'updated_at' => $now,
            ]);
            $this->audit($draftId, $userId, 'draft_document_created', ['document_id' => $documentId, 'snapshot_hash' => $sourceHash]);
            if (!$this->db->transStatus()) throw new RuntimeException('No fue posible materializar el snapshot fiscal.');
            $this->db->transCommit();
            return $documentId;
        } catch (Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }
    }

    private function insertParties(int $documentId, array $snapshot, string $now): void
    {
        $issuer = $snapshot['issuer_snapshot'];
        $receiver = $snapshot['receiver_snapshot'];
        $this->db->table('fiscal_document_issuers')->insert([
            'fiscal_document_id' => $documentId, 'rfc' => $issuer['rfc'],
            'legal_name' => $issuer['legal_name'], 'tax_regime_code' => $issuer['tax_regime_code'],
            'fiscal_postal_code' => $issuer['fiscal_postal_code'],
            'expedition_postal_code' => $snapshot['draft']['expedition_postal_code'],
            'country_code' => $issuer['fiscal_country_code'] ?? 'MEX',
            'street' => $issuer['fiscal_street'] ?? null, 'external_number' => $issuer['fiscal_external_number'] ?? null,
            'internal_number' => $issuer['fiscal_internal_number'] ?? null, 'neighborhood' => $issuer['fiscal_neighborhood'] ?? null,
            'locality' => $issuer['fiscal_locality'] ?? null, 'municipality' => $issuer['fiscal_municipality'] ?? null,
            'state' => $issuer['fiscal_state'] ?? null, 'created_at' => $now,
        ]);
        $this->db->table('fiscal_document_receivers')->insert([
            'fiscal_document_id' => $documentId, 'rfc' => $receiver['rfc'],
            'legal_name' => $receiver['legal_name'],
            'tax_regime_code' => $snapshot['draft']['receiver_tax_regime_code'],
            'fiscal_postal_code' => $snapshot['draft']['receiver_postal_code'],
            'cfdi_use_code' => $snapshot['draft']['cfdi_use_code'],
            'fiscal_residence_country_code' => $receiver['tax_residency_country'] ?? null,
            'foreign_tax_registration' => $receiver['foreign_tax_registration'] ?? null,
            'street' => $receiver['fiscal_street'] ?? null, 'external_number' => $receiver['fiscal_external_number'] ?? null,
            'internal_number' => $receiver['fiscal_internal_number'] ?? null, 'neighborhood' => $receiver['fiscal_neighborhood'] ?? null,
            'locality' => $receiver['fiscal_locality'] ?? null, 'municipality' => $receiver['fiscal_municipality'] ?? null,
            'state' => $receiver['fiscal_state'] ?? null, 'created_at' => $now,
        ]);
    }

    private function insertItems(int $documentId, array $snapshot, string $now): void
    {
        $money = new FiscalDecimalCalculator();
        foreach ($snapshot['items'] as $index => $item) {
            $s = $item['snapshot'];
            $this->db->table('fiscal_document_items')->insert([
                'fiscal_document_id' => $documentId, 'invoice_item_id' => $item['sale_item_id'],
                'item_id' => $item['product_id'], 'line_number' => $index + 1,
                'product_service_code' => $s['product_service_code'],
                'identification_number' => $s['identification_number'] ?? null,
                'quantity' => $item['quantity'], 'unit_code' => $s['unit_code'],
                'unit_name' => $s['commercial_unit'] ?? 'Pieza',
                'description' => $s['fiscal_description'] ?? $s['description'] ?? $s['title'],
                // CFDI Importe must equal Cantidad × ValorUnitario. For tax-inclusive
                // sales the commercial rate is gross; the fiscal snapshot subtotal is net.
                'unit_value' => FiscalDecimal::divide((string)$item['subtotal'], (string)$item['quantity']),
                'gross_amount' => $money->money((string)$item['subtotal']),
                'discount' => $money->money((string)$item['discount']), 'tax_object_code' => $s['object_tax'] ?? $s['tax_object_code'],
                'taxable_base' => $money->money((string)$s['taxable_base']), 'transferred_tax_total' => $money->money((string)$s['transferred_total']),
                'withheld_tax_total' => $money->money((string)$s['withheld_total']), 'line_total' => $money->money((string)$item['total']), 'created_at' => $now,
            ]);
            $documentItemId = (int)$this->db->insertID();
            foreach ($item['taxes'] as $order => $tax) {
                $documentTaxType = $this->documentTaxType((string)$tax['tax_type']);
                $this->db->table('fiscal_document_item_taxes')->insert([
                    'fiscal_document_item_id' => $documentItemId, 'administrative_tax_id' => null,
                    'tax_code' => $tax['tax_code'], 'tax_type' => $documentTaxType,
                    'factor_type' => $tax['factor_type'], 'rate_or_quota' => $tax['rate_or_quota'],
                    'taxable_base' => $money->money((string)$tax['tax_base']), 'amount' => $money->money((string)$tax['tax_amount']),
                    'sort_order' => $order, 'created_at' => $now,
                ]);
            }
        }
    }

    private function insertTaxTotals(int $documentId, array $taxes, string $now): void
    {
        $groups = [];
        foreach ($taxes as $tax) {
            $documentTaxType = $this->documentTaxType((string)$tax['tax_type']);
            $key = implode('|', [$tax['tax_code'], $documentTaxType, $tax['factor_type'], $tax['rate_or_quota'] ?? '']);
            if (!isset($groups[$key])) {
                $groups[$key] = $tax;
                $groups[$key]['tax_type'] = $documentTaxType;
                $groups[$key]['tax_base'] = '0.000000';
                $groups[$key]['tax_amount'] = '0.000000';
            }
            $money=new FiscalDecimalCalculator();
            $groups[$key]['tax_base']=FiscalDecimal::add($groups[$key]['tax_base'],$money->money((string)$tax['tax_base']));
            $groups[$key]['tax_amount']=FiscalDecimal::add($groups[$key]['tax_amount'],$money->money((string)$tax['tax_amount']));
        }
        foreach ($groups as $tax) {
            $this->db->table('fiscal_document_tax_totals')->insert([
                'fiscal_document_id' => $documentId, 'tax_code' => $tax['tax_code'],
                'tax_type' => $tax['tax_type'], 'factor_type' => $tax['factor_type'],
                'rate_or_quota' => $tax['rate_or_quota'], 'taxable_base' => $tax['tax_base'],
                'amount' => $tax['tax_amount'], 'created_at' => $now,
            ]);
        }
    }

    private function documentTaxType(string $type): string
    {
        return match ($type) {
            'transfer', 'transferred' => 'transferred',
            'withholding', 'withheld' => 'withheld',
            default => throw new RuntimeException('El snapshot contiene un tipo de impuesto no soportado.'),
        };
    }

    public function reconcileLocalDocumentCurrencyTotals(int$documentId):void
    {
        $document=$this->db->table('fiscal_documents')->select('status')->where(['id'=>$documentId,'deleted'=>0])->get(1)->getRow();
        if(!$document||!in_array((string)$document->status,['locked','ready','ready_to_stamp','stamping','stamping_error'],true))throw new RuntimeException('Sólo un documento fiscal no timbrado puede reconciliarse localmente.');
        if($this->db->table('fiscal_document_stamps')->where('fiscal_document_id',$documentId)->countAllResults())throw new RuntimeException('Un CFDI timbrado no puede recalcularse.');
        if($this->db->table('fiscal_stamp_attempts')->where('fiscal_document_id',$documentId)->countAllResults())throw new RuntimeException('Un documento con intento PAC no puede reconciliarse localmente.');
        $items=$this->db->table('fiscal_document_items')->where('fiscal_document_id',$documentId)->get()->getResultArray();
        if(!$items)throw new RuntimeException('El documento fiscal no contiene conceptos.');
        $taxes=$this->db->table('fiscal_document_item_taxes t')->select('t.fiscal_document_item_id,t.tax_type,t.amount')->join('fiscal_document_items i','i.id=t.fiscal_document_item_id')->where('i.fiscal_document_id',$documentId)->get()->getResultArray();
        $taxByItem=[];
        foreach($taxes as$tax){$itemId=(int)$tax['fiscal_document_item_id'];$key=$tax['tax_type']==='withheld'?'withheld':'transferred';$taxByItem[$itemId][$key]=FiscalDecimal::add($taxByItem[$itemId][$key]??'0',(string)$tax['amount']);}
        $lines=[];
        foreach($items as$item)$lines[]=['subtotal'=>(string)$item['gross_amount'],'discount'=>(string)$item['discount'],'transferred'=>$taxByItem[(int)$item['id']]['transferred']??'0','withheld'=>$taxByItem[(int)$item['id']]['withheld']??'0'];
        $currency=(new \App\Services\Fiscal\Cfdi40\CfdiCurrencyTotalsCalculator())->fromLines($lines);
        $this->db->transBegin();
        try{
            $this->db->table('fiscal_documents')->where('id',$documentId)->update(['subtotal'=>$currency['subtotal'],'discount'=>$currency['discount'],'transferred_tax_total'=>$currency['transferred'],'withheld_tax_total'=>$currency['withheld'],'total'=>$currency['total'],'updated_at'=>get_current_utc_time()]);
            $groups=$this->db->table('fiscal_document_item_taxes t')->select('t.tax_code,t.tax_type,t.factor_type,t.rate_or_quota,SUM(t.taxable_base) taxable_base,SUM(t.amount) amount')->join('fiscal_document_items i','i.id=t.fiscal_document_item_id')->where('i.fiscal_document_id',$documentId)->groupBy('t.tax_code,t.tax_type,t.factor_type,t.rate_or_quota')->get()->getResultArray();
            $this->db->table('fiscal_document_tax_totals')->where('fiscal_document_id',$documentId)->delete();
            foreach($groups as$tax)$this->db->table('fiscal_document_tax_totals')->insert(['fiscal_document_id'=>$documentId,'tax_code'=>$tax['tax_code'],'tax_type'=>$tax['tax_type'],'factor_type'=>$tax['factor_type'],'rate_or_quota'=>$tax['rate_or_quota'],'taxable_base'=>$tax['taxable_base'],'amount'=>$tax['amount'],'created_at'=>get_current_utc_time()]);
            if(!$this->db->transStatus())throw new RuntimeException('No fue posible reconciliar los totales locales.');
            $this->db->transCommit();
        }catch(Throwable$e){$this->db->transRollback();throw$e;}
    }
    private function currencyTotals(array$snapshot):array{$lines=[];foreach($snapshot['items']as$item){$transferred=$withheld='0.000000';foreach($item['taxes']as$tax){if($tax['tax_type']==='withholding')$withheld=FiscalDecimal::add($withheld,(string)$tax['tax_amount']);else$transferred=FiscalDecimal::add($transferred,(string)$tax['tax_amount']);}$lines[]=['subtotal'=>(string)$item['subtotal'],'discount'=>(string)$item['discount'],'transferred'=>$transferred,'withheld'=>$withheld];}return(new \App\Services\Fiscal\Cfdi40\CfdiCurrencyTotalsCalculator())->fromLines($lines);}

    private function audit(int $draftId, int $userId, string $event, array $summary): void
    {
        $this->db->table('fiscal_draft_audit')->insert([
            'fiscal_draft_id' => $draftId, 'user_id' => $userId, 'event' => $event,
            'summary_json' => json_encode($summary, JSON_UNESCAPED_SLASHES), 'created_at' => get_current_utc_time(),
        ]);
    }
}
