<?php
declare(strict_types=1);
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;

final class CreateCommercialLifecycle extends Migration
{
    public function up(): void
    {
        $this->db->query('ALTER TABLE '.$this->db->protectIdentifiers($this->db->prefixTable('estimates'))." MODIFY `status` VARCHAR(20) NOT NULL DEFAULT 'draft'");
        $this->add('invoices', [
            'commercial_status'=>['type'=>'VARCHAR','constraint'=>20,'default'=>'open','after'=>'status'],
            'closed_at'=>['type'=>'DATETIME','null'=>true,'after'=>'commercial_status'],
            'closed_by'=>['type'=>'BIGINT','unsigned'=>true,'null'=>true,'after'=>'closed_at'],
            'closure_reason'=>['type'=>'VARCHAR','constraint'=>500,'null'=>true,'after'=>'closed_by'],
        ]);
        $this->add('estimates', [
            'converted_sale_id'=>['type'=>'BIGINT','unsigned'=>true,'null'=>true,'after'=>'status'],
            'converted_at'=>['type'=>'DATETIME','null'=>true,'after'=>'converted_sale_id'],
            'converted_by'=>['type'=>'BIGINT','unsigned'=>true,'null'=>true,'after'=>'converted_at'],
            'cancelled_at'=>['type'=>'DATETIME','null'=>true,'after'=>'converted_by'],
            'cancelled_by'=>['type'=>'BIGINT','unsigned'=>true,'null'=>true,'after'=>'cancelled_at'],
            'cancellation_reason'=>['type'=>'VARCHAR','constraint'=>500,'null'=>true,'after'=>'cancelled_by'],
        ]);
        if(!$this->db->tableExists('commercial_lifecycle_audit')){
            $this->forge->addField([
                'id'=>['type'=>'BIGINT','unsigned'=>true,'auto_increment'=>true],
                'entity_type'=>['type'=>'VARCHAR','constraint'=>20],
                'entity_id'=>['type'=>'BIGINT','unsigned'=>true],
                'event'=>['type'=>'VARCHAR','constraint'=>50],
                'old_status'=>['type'=>'VARCHAR','constraint'=>20,'null'=>true],
                'new_status'=>['type'=>'VARCHAR','constraint'=>20,'null'=>true],
                'reason'=>['type'=>'VARCHAR','constraint'=>500,'null'=>true],
                'user_id'=>['type'=>'BIGINT','unsigned'=>true],
                'created_at'=>['type'=>'DATETIME'],
            ]);$this->forge->addKey('id',true);$this->forge->addKey(['entity_type','entity_id']);$this->forge->createTable('commercial_lifecycle_audit');
        }
        $i=$this->db->prefixTable('invoices');$e=$this->db->prefixTable('estimates');
        $this->db->query("UPDATE $i SET commercial_status=CASE WHEN status='draft' THEN 'draft' WHEN status='cancelled' THEN 'cancelled' ELSE 'open' END WHERE commercial_status IS NULL OR commercial_status='' OR commercial_status='open'");
        $this->db->query("UPDATE $e e JOIN $i i ON i.estimate_id=e.id AND i.deleted=0 SET e.status='converted',e.converted_sale_id=i.id,e.converted_at=COALESCE(e.converted_at,NOW()),e.converted_by=COALESCE(e.converted_by,i.created_by) WHERE e.status='accepted'");
        $this->db->query("UPDATE $e SET status='rejected' WHERE status='declined'");
    }
    public function down(): void
    {
        if($this->db->tableExists('commercial_lifecycle_audit'))$this->forge->dropTable('commercial_lifecycle_audit');
        foreach(['invoices'=>['closure_reason','closed_by','closed_at','commercial_status'],'estimates'=>['cancellation_reason','cancelled_by','cancelled_at','converted_by','converted_at','converted_sale_id']]as$t=>$fs)foreach($fs as$f)if($this->db->fieldExists($f,$t))$this->forge->dropColumn($t,$f);
    }
    private function add(string $table,array $fields):void{$add=[];foreach($fields as$n=>$d)if(!$this->db->fieldExists($n,$table))$add[$n]=$d;if($add)$this->forge->addColumn($table,$add);}
}
