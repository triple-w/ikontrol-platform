<?php
declare(strict_types=1);
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
use RuntimeException;

final class AddFiscalGlobalInformation extends Migration
{
    public function up():void
    {
        foreach(['fiscal_drafts','fiscal_documents']as$table){
            if(!$this->db->tableExists($table))throw new RuntimeException('Cannot add global CFDI information: '.$table.' is missing.');
            $fields=[];
            if(!$this->db->fieldExists('global_periodicity',$table))$fields['global_periodicity']=['type'=>'CHAR','constraint'=>2,'null'=>true];
            if(!$this->db->fieldExists('global_months',$table))$fields['global_months']=['type'=>'CHAR','constraint'=>2,'null'=>true];
            if(!$this->db->fieldExists('global_year',$table))$fields['global_year']=['type'=>'SMALLINT','unsigned'=>true,'null'=>true];
            if($fields)$this->forge->addColumn($table,$fields);
        }
    }
    public function down():void{}
}
