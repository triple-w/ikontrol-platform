<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Fiscal\FiscalDecimal;
use CodeIgniter\Database\BaseConnection;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Mutable draft-only external CFDI references. P11 owns snapshotting and XML. */
final class PaymentComplementExternalDocumentService
{
    private const DOCUMENTS = 'payment_complement_external_documents';
    private const TAXES = 'payment_complement_external_taxes';

    public function __construct(private BaseConnection $db)
    {
    }

    public function get(int $complementId, int $id): object
    {
        $row = $this->db->table(self::DOCUMENTS)
            ->where(['id' => $id, 'payment_complement_id' => $complementId, 'deleted' => 0])->get(1)->getRow();
        if (! $row) throw new RuntimeException('El CFDI externo no pertenece al complemento.');
        $row->taxes = $this->db->table(self::TAXES)->where('external_document_id', $id)->orderBy('id')->get()->getResultArray();
        return $row;
    }

    public function list(int $complementId): array
    {
        return $this->db->table(self::DOCUMENTS)->where(['payment_complement_id' => $complementId, 'deleted' => 0])->orderBy('id')->get()->getResult();
    }

    public function save(int $complementId, int $id, array $input, ?int $actor): int
    {
        $this->requireSchema();
        $this->db->transBegin();
        try {
            $context = $this->editableContext($complementId);
            if ($id > 0) $this->get($complementId, $id);
            $data = $this->validate($input, (string) $context->currency_code);
            $internal = $this->db->table('payment_complement_documents')
                ->where(['payment_complement_payment_id' => $context->payment_id, 'document_uuid' => $data['uuid'], 'deleted' => 0])->countAllResults();
            if ($internal) throw new InvalidArgumentException('El UUID ya está relacionado como documento interno.');
            $external = $this->db->table(self::DOCUMENTS)->where(['payment_complement_id' => $complementId, 'uuid' => $data['uuid'], 'deleted' => 0]);
            if ($id > 0) $external->where('id !=', $id);
            if ($external->countAllResults()) throw new InvalidArgumentException('El UUID ya está relacionado en este complemento.');
            $projected = $this->projectedPaymentAmount((int)$context->payment_id, $id, $data, (string)$context->currency_code);
            if (FiscalDecimal::micros($projected) > FiscalDecimal::micros((string)$context->payment_amount)) {
                throw new InvalidArgumentException('Los documentos relacionados exceden el pago administrativo real.');
            }
            $taxes = $data['taxes']; unset($data['taxes']);
            $now = gmdate('Y-m-d H:i:s');
            if ($id > 0) {
                $this->db->table(self::DOCUMENTS)->where('id', $id)->update($data + ['updated_at' => $now]);
                $this->db->table(self::TAXES)->where('external_document_id', $id)->delete();
            } else {
                if ($this->db->DBDriver !== 'MySQLi') $data['active_uuid'] = $data['uuid'];
                $this->db->table(self::DOCUMENTS)->insert($data + [
                    'payment_complement_id' => $complementId, 'payment_complement_payment_id' => $context->payment_id,
                    'created_by' => $actor, 'created_at' => $now, 'updated_at' => $now, 'deleted' => 0,
                ]);
                $id = (int) $this->db->insertID();
            }
            foreach ($taxes as $tax) $this->db->table(self::TAXES)->insert($tax + ['external_document_id' => $id]);
            $this->syncTotals($complementId, (int)$context->payment_id, $projected);
            if (! $this->db->transStatus()) throw new RuntimeException('No fue posible guardar el CFDI externo.');
            $this->db->transCommit();
            return $id;
        } catch (Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }
    }

    public function remove(int $complementId, int $id): void
    {
        $this->requireSchema();
        $this->db->transBegin();
        try {
            $this->editableContext($complementId);
            $this->get($complementId, $id);
            // active_uuid is generated from deleted, so a later same-UUID draft is valid.
            $change = ['deleted' => 1, 'updated_at' => gmdate('Y-m-d H:i:s')];
            if ($this->db->DBDriver !== 'MySQLi') $change['active_uuid'] = null;
            $this->db->table(self::DOCUMENTS)->where('id', $id)->update($change);
            $this->syncTotals($complementId, (int)$this->editableContext($complementId)->payment_id);
            if (! $this->db->transStatus()) throw new RuntimeException('No fue posible retirar el CFDI externo.');
            $this->db->transCommit();
        } catch (Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }
    }

    /** @return array{uuid:string,series:string,folio:string,currency_code:string,equivalence_dr:string,payment_method_code:string,tax_object_code:string,installment_number:int,previous_balance:string,paid_amount:string,remaining_balance:string,taxes:array<int,array<string,mixed>>} */
    public function validate(array $input, string $paymentCurrency): array
    {
        $uuid = strtoupper(trim((string) ($input['uuid'] ?? '')));
        if (! preg_match('/^[0-9A-F]{8}-(?:[0-9A-F]{4}-){3}[0-9A-F]{12}$/D', $uuid)) throw new InvalidArgumentException('UUID inválido.');
        $currency = strtoupper(trim((string) ($input['currency_code'] ?? '')));
        if (! preg_match('/^[A-Z]{3}$/D', $currency) || $currency === 'XXX'
            || ! $this->db->table('sat_currencies')->where(['code' => $currency, 'is_active' => 1])->countAllResults()) {
            throw new InvalidArgumentException('Seleccione una moneda SAT activa.');
        }
        $paymentCurrency = strtoupper(trim($paymentCurrency));
        if (! preg_match('/^[A-Z]{3}$/D', $paymentCurrency)) throw new RuntimeException('La moneda del pago fiscal es inválida.');
        $equivalence = $this->equivalence($input['equivalence_dr'] ?? null, $currency === $paymentCurrency);
        if (($input['payment_method_code'] ?? 'PPD') !== 'PPD') throw new InvalidArgumentException('El CFDI externo relacionado debe usar MetodoPago PPD.');
        $installment = $this->positiveInt($input['installment_number'] ?? null, 'NumParcialidad');
        $previous = $this->decimal($input['previous_balance'] ?? null, true, 'ImpSaldoAnt');
        $paid = $this->decimal($input['paid_amount'] ?? null, false, 'ImpPagado');
        if (FiscalDecimal::micros($paid) > FiscalDecimal::micros($previous)) throw new InvalidArgumentException('ImpPagado no puede exceder ImpSaldoAnt.');
        $remaining = FiscalDecimal::subtract($previous, $paid);
        if (isset($input['remaining_balance']) && $input['remaining_balance'] !== ''
            && $this->decimal($input['remaining_balance'], true, 'ImpSaldoInsoluto') !== $remaining) {
            throw new InvalidArgumentException('ImpSaldoInsoluto debe ser ImpSaldoAnt menos ImpPagado.');
        }
        $object = (string) ($input['tax_object_code'] ?? '');
        if (! in_array($object, ['01', '02', '03'], true)) throw new InvalidArgumentException('ObjetoImpDR debe ser 01, 02 o 03.');
        $taxes = $this->taxes($input['taxes'] ?? [], $object);
        $series = $this->text($input['series'] ?? '', 25, 'Serie');
        $folio = $this->text($input['folio'] ?? '', 40, 'Folio');
        return ['uuid' => $uuid, 'series' => $series, 'folio' => $folio, 'currency_code' => $currency,
            'equivalence_dr' => $equivalence, 'payment_method_code' => 'PPD', 'tax_object_code' => $object,
            'installment_number' => $installment, 'previous_balance' => $previous, 'paid_amount' => $paid,
            'remaining_balance' => $remaining, 'taxes' => $taxes];
    }

    private function editableContext(int $complementId): object
    {
        $suffix = $this->db->DBDriver === 'MySQLi' ? ' FOR UPDATE' : '';
        $row = $this->db->query('SELECT c.*,p.id payment_id,p.currency_code,ip.amount payment_amount FROM '
            . $this->db->escapeIdentifiers($this->db->prefixTable('payment_complements')) . ' c JOIN '
            . $this->db->escapeIdentifiers($this->db->prefixTable('payment_complement_payments'))
            . ' p ON p.payment_complement_id=c.id AND p.deleted=0 JOIN '
            . $this->db->escapeIdentifiers($this->db->prefixTable('invoice_payments'))
            . ' ip ON ip.id=p.source_invoice_payment_id WHERE c.id=? AND c.deleted=0' . $suffix, [$complementId])->getRow();
        if (! $row || ! in_array($row->status, ['draft', 'complete_draft'], true)) throw new RuntimeException('El complemento no es un borrador editable.');
        if ((int)($row->fiscal_document_id ?? 0) > 0 || ($this->db->tableExists('payment_complement_fiscal_snapshots') && $this->db->table('payment_complement_fiscal_snapshots')->where('payment_complement_id',$complementId)->countAllResults())) {
            throw new RuntimeException('El snapshot fiscal ya fue congelado; el documento externo es inmutable.');
        }
        return $row;
    }

    private function projectedPaymentAmount(int $paymentId, int $editingId, array $candidate, string $paymentCurrency): string
    {
        $internal = $this->db->table('payment_complement_documents')->selectSum('amount_paid','total')->where(['payment_complement_payment_id'=>$paymentId,'deleted'=>0])->get()->getRow();
        $total = FiscalDecimal::format(FiscalDecimal::micros((string)($internal->total ?? '0')));
        $rows = $this->db->table(self::DOCUMENTS)->where(['payment_complement_payment_id'=>$paymentId,'deleted'=>0]);
        if ($editingId > 0) $rows->where('id !=',$editingId);
        foreach ($rows->get()->getResult() as $row) $total = FiscalDecimal::add($total, $this->paymentValue((string)$row->paid_amount,(string)$row->currency_code,(string)$row->equivalence_dr,$paymentCurrency));
        return FiscalDecimal::add($total, $this->paymentValue($candidate['paid_amount'],$candidate['currency_code'],$candidate['equivalence_dr'],$paymentCurrency));
    }

    private function paymentValue(string $paid, string $documentCurrency, string $equivalence, string $paymentCurrency): string
    {
        return PaymentComplementAmountConverter::toPaymentCurrency($paid, $documentCurrency, $equivalence, $paymentCurrency);
    }

    private function syncTotals(int $complementId, int $paymentId, ?string $knownTotal = null): void
    {
        if ($knownTotal === null) {
            $internal=$this->db->table('payment_complement_documents')->selectSum('amount_paid','total')->where(['payment_complement_payment_id'=>$paymentId,'deleted'=>0])->get()->getRow();
            $total=FiscalDecimal::format(FiscalDecimal::micros((string)($internal->total??'0')));
            $payment=$this->db->table('payment_complement_payments')->where('id',$paymentId)->get(1)->getRow();
            foreach($this->db->table(self::DOCUMENTS)->where(['payment_complement_payment_id'=>$paymentId,'deleted'=>0])->get()->getResult()as$row)$total=FiscalDecimal::add($total,$this->paymentValue((string)$row->paid_amount,(string)$row->currency_code,(string)$row->equivalence_dr,(string)$payment->currency_code));
        } else $total=$knownTotal;
        $now=gmdate('Y-m-d H:i:s');
        $this->db->table('payment_complement_payments')->where('id',$paymentId)->update(['amount'=>$total,'updated_at'=>$now]);
        $this->db->table('payment_complements')->where('id',$complementId)->update(['status'=>FiscalDecimal::micros($total)>0?'complete_draft':'draft','updated_at'=>$now]);
    }

    private function taxes(mixed $input, string $object): array
    {
        if (! is_array($input)) throw new InvalidArgumentException('Los impuestos DR deben ser una lista.');
        if ($object === '02' && ! $input) throw new InvalidArgumentException('ObjetoImpDR 02 requiere impuestos DR.');
        if ($object !== '02' && $input) throw new InvalidArgumentException('ObjetoImpDR 01/03 no permite impuestos DR.');
        $result = []; $identity = [];
        foreach ($input as $tax) {
            if (! is_array($tax)) throw new InvalidArgumentException('Impuesto DR inválido.');
            $type = (string) ($tax['tax_type'] ?? ''); $code = (string) ($tax['tax_code'] ?? ''); $factor = (string) ($tax['factor_type'] ?? '');
            if (! in_array($type, ['transfer', 'withholding'], true) || ! in_array($code, ['001', '002', '003'], true)
                || ! in_array($factor, ['Tasa', 'Cuota', 'Exento'], true) || ($type === 'withholding' && $factor === 'Exento')) {
                throw new InvalidArgumentException('Tipo, impuesto o factor DR inválido.');
            }
            $base = $this->decimal($tax['base'] ?? null, false, 'BaseDR');
            $rate = $factor === 'Exento' ? null : $this->decimal($tax['rate_or_quota'] ?? null, true, 'TasaOCuotaDR');
            $amount = $factor === 'Exento' ? null : $this->decimal($tax['amount'] ?? null, true, 'ImporteDR');
            $key = implode('|', [$type, $code, $factor, $rate ?? '']);
            if (isset($identity[$key])) throw new InvalidArgumentException('Agrupe cada impuesto DR por tipo, impuesto, factor y tasa.');
            $identity[$key] = true;
            $result[] = ['tax_type' => $type, 'base' => $base, 'tax_code' => $code, 'factor_type' => $factor,
                'rate_or_quota' => $rate, 'amount' => $amount];
        }
        return $result;
    }

    private function equivalence(mixed $value, bool $sameCurrency): string
    {
        $raw = trim((string) ($value ?? ''));
        if ($sameCurrency) {
            if ($raw === '') return '1.0000000000';
            if (! preg_match('/^\d+(?:\.\d{1,10})?$/D', $raw) || ! $this->isOne($raw)) throw new InvalidArgumentException('EquivalenciaDR debe ser 1 cuando las monedas coinciden.');
            return '1.0000000000';
        }
        if (! preg_match('/^\d{1,18}(?:\.\d{1,10})?$/D', $raw) || preg_match('/^0+(?:\.0+)?$/D', $raw)) {
            throw new InvalidArgumentException('EquivalenciaDR es obligatoria y debe ser decimal positivo.');
        }
        return $raw;
    }

    private function isOne(string $value): bool
    {
        [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        return ltrim($integer, '0') === '1' && trim($fraction, '0') === '';
    }

    private function decimal(mixed $value, bool $allowZero, string $label): string
    {
        $value = trim((string) ($value ?? ''));
        if (! preg_match('/^\d{1,12}(?:\.\d{1,6})?$/D', $value)) throw new InvalidArgumentException($label . ' debe ser decimal con hasta seis posiciones.');
        $result = FiscalDecimal::format(FiscalDecimal::micros($value));
        if (FiscalDecimal::micros($result) < 0 || (! $allowZero && FiscalDecimal::micros($result) === 0)) {
            throw new InvalidArgumentException($label . ' no tiene un valor permitido.');
        }
        return $result;
    }

    private function positiveInt(mixed $value, string $label): int
    {
        if (! preg_match('/^[1-9][0-9]{0,8}$/D', (string) $value)) throw new InvalidArgumentException($label . ' debe ser entero mayor o igual a 1.');
        return (int) $value;
    }

    private function text(mixed $value, int $length, string $label): string
    {
        $value = trim((string) ($value ?? ''));
        if (mb_strlen($value) > $length || preg_match('/[|\x00-\x1F]/', $value)) throw new InvalidArgumentException($label . ' inválido.');
        return $value;
    }

    private function requireSchema(): void
    {
        foreach ([self::DOCUMENTS, self::TAXES, 'payment_complement_documents'] as $table) {
            if (! $this->db->tableExists($table)) throw new RuntimeException('Falta schema P04: ' . $table);
        }
        $fields = array_column($this->db->getFieldData(self::DOCUMENTS), null, 'name');
        foreach (['active_uuid', 'equivalence_dr', 'payment_complement_payment_id'] as $name) if (! isset($fields[$name])) throw new RuntimeException('Falta schema P04: ' . $name);
        $index = $this->db->getIndexData(self::DOCUMENTS)['uq_pc_external_active_uuid'] ?? null;
        if (! $index || $index->type !== 'UNIQUE' || $index->fields !== ['payment_complement_id', 'active_uuid']) throw new RuntimeException('Falta identidad única de CFDI externo.');
    }
}
