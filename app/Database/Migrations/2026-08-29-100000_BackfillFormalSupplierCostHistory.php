<?php
declare(strict_types=1);

namespace App\Database\Migrations;

use App\Services\SupplierCostHistoryService;
use CodeIgniter\Database\Migration;

final class BackfillFormalSupplierCostHistory extends Migration
{
    public function up()
    {
        helper('date_time');
        if (!$this->db->tableExists('product_supplier_cost_history')) {
            return;
        }
        $service = new SupplierCostHistoryService($this->db);
        $rows = $this->db->table('proposals')
            ->select('id,status')
            ->where('deleted', 0)
            ->whereIn('status', ['sent', 'accepted', 'declined'])
            ->orderBy('id')
            ->get()->getResult();
        foreach ($rows as $proposal) {
            $service->snapshotProposal((int) $proposal->id, (string) $proposal->status, 0);
        }
    }

    public function down()
    {
        // Historical commercial evidence is intentionally preserved on rollback.
    }
}
