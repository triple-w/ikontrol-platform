<?php

declare(strict_types=1);
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
use RuntimeException;

class ExtendAdministrativeTaxesForFiscalPreparation extends Migration
{
    private array $fields = [
        'sat_tax_code_id' => ['type'=>'INT','unsigned'=>true,'null'=>true],
        'fiscal_tax_type' => ['type'=>'VARCHAR','constraint'=>20,'null'=>true],
        'factor_type_id' => ['type'=>'INT','unsigned'=>true,'null'=>true],
        'xml_rate' => ['type'=>'DECIMAL','constraint'=>'18,6','null'=>true],
        'xml_quota' => ['type'=>'DECIMAL','constraint'=>'18,6','null'=>true],
        'is_fiscal_ready' => ['type'=>'TINYINT','constraint'=>1,'default'=>0],
        'use_for_administrative' => ['type'=>'TINYINT','constraint'=>1,'default'=>1],
        'use_for_fiscal' => ['type'=>'TINYINT','constraint'=>1,'default'=>0],
        'fiscal_notes' => ['type'=>'TEXT','null'=>true],
        'updated_at' => ['type'=>'DATETIME','null'=>true],
    ];
    public function up(): void
    {
        if (! $this->db->tableExists('taxes')) throw new RuntimeException('Cannot extend taxes: RISE taxes table is missing.');
        $add=[]; foreach($this->fields as $name=>$definition) if(! $this->db->fieldExists($name,'taxes')) $add[$name]=$definition;
        if($add) $this->forge->addColumn('taxes',$add);
        $this->db->table('taxes')->where('use_for_administrative', null)->update(['use_for_administrative'=>1]);
    }
    public function down(): void
    {
        // Safe rollback is intentionally additive-only: existing fiscal preparation is retained.
    }
}
