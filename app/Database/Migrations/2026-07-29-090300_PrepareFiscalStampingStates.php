<?php
declare(strict_types=1);
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
use RuntimeException;

final class PrepareFiscalStampingStates extends Migration
{
    public function up(): void
    {
        if(!$this->db->tableExists('fiscal_documents'))throw new RuntimeException('Cannot prepare stamping states: fiscal_documents missing.');
        // status is deliberately VARCHAR: adding the new controlled application
        // states requires no destructive ENUM or administrative schema alteration.
        if(!$this->db->fieldExists('stamp_updated_at','fiscal_documents')){
            $this->forge->addColumn('fiscal_documents',['stamp_updated_at'=>['type'=>'DATETIME','null'=>true,'after'=>'updated_at']]);
        }
    }
    public function down(): void
    {
        if($this->db->tableExists('fiscal_documents')&&$this->db->fieldExists('stamp_updated_at','fiscal_documents'))$this->forge->dropColumn('fiscal_documents','stamp_updated_at');
    }
}
