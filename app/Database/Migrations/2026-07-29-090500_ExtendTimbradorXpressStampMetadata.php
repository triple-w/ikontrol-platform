<?php
declare(strict_types=1);
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;

final class ExtendTimbradorXpressStampMetadata extends Migration
{
    public function up():void
    {
        if(!$this->db->tableExists('fiscal_document_stamps'))return;
        $fields=[
            'provider_original_chain'=>['type'=>'MEDIUMTEXT','null'=>true],
            'sat_original_chain'=>['type'=>'MEDIUMTEXT','null'=>true],
            'qr_data'=>['type'=>'TEXT','null'=>true],
            'auxiliary_warnings'=>['type'=>'TEXT','null'=>true],
        ];
        foreach($fields as$name=>$definition)if(!$this->db->fieldExists($name,'fiscal_document_stamps'))$this->forge->addColumn('fiscal_document_stamps',[$name=>$definition]);
    }
    public function down():void
    {
        if(!$this->db->tableExists('fiscal_document_stamps'))return;
        foreach(['provider_original_chain','sat_original_chain','qr_data','auxiliary_warnings']as$field)if($this->db->fieldExists($field,'fiscal_document_stamps'))$this->forge->dropColumn('fiscal_document_stamps',$field);
    }
}
