<?php

namespace App\Services;

use RuntimeException;
use Throwable;

final class PaymentAllocationService
{
    public function __construct(private $db) {}

    public function payment(int $id): ?object
    {
        return $this->db->table('invoice_payments p')->select('p.*,c.company_name client_name,pm.title payment_method_title,fa.name financial_account_name')->join('clients c', 'c.id=p.client_id')->join('payment_methods pm', 'pm.id=p.payment_method_id', 'left')->join('financial_accounts fa', 'fa.id=p.destination_financial_account_id', 'left')->where('p.id', $id)->get(1)->getRow();
    }

    public function sale(int $id): ?object { return $this->db->table('invoices')->where('id', $id)->get(1)->getRow(); }
    public function paymentApplied(int $id): string { return $this->sum('invoice_payment_id', $id); }
    public function salePaid(int $id): string { return $this->sum('invoice_id', $id); }

    public function paymentAvailable(int $id): string
    {
        $payment = $this->payment($id);
        if (!$payment) throw new RuntimeException('El pago no existe.');
        if ($payment->status !== 'active' || (int) $payment->deleted) return '0.000000';
        return FinancialMoney::subtract(FinancialMoney::fromDatabase($payment->amount), $this->paymentApplied($id));
    }

    public function saleOutstanding(int $id): string
    {
        $sale = $this->sale($id);
        if (!$sale) throw new RuntimeException('La venta no existe.');
        $outstanding = FinancialMoney::subtract(
            FinancialMoney::subtract(FinancialMoney::fromDatabase($sale->invoice_total), $this->salePaid($id)),
            (new \App\Services\Fiscal\CreditNoteBalanceService($this->db))->creditedSaleAmount($id)
        );
        return bccomp($outstanding, '0.000000', 6) > 0 ? $outstanding : '0.000000';
    }

    public function create(int $paymentId, int $saleId, $amount, ?int $actor, ?string $date = null): int
    {
        $this->db->transBegin();
        try {
            $id = $this->createWithinTransaction($paymentId, $saleId, $amount, $actor, $date);
            if (!$this->db->transStatus()) throw new RuntimeException('No fue posible guardar la aplicación.');
            $this->db->transCommit();
            return $id;
        } catch (Throwable $e) { $this->db->transRollback(); throw $e; }
    }

    public function applyMany(int $paymentId, array $applications, ?int $actor, ?string $date = null): array
    {
        $this->db->transBegin();
        try {
            $ids = [];
            foreach ($applications as $application) {
                $saleId = (int) ($application['invoice_id'] ?? 0);
                $amount = $application['amount_applied'] ?? '0';
                if ($saleId && bccomp(FinancialMoney::normalize($amount), '0', 6) > 0) $ids[] = $this->createWithinTransaction($paymentId, $saleId, $amount, $actor, $date);
            }
            if (!$ids) throw new RuntimeException('Capture al menos una aplicación mayor que cero.');
            if (!$this->db->transStatus()) throw new RuntimeException('No fue posible guardar las aplicaciones.');
            $this->db->transCommit();
            return $ids;
        } catch (Throwable $e) { $this->db->transRollback(); throw $e; }
    }

    public function createWithinTransaction(int $paymentId, int $saleId, $amount, ?int $actor, ?string $date = null): int
    {
        $amount = FinancialMoney::positive($amount);
        $payments = $this->db->prefixTable('invoice_payments');
        $sales = $this->db->prefixTable('invoices');
        $allocations = $this->db->prefixTable('payment_allocations');
        $payment = $this->db->query("SELECT * FROM {$payments} WHERE id=? FOR UPDATE", [$paymentId])->getRow();
        $sale = $this->db->query("SELECT * FROM {$sales} WHERE id=? FOR UPDATE", [$saleId])->getRow();
        if (!$payment || $payment->status !== 'active' || (int) $payment->deleted) throw new RuntimeException('El pago no está activo.');
        if (!$sale || (int) $sale->deleted || $sale->type !== 'invoice' || in_array($sale->status, ['cancelled', 'credited'], true) || $sale->commercial_status === 'cancelled') throw new RuntimeException('La venta no está vigente.');
        if ((int) $payment->client_id !== (int) $sale->client_id) throw new RuntimeException('El pago y la venta pertenecen a clientes distintos.');
        $existing = $this->db->query("SELECT * FROM {$allocations} WHERE invoice_payment_id=? AND invoice_id=? FOR UPDATE", [$paymentId, $saleId])->getRow();
        $paymentApplied = $this->lockedSum('invoice_payment_id', $paymentId, $existing?->id);
        $salePaid = $this->lockedSum('invoice_id', $saleId, $existing?->id);
        $saleCredits = (new \App\Services\Fiscal\CreditNoteBalanceService($this->db))->creditedSaleAmount($saleId);
        $available = FinancialMoney::subtract(FinancialMoney::fromDatabase($payment->amount), $paymentApplied);
        $outstanding = FinancialMoney::subtract(FinancialMoney::subtract(FinancialMoney::fromDatabase($sale->invoice_total), $salePaid), FinancialMoney::fromDatabase($saleCredits));
        if (bccomp($amount, $available, 6) > 0) throw new RuntimeException('El importe excede el saldo disponible del pago.');
        if (bccomp($amount, $outstanding, 6) > 0) throw new RuntimeException('El importe excede el saldo pendiente de la venta.');
        $data = ['amount_applied' => $amount, 'allocation_date' => $date ?: gmdate('Y-m-d'), 'status' => 'active', 'deleted' => 0, 'deactivated_at' => null, 'deactivated_by' => null, 'deactivation_reason' => null, 'updated_at' => get_current_utc_time()];
        if ($existing) { $this->db->table('payment_allocations')->where('id', $existing->id)->update($data); return (int) $existing->id; }
        $data += ['invoice_payment_id' => $paymentId, 'invoice_id' => $saleId, 'created_by' => $actor, 'created_at' => get_current_utc_time()];
        $this->db->table('payment_allocations')->insert($data);
        return (int) $this->db->insertID();
    }

    public function deactivate(int $id, int $actor = 0, string $reason = 'Retirada administrativa'): void
    {
        $this->db->transBegin();
        try {
            $row = $this->db->query('SELECT * FROM '.$this->db->prefixTable('payment_allocations').' WHERE id=? FOR UPDATE', [$id])->getRow();
            if (!$row || $row->status !== 'active' || (int) $row->deleted) throw new RuntimeException('La aplicación no existe o ya fue retirada.');
            $this->db->table('payment_allocations')->where('id', $id)->update(['deleted' => 1, 'status' => 'inactive', 'deactivated_at' => get_current_utc_time(), 'deactivated_by' => $actor ?: null, 'deactivation_reason' => $reason, 'updated_at' => get_current_utc_time()]);
            $this->db->transCommit();
        } catch (Throwable $e) { $this->db->transRollback(); throw $e; }
    }

    public function restoreWithinTransaction(int $id, $expectedAmount, $previousAmount, ?int $actor, string $reason): void
    {
        $expectedAmount = FinancialMoney::normalize($expectedAmount);
        $previousAmount = FinancialMoney::normalize($previousAmount);
        $row = $this->db->query('SELECT * FROM '.$this->db->prefixTable('payment_allocations').' WHERE id=? FOR UPDATE', [$id])->getRow();
        if (!$row || $row->status !== 'active' || (int) $row->deleted) throw new RuntimeException('La aplicación automática ya no está activa.');
        if (bccomp(FinancialMoney::normalize($row->amount_applied), $expectedAmount, 6) !== 0) throw new RuntimeException('La aplicación administrativa cambió después de crear el borrador; no puede restaurarse automáticamente.');
        $now = get_current_utc_time();
        if (bccomp($previousAmount, '0.000000', 6) === 0) {
            $this->db->table('payment_allocations')->where('id', $id)->update(['deleted' => 1, 'status' => 'inactive', 'deactivated_at' => $now, 'deactivated_by' => $actor, 'deactivation_reason' => $reason, 'updated_at' => $now]);
            return;
        }
        $this->db->table('payment_allocations')->where('id', $id)->update(['amount_applied' => $previousAmount, 'updated_at' => $now]);
    }

    public function candidates(int $clientId): array
    {
        return $this->db->table('invoice_payments p')->select('p.id payment_id,p.payment_date,p.amount,p.payment_method_id,p.destination_financial_account_id,COALESCE(SUM(a.amount_applied),0) total_applied,(p.amount-COALESCE(SUM(a.amount_applied),0)) available_amount')->join('payment_allocations a', "a.invoice_payment_id=p.id AND a.deleted=0 AND a.status='active'", 'left')->where(['p.client_id' => $clientId, 'p.deleted' => 0, 'p.status' => 'active'])->groupBy('p.id')->having('available_amount >', 0)->get()->getResult();
    }

    public function salesForPayment(int $paymentId): array
    {
        $payment = $this->payment($paymentId);
        if (!$payment) return [];
        $rows=$this->db->table('invoices i')->select("i.id,i.display_id,i.bill_date,i.invoice_total total,COALESCE(SUM(pa.amount_applied),0) paid_amount,(i.invoice_total-COALESCE(SUM(pa.amount_applied),0)) outstanding", false)->join('payment_allocations pa', "pa.invoice_id=i.id AND pa.deleted=0 AND pa.status='active'", 'left')->where(['i.client_id' => $payment->client_id, 'i.deleted' => 0, 'i.type' => 'invoice'])->whereNotIn('i.status', ['cancelled', 'credited'])->where('i.commercial_status !=', 'cancelled')->groupBy('i.id')->having('outstanding >', 0)->orderBy('i.id', 'DESC')->get()->getResult();foreach($rows as$row)$row->outstanding=FinancialMoney::subtract(FinancialMoney::fromDatabase($row->outstanding),(new \App\Services\Fiscal\CreditNoteBalanceService($this->db))->creditedSaleAmount((int)$row->id));return array_values(array_filter($rows,fn($row)=>bccomp((string)$row->outstanding,'0',6)>0));
    }

    public function allocationsForPayment(int $paymentId): array
    {
        $pa = $this->db->prefixTable('payment_allocations');
        $rows=$this->db->table('payment_allocations pa')->select("pa.*,i.display_id,i.bill_date,i.invoice_total total,(i.invoice_total-COALESCE((SELECT SUM(x.amount_applied) FROM {$pa} x WHERE x.invoice_id=i.id AND x.deleted=0 AND x.status='active'),0)) outstanding", false)->join('invoices i', 'i.id=pa.invoice_id')->where(['pa.invoice_payment_id' => $paymentId, 'pa.deleted' => 0, 'pa.status' => 'active'])->get()->getResult();foreach($rows as$row)$row->outstanding=FinancialMoney::subtract(FinancialMoney::fromDatabase($row->outstanding),(new \App\Services\Fiscal\CreditNoteBalanceService($this->db))->creditedSaleAmount((int)$row->invoice_id));return$rows;
    }

    public function paymentsForSale(int $saleId): array
    {
        return $this->db->table('payment_allocations pa')->select('pa.amount_applied,pa.status,p.id payment_id,p.amount payment_amount,p.payment_date,p.payment_method_id,p.destination_financial_account_id,pm.title payment_method_title')->join('invoice_payments p', 'p.id=pa.invoice_payment_id')->join('payment_methods pm', 'pm.id=p.payment_method_id', 'left')->where(['pa.invoice_id' => $saleId, 'pa.deleted' => 0, 'pa.status' => 'active', 'p.deleted' => 0, 'p.status' => 'active'])->get()->getResult();
    }

    private function sum(string $field, int $id): string
    {
        $row = $this->db->table('payment_allocations')->selectSum('amount_applied', 'total')->where([$field => $id, 'deleted' => 0, 'status' => 'active'])->get()->getRow();
        return FinancialMoney::fromDatabase($row->total ?? '0');
    }

    private function lockedSum(string $field, int $id, ?int $exclude): string
    {
        $sql = 'SELECT COALESCE(SUM(amount_applied),0) total FROM '.$this->db->prefixTable('payment_allocations').' WHERE '.$field.'=? AND deleted=0 AND status=\'active\'';
        $args = [$id];
        if ($exclude) { $sql .= ' AND id<>?'; $args[] = $exclude; }
        return FinancialMoney::fromDatabase($this->db->query($sql, $args)->getRow()->total ?? '0');
    }
}
