<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
final class EnablePaymentComplementStamping extends Migration
{
 public function up(){if(!$this->db->fieldExists('fiscal_document_id','payment_complements')){$this->forge->addColumn('payment_complements',['fiscal_document_id'=>['type'=>'BIGINT','unsigned'=>true,'null'=>true,'after'=>'issuer_profile_id'],'stamped_at'=>['type'=>'DATETIME','null'=>true,'after'=>'issue_date'],'last_stamp_error'=>['type'=>'TEXT','null'=>true,'after'=>'cancellation_reason']]);$table=$this->db->prefixTable('payment_complements');$documents=$this->db->prefixTable('fiscal_documents');$this->db->query("ALTER TABLE {$table} ADD UNIQUE KEY uq_payment_complement_fiscal_document (fiscal_document_id)");$this->db->query("ALTER TABLE {$table} ADD CONSTRAINT fk_payment_complement_fiscal_document FOREIGN KEY (fiscal_document_id) REFERENCES {$documents}(id)");}}
 public function down(){if($this->db->fieldExists('fiscal_document_id','payment_complements')){$table=$this->db->prefixTable('payment_complements');$this->db->query("ALTER TABLE {$table} DROP FOREIGN KEY fk_payment_complement_fiscal_document");$this->db->query("ALTER TABLE {$table} DROP INDEX uq_payment_complement_fiscal_document");$this->forge->dropColumn('payment_complements',['fiscal_document_id','stamped_at','last_stamp_error']);}}
}
