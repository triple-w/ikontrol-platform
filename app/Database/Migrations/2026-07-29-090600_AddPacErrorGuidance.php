<?php
declare(strict_types=1);
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;

final class AddPacErrorGuidance extends Migration
{
    public function up():void
    {
        if(!$this->db->tableExists('fiscal_stamp_attempts'))return;
        $fields=['recommended_action'=>['type'=>'VARCHAR','constraint'=>500,'null'=>true],'requires_reconciliation'=>['type'=>'TINYINT','constraint'=>1,'default'=>0]];
        foreach($fields as$name=>$definition)if(!$this->db->fieldExists($name,'fiscal_stamp_attempts'))$this->forge->addColumn('fiscal_stamp_attempts',[$name=>$definition]);
    }
    public function down():void
    {
        if(!$this->db->tableExists('fiscal_stamp_attempts'))return;
        foreach(['recommended_action','requires_reconciliation']as$field)if($this->db->fieldExists($field,'fiscal_stamp_attempts'))$this->forge->dropColumn('fiscal_stamp_attempts',$field);
    }
}
