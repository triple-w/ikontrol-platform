<?php
declare(strict_types=1);
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
final class CreateFiscalDraftItemTaxes extends Migration
{
 public function up():void{
  if(!$this->db->tableExists('fiscal_draft_item_taxes')){$m=['type'=>'DECIMAL','constraint'=>'18,6'];$this->forge->addField([
   'id'=>['type'=>'BIGINT','unsigned'=>true,'auto_increment'=>true],'fiscal_draft_id'=>['type'=>'BIGINT','unsigned'=>true],
   'fiscal_draft_item_id'=>['type'=>'BIGINT','unsigned'=>true],'sale_id'=>['type'=>'BIGINT','unsigned'=>true,'null'=>true],
   'sale_item_id'=>['type'=>'BIGINT','unsigned'=>true,'null'=>true],'tax_type'=>['type'=>'VARCHAR','constraint'=>20],
   'tax_code'=>['type'=>'VARCHAR','constraint'=>10],'factor_type'=>['type'=>'VARCHAR','constraint'=>20],
   'rate_or_quota'=>$m+['null'=>true],'tax_base'=>$m,'tax_amount'=>$m+['default'=>'0.000000'],
   'is_exempt'=>['type'=>'TINYINT','constraint'=>1,'default'=>0],'calculation_order'=>['type'=>'INT','default'=>0],
   'source'=>['type'=>'VARCHAR','constraint'=>30,'default'=>'snapshot'],'created_at'=>['type'=>'DATETIME','null'=>true],'updated_at'=>['type'=>'DATETIME','null'=>true],
  ]);$this->forge->addKey('id',true);foreach(['fiscal_draft_id','fiscal_draft_item_id','sale_id','sale_item_id','tax_type','tax_code']as$k)$this->forge->addKey($k);$this->forge->addUniqueKey(['fiscal_draft_item_id','tax_type','tax_code','factor_type','rate_or_quota'],'uq_draft_item_tax');$this->forge->createTable('fiscal_draft_item_taxes');}
  $add=[];foreach(['snapshot_version'=>['type'=>'INT','default'=>1],'requires_snapshot_refresh'=>['type'=>'TINYINT','constraint'=>1,'default'=>0],'snapshot_completed_at'=>['type'=>'DATETIME','null'=>true]]as$n=>$d)if(!$this->db->fieldExists($n,'fiscal_drafts'))$add[$n]=$d;if($add)$this->forge->addColumn('fiscal_drafts',$add);
  $this->db->table('fiscal_drafts')->whereIn('status',['draft','ready'])->where('snapshot_version <',2)->update(['requires_snapshot_refresh'=>1]);
  $ready=$this->db->table('fiscal_drafts')->where(['status'=>'ready','requires_snapshot_refresh'=>1])->get()->getResultArray();foreach($ready as$r){$this->db->table('fiscal_drafts')->where('id',$r['id'])->update(['status'=>'draft']);$this->audit((int)$r['id'],'draft_snapshot_refresh_required');}
 }
 public function down():void{/* additive and evidence-preserving */}
 private function audit(int$id,string$event):void{if($this->db->tableExists('fiscal_draft_audit'))$this->db->table('fiscal_draft_audit')->insert(['fiscal_draft_id'=>$id,'sale_id'=>null,'user_id'=>0,'event'=>$event,'summary_json'=>json_encode(['snapshot_version'=>1]),'created_at'=>date('Y-m-d H:i:s')]);}
}
