<?php
declare(strict_types=1);
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;

final class CompletePaymentComplementFiscalData extends Migration
{
    public function up():void
    {
        $this->add('financial_accounts','bank_name',['type'=>'VARCHAR','constraint'=>150,'null'=>true,'after'=>'description']);
        $this->add('financial_accounts','bank_rfc',['type'=>'VARCHAR','constraint'=>13,'null'=>true,'after'=>'bank_name']);
        $this->add('financial_accounts','account_number',['type'=>'VARCHAR','constraint'=>50,'null'=>true,'after'=>'bank_rfc']);
        $this->add('financial_accounts','clabe',['type'=>'VARCHAR','constraint'=>18,'null'=>true,'after'=>'account_number']);
        $this->add('payment_complement_payments','payment_chain_type',['type'=>'VARCHAR','constraint'=>2,'null'=>true,'after'=>'beneficiary_account']);
        $this->add('payment_complement_payments','payment_certificate',['type'=>'TEXT','null'=>true,'after'=>'payment_chain_type']);
        $this->add('payment_complement_payments','payment_chain',['type'=>'TEXT','null'=>true,'after'=>'payment_certificate']);
        $this->add('payment_complement_payments','payment_signature',['type'=>'TEXT','null'=>true,'after'=>'payment_chain']);
    }
    public function down():void{}
    private function add(string$table,string$field,array$definition):void{if(!$this->db->fieldExists($field,$table))$this->forge->addColumn($table,[$field=>$definition]);}
}
