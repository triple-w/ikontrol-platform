<?php
declare(strict_types=1);
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
final class AddFiscalOverrideToCommercialItems extends Migration
{
    public function up(){foreach(['proposal_items','estimate_items']as$table)if(!$this->db->fieldExists('fiscal_override_json',$table))$this->forge->addColumn($table,['fiscal_override_json'=>['type'=>'LONGTEXT','null'=>true,'after'=>'item_id']]);}
    public function down(){foreach(['proposal_items','estimate_items']as$table)if($this->db->fieldExists('fiscal_override_json',$table))$this->forge->dropColumn($table,'fiscal_override_json');}
}
