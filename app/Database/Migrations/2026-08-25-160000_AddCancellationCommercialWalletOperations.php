<?php
declare(strict_types=1);
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
final class AddCancellationCommercialWalletOperations extends Migration
{
    public function up(){if(!$this->db->tableExists('fiscal_stamp_movements'))return;$table=$this->db->protectIdentifiers($this->db->prefixTable('fiscal_stamp_movements'));$this->db->query("ALTER TABLE {$table} DROP CONSTRAINT chk_stamp_movement_type");$types="'allocation','adjustment_credit','adjustment_debit','document_reservation','document_consumption','document_release','reconciliation_consumption','reconciliation_release','cancellation_request','cancellation_status_query'";$this->db->query("ALTER TABLE {$table} ADD CONSTRAINT chk_stamp_movement_type CHECK (movement_type IN ({$types}))");}
    public function down(){if(!$this->db->tableExists('fiscal_stamp_movements'))return;$table=$this->db->protectIdentifiers($this->db->prefixTable('fiscal_stamp_movements'));try{$this->db->query("ALTER TABLE {$table} DROP CHECK chk_stamp_movement_type");}catch(\Throwable){}}
}
