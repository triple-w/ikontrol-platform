<?php
namespace App\Services;

use RuntimeException;
use Throwable;

final class FinancialTransferService
{
    public function __construct(private $db = null)
    {
        $this->db ??= db_connect();
    }

    public function create(int $source, int $destination, $amount, string $date, ?string $note, int $actor): int
    {
        if ($source === $destination) throw new RuntimeException('La cuenta origen y destino deben ser diferentes.');
        $amount = FinancialMoney::positive($amount);
        foreach ([$source, $destination] as $id) {
            if (!$this->db->table('financial_accounts')->where(['id' => $id, 'deleted' => 0, 'is_active' => 1, 'currency' => 'MXN'])->countAllResults()) {
                throw new RuntimeException('Ambas cuentas deben existir, estar activas y operar en MXN.');
            }
        }
        $this->db->transBegin();
        try {
            $this->db->table('financial_account_transfers')->insert(['source_account_id' => $source, 'destination_account_id' => $destination, 'amount' => $amount, 'transfer_date' => $date, 'note' => $note, 'status' => 'active', 'deleted' => 0, 'created_by' => $actor, 'created_at' => get_current_utc_time(), 'updated_at' => get_current_utc_time()]);
            $id = (int) $this->db->insertID();
            $movements = new FinancialAccountMovementService($this->db);
            $movements->sync('transfer_out', $id, $source, 'out', $amount, $date, $actor, $note);
            $movements->sync('transfer_in', $id, $destination, 'in', $amount, $date, $actor, $note);
            if (!$this->db->transStatus()) throw new RuntimeException('No fue posible registrar la transferencia.');
            $this->db->transCommit();
            return $id;
        } catch (Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }
    }

    public function cancel(int $id, int $actor, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') throw new RuntimeException('El motivo de cancelación es obligatorio.');
        $this->db->transBegin();
        try {
            $transfer = $this->db->query('SELECT * FROM '.$this->db->prefixTable('financial_account_transfers').' WHERE id=? FOR UPDATE', [$id])->getRow();
            if (!$transfer || $transfer->status !== 'active' || (int) $transfer->deleted) throw new RuntimeException('La transferencia no existe o ya fue cancelada.');
            $movements = new FinancialAccountMovementService($this->db);
            $movements->reverseSource('transfer_out', $id, $actor, $reason);
            $movements->reverseSource('transfer_in', $id, $actor, $reason);
            $this->db->table('financial_account_transfers')->where('id', $id)->update(['status' => 'cancelled', 'cancelled_at' => get_current_utc_time(), 'cancelled_by' => $actor, 'cancellation_reason' => $reason, 'updated_at' => get_current_utc_time()]);
            if (!$this->db->transStatus()) throw new RuntimeException('No fue posible cancelar la transferencia.');
            $this->db->transCommit();
        } catch (Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }
    }
}
