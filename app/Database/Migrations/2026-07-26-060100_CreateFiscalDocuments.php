<?php
declare(strict_types=1);
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
use RuntimeException;
class CreateFiscalDocuments extends Migration{
 public function up():void{
  if($this->db->tableExists('fiscal_documents'))return;
  foreach(['invoices','fiscal_profiles','fiscal_series']as$t)if(!$this->db->tableExists($t))throw new RuntimeException("Cannot create fiscal_documents: {$t} is missing.");
  $m=['type'=>'DECIMAL','constraint'=>'18,2','default'=>'0.00'];
  $this->forge->addField([
   'id'=>['type'=>'BIGINT','unsigned'=>true,'auto_increment'=>true],'invoice_id'=>['type'=>'INT','unsigned'=>true],
   'issuer_profile_id'=>['type'=>'INT','unsigned'=>true],'receiver_profile_id'=>['type'=>'INT','unsigned'=>true],
   'fiscal_series_id'=>['type'=>'INT','unsigned'=>true],'pricing_preparation_id'=>['type'=>'BIGINT','unsigned'=>true,'null'=>true],
   'document_type'=>['type'=>'VARCHAR','constraint'=>20,'default'=>'income'],'status'=>['type'=>'VARCHAR','constraint'=>30,'default'=>'draft'],
   'version'=>['type'=>'INT','unsigned'=>true,'default'=>1],'series'=>['type'=>'VARCHAR','constraint'=>25,'default'=>''],
   'folio'=>['type'=>'BIGINT','unsigned'=>true],'issue_date'=>['type'=>'DATETIME'],'expedition_postal_code'=>['type'=>'VARCHAR','constraint'=>5],
   'currency_code'=>['type'=>'CHAR','constraint'=>3],'exchange_rate'=>['type'=>'DECIMAL','constraint'=>'18,6','null'=>true],
   'payment_form_code'=>['type'=>'VARCHAR','constraint'=>3],'payment_method_code'=>['type'=>'VARCHAR','constraint'=>3],
   'cfdi_use_code'=>['type'=>'VARCHAR','constraint'=>5],'export_code'=>['type'=>'VARCHAR','constraint'=>3,'default'=>'01'],
   'subtotal'=>$m,'discount'=>$m,'transferred_tax_total'=>$m,'withheld_tax_total'=>$m,'total'=>$m,
   'administrative_total_reference'=>$m,'pricing_mode'=>['type'=>'VARCHAR','constraint'=>20],
   'source_snapshot_hash'=>['type'=>'CHAR','constraint'=>64],'created_by'=>['type'=>'INT','unsigned'=>true,'null'=>true],
   'created_at'=>['type'=>'DATETIME','null'=>true],'updated_at'=>['type'=>'DATETIME','null'=>true],
   'locked_at'=>['type'=>'DATETIME','null'=>true],'cancelled_at'=>['type'=>'DATETIME','null'=>true],
   'deleted'=>['type'=>'TINYINT','constraint'=>1,'default'=>0],
  ]);
  $this->forge->addKey('id',true);$this->forge->addUniqueKey(['issuer_profile_id','document_type','series','folio'],'uq_fiscal_document_folio');
  $this->forge->addKey(['invoice_id','status','deleted'],false,false,'idx_fiscal_document_invoice_status');
  $this->forge->addKey(['invoice_id','source_snapshot_hash'],false,false,'idx_fiscal_document_snapshot');$this->forge->createTable('fiscal_documents');
 }
 public function down():void{if($this->db->tableExists('fiscal_documents'))$this->forge->dropTable('fiscal_documents');}
}
