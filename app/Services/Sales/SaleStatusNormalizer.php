<?php

declare(strict_types=1);

namespace App\Services\Sales;

use CodeIgniter\Database\BaseConnection;
use RuntimeException;
use Throwable;

/** Repairs only the invalid historical combination closed + draft. */
final class SaleStatusNormalizer
{
    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= db_connect();
    }

    public function normalize(bool $dryRun = true): array
    {
        if (! $this->db->tableExists('invoices') || ! $this->db->tableExists('payment_allocations')) {
            throw new RuntimeException('Faltan tablas canónicas de ventas o aplicaciones de pago.');
        }
        $ids = array_map('intval', array_column($this->db->table('invoices')
            ->select('id')->where(['commercial_status'=>'closed','status'=>'draft','deleted'=>0])
            ->orderBy('id')->get()->getResultArray(), 'id'));
        $status = new SalePaymentStatusService($this->db);
        $rows = [];
        $updated = 0;

        if (! $dryRun) $this->db->transBegin();
        try {
            foreach ($ids as $id) {
                $result = $status->calculate($id);
                $rows[] = ['invoice_id'=>$id,'from'=>'draft','to'=>$result['status'],'total'=>$result['total'],'paid'=>$result['paid'],'credited'=>$result['credited'],'balance'=>$result['balance']];
                if ($dryRun) continue;
                $this->db->table('invoices')->where(['id'=>$id,'commercial_status'=>'closed','status'=>'draft','deleted'=>0])
                    ->update(['status'=>$result['status']]);
                $updated += $this->db->affectedRows();
            }
            if (! $dryRun) {
                if (! $this->db->transStatus()) throw new RuntimeException('No fue posible normalizar los estados de ventas.');
                $this->db->transCommit();
            }
        } catch (Throwable $error) {
            if (! $dryRun) $this->db->transRollback();
            throw $error;
        }

        return ['dry_run'=>$dryRun,'scanned'=>count($ids),'candidates'=>count($rows),'updated'=>$updated,'rows'=>$rows];
    }
}
