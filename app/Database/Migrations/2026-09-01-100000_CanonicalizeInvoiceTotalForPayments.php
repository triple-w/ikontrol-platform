<?php
declare(strict_types=1);
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
use RuntimeException;
final class CanonicalizeInvoiceTotalForPayments extends Migration
{
 public function up():void
 {
  if(!$this->db->tableExists('invoices')||!$this->db->fieldExists('invoice_total','invoices'))throw new RuntimeException('Falta invoices.invoice_total.');
  $column=$this->db->query("SELECT DATA_TYPE,NUMERIC_SCALE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME='invoice_total'",[$this->db->DBPrefix.'invoices'])->getRow();
  if($column&&$column->DATA_TYPE==='decimal'&&(int)$column->NUMERIC_SCALE===6)return;
  $unsafe=(int)$this->db->query('SELECT COUNT(*) total FROM '.$this->db->prefixTable('invoices').' WHERE invoice_total IS NOT NULL AND ABS(invoice_total-ROUND(invoice_total,6))>0.0000005')->getRow()->total;
  if($unsafe)throw new RuntimeException('Existen ventas con precision economica mayor a seis decimales; se requiere conciliacion antes de migrar.');
  $this->forge->modifyColumn('invoices',['invoice_total'=>['name'=>'invoice_total','type'=>'DECIMAL','constraint'=>'18,6','null'=>false]]);
 }
 public function down():void{}
}
