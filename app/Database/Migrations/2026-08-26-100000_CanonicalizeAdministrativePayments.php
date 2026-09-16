<?php
namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

final class CanonicalizeAdministrativePayments extends Migration
{
    public function up(): void
    {
        $p=$this->db->DBPrefix;
        if(!$this->db->fieldExists('client_id','invoice_payments'))$this->forge->addColumn('invoice_payments',['client_id'=>['type'=>'INT','null'=>true,'after'=>'id']]);
        if(!$this->db->fieldExists('reference','invoice_payments'))$this->forge->addColumn('invoice_payments',['reference'=>['type'=>'VARCHAR','constraint'=>150,'null'=>true,'after'=>'note']]);
        foreach(['deactivated_at'=>['type'=>'DATETIME','null'=>true],'deactivated_by'=>['type'=>'INT','null'=>true],'deactivation_reason'=>['type'=>'VARCHAR','constraint'=>500,'null'=>true]]as$n=>$d)if(!$this->db->fieldExists($n,'payment_allocations'))$this->forge->addColumn('payment_allocations',[$n=>$d]);
        $this->db->query("UPDATE {$p}invoice_payments p JOIN {$p}invoices i ON i.id=p.invoice_id SET p.client_id=i.client_id WHERE p.client_id IS NULL");
        if((int)$this->db->query("SELECT COUNT(*) c FROM {$p}invoice_payments WHERE client_id IS NULL OR client_id=0")->getRow()->c)throw new RuntimeException('Existen pagos sin cliente que deben clasificarse antes de continuar.');
        if((int)$this->db->query("SELECT COUNT(*) c FROM (SELECT invoice_payment_id,fiscal_document_id,COUNT(*) n FROM {$p}payment_allocations GROUP BY invoice_payment_id,fiscal_document_id HAVING n>1) x")->getRow()->c)throw new RuntimeException('Existen aplicaciones duplicadas que deben conciliarse antes de continuar.');
        $this->forge->modifyColumn('invoice_payments',['client_id'=>['name'=>'client_id','type'=>'INT','null'=>false],'invoice_id'=>['name'=>'invoice_id','type'=>'INT','null'=>true]]);
        $this->forge->modifyColumn('payment_allocations',['invoice_payment_id'=>['name'=>'invoice_payment_id','type'=>'INT','null'=>false],'fiscal_document_id'=>['name'=>'fiscal_document_id','type'=>'BIGINT','unsigned'=>true,'null'=>false],'amount_applied'=>['name'=>'amount_applied','type'=>'DECIMAL','constraint'=>'18,6','null'=>false]]);
        foreach([
            "ALTER TABLE {$p}invoice_payments ADD KEY idx_payment_client_status_date (client_id,status,payment_date)",
            "ALTER TABLE {$p}invoice_payments ADD CONSTRAINT fk_payment_client FOREIGN KEY(client_id) REFERENCES {$p}clients(id) ON DELETE RESTRICT",
            "ALTER TABLE {$p}payment_allocations ADD UNIQUE KEY uq_payment_document (invoice_payment_id,fiscal_document_id)",
            "ALTER TABLE {$p}payment_allocations ADD KEY idx_allocation_document_status (fiscal_document_id,status,deleted)",
            "ALTER TABLE {$p}payment_allocations ADD CONSTRAINT fk_allocation_payment FOREIGN KEY(invoice_payment_id) REFERENCES {$p}invoice_payments(id) ON DELETE RESTRICT",
            "ALTER TABLE {$p}payment_allocations ADD CONSTRAINT fk_allocation_document FOREIGN KEY(fiscal_document_id) REFERENCES {$p}fiscal_documents(id) ON DELETE RESTRICT",
            "ALTER TABLE {$p}payment_allocations ADD CONSTRAINT chk_allocation_amount CHECK (amount_applied>0)",
            "ALTER TABLE {$p}payment_allocations ADD CONSTRAINT chk_allocation_status CHECK (status IN ('active','inactive'))"
        ]as$sql)$this->db->query($sql);
    }
    public function down(): void {}
}
