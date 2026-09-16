<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
class CreatePaymentAllocations extends Migration
{
    public function up(){
        if(!$this->db->tableExists('payment_allocations')){
            $this->forge->addField(['id'=>['type'=>'INT','unsigned'=>true,'auto_increment'=>true],'invoice_payment_id'=>['type'=>'INT','unsigned'=>true],'fiscal_document_id'=>['type'=>'INT','unsigned'=>true],'amount_applied'=>['type'=>'DECIMAL','constraint'=>'18,6'],'allocation_date'=>['type'=>'DATE'],'status'=>['type'=>'VARCHAR','constraint'=>20,'default'=>'active'],'created_by'=>['type'=>'INT','null'=>true],'created_at'=>['type'=>'DATETIME','null'=>true],'updated_at'=>['type'=>'DATETIME','null'=>true],'deleted'=>['type'=>'TINYINT','constraint'=>1,'default'=>0]]);
            $this->forge->addKey('id',true);$this->forge->addKey(['invoice_payment_id','fiscal_document_id']);$this->forge->createTable('payment_allocations');
        }
    }
    public function down(){}
}
