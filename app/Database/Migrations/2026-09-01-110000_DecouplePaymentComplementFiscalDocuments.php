<?php
declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class DecouplePaymentComplementFiscalDocuments extends Migration
{
    public function up()
    {
        if(!$this->db->tableExists('payment_complement_documents')||!$this->db->fieldExists('payment_allocation_id','payment_complement_documents'))return;
        $table=$this->db->prefixTable('payment_complement_documents');
        $column=$this->db->query("SHOW COLUMNS FROM {$table} WHERE Field='payment_allocation_id'")->getRow();
        if($column&&strtoupper((string)$column->Null)!=='YES')$this->db->query("ALTER TABLE {$table} MODIFY payment_allocation_id INT UNSIGNED NULL");
    }

    public function down()
    {
        // Fiscal selections without an administrative allocation are valid data.
        // Restoring NOT NULL would be destructive, so rollback intentionally preserves them.
    }
}
