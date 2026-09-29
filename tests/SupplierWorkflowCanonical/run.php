<?php
declare(strict_types=1);

foreach (['fiscal.enabled'=>'false','fiscal.stampingEnabled'=>'false','fiscal.allowRealPac'=>'false'] as $key=>$value) { putenv("$key=$value"); $_ENV[$key]=$_SERVER[$key]=$value; }
require dirname(__DIR__) . '/bootstrap.php';
require_once APPPATH . 'ThirdParty/PHP-Hooks/php-hooks.php';
helper(['plugin','general','date_time']);

use App\Services\SupplierComparisonService;
use App\Services\SupplierCostHistoryService;
use Config\Database;

$passed=0; $assert=static function(bool $ok,string $label)use(&$passed):void{if(!$ok)throw new RuntimeException($label);$passed++;echo "[PASS] $label\n";};
try {
    $db=Database::connect(['DSN'=>'','hostname'=>'','username'=>'','password'=>'','database'=>':memory:','DBDriver'=>'SQLite3','DBPrefix'=>'p07_','pConnect'=>false,'DBDebug'=>true,'foreignKeys'=>true],false);$db->initialize();
    $forge=Database::forge($db);$make=static function(string $table,array $fields)use($forge):void{$forge->addField($fields);$forge->addKey('id',true);$forge->createTable($table);};
    $id=['type'=>'INT','auto_increment'=>true];$int=['type'=>'INT','default'=>0];$nint=['type'=>'INT','null'=>true];$dec=['type'=>'DECIMAL','constraint'=>'18,6','null'=>true];
    $make('items',['id'=>$id,'title'=>['type'=>'VARCHAR','constraint'=>100],'deleted'=>$int]);$make('suppliers',['id'=>$id,'name'=>['type'=>'VARCHAR','constraint'=>100],'rfc'=>['type'=>'VARCHAR','constraint'=>20,'null'=>true],'status'=>['type'=>'VARCHAR','constraint'=>20],'deleted'=>$int]);$make('clients',['id'=>$id,'company_name'=>['type'=>'VARCHAR','constraint'=>100]]);
    $fields=['id'=>$id,'source_type'=>['type'=>'VARCHAR','constraint'=>20,'null'=>true],'source_id'=>$nint,'source_item_id'=>$nint,'source_folio'=>['type'=>'VARCHAR','constraint'=>80,'null'=>true],'proposal_id'=>$nint,'proposal_item_id'=>$nint,'product_id'=>$int,'supplier_id'=>$int,'client_id'=>$nint,'unit_cost'=>['type'=>'DECIMAL','constraint'=>'18,6'],'sale_unit_price'=>$dec,'quantity'=>$dec,'currency'=>['type'=>'CHAR','constraint'=>3],'quoted_at'=>['type'=>'DATETIME','null'=>true],'notes'=>['type'=>'TEXT','null'=>true],'recorded_by'=>$nint,'created_at'=>['type'=>'DATETIME'],'updated_at'=>['type'=>'DATETIME','null'=>true],'source_status'=>['type'=>'VARCHAR','constraint'=>20],'snapshot_version'=>$int,'economic_hash'=>['type'=>'CHAR','constraint'=>64],'idempotency_key'=>['type'=>'CHAR','constraint'=>64,'null'=>true]];
    $forge->addField($fields);$forge->addKey('id',true);$forge->addUniqueKey('idempotency_key','uq_cost_history_manual_idempotency');$forge->createTable('product_supplier_cost_history');
    $db->table('items')->insert(['id'=>1,'title'=>'Producto','deleted'=>0]);$db->table('suppliers')->insert(['id'=>1,'name'=>'Proveedor','status'=>'active','deleted'=>0]);$db->table('clients')->insert(['id'=>1,'company_name'=>'Cliente']);
    $db->table('product_supplier_cost_history')->insert(['source_type'=>'proposal','source_id'=>9,'source_item_id'=>5,'source_folio'=>'P-9','proposal_id'=>9,'proposal_item_id'=>5,'product_id'=>1,'supplier_id'=>1,'client_id'=>1,'unit_cost'=>'20.000000','sale_unit_price'=>'30.000000','quantity'=>'1.000000','currency'=>'MXN','quoted_at'=>'2026-09-01 00:00:00','created_at'=>'2026-09-01 00:00:00','source_status'=>'sent','snapshot_version'=>1,'economic_hash'=>str_repeat('a',64)]);
    $history=new SupplierCostHistoryService($db);$manual=$history->saveManual(['product_id'=>1,'supplier_id'=>1,'unit_cost'=>'18','notes'=>'Captura manual','idempotency_key'=>'p07-manual'],7);$assert($manual['created'],'manual capture is created through SupplierCostHistoryService');
    $before=$db->table('product_supplier_cost_history')->countAllResults();$comparison=(new SupplierComparisonService($db))->compare(1);$rows=$comparison['suppliers'][0]['history'];$assert(count($rows)===2&&count(array_filter($rows,fn($r)=>$r['source_type']==='manual'&&$r['notes']==='Captura manual'))===1,'comparison exposes formal and manual history together');$assert($before===$db->table('product_supplier_cost_history')->countAllResults(),'comparison does not write history');
    $retry=$history->saveManual(['product_id'=>1,'supplier_id'=>1,'unit_cost'=>'18','notes'=>'Captura manual','idempotency_key'=>'p07-manual'],7);$assert(!$retry['created']&&$retry['id']===$manual['id'],'manual retry remains idempotent');
    try{$history->saveManual(['product_id'=>1,'supplier_id'=>1,'unit_cost'=>'19','notes'=>'Captura manual','idempotency_key'=>'p07-manual'],7);throw new RuntimeException('token conflict accepted');}catch(InvalidArgumentException){$assert(true,'reused manual token with different payload is rejected');}
    $sources=['app/Config/Routes.php'=>['manual-cost/modal-form',"['filter'=>'csrf']"],'app/Controllers/Suppliers.php'=>['guard(\'supplier_costs_edit\')','saveManual','guard(\'supplier_costs_view\')'],'app/Views/suppliers/manual_cost_modal_form.php'=>['csrf_field()','idempotency_key','unit_cost'],'app/Views/suppliers/view.php'=>['Registrar costo','Comparar'],'app/Views/roles/permissions.php'=>['supplier_costs_view','supplier_costs_edit'],'app/Views/proposals/item_modal_form.php'=>['quick-supplier-form','selectSupplierInParent','supplier.val(id).trigger']];foreach($sources as $file=>$need){$body=file_get_contents(ROOTPATH.$file);foreach($need as $text)$assert(str_contains($body,$text),"$file protects/exposes supplier cost workflow: $text");}
    $assert(!str_contains(file_get_contents(APPPATH.'Services/ManualSupplierCostService.php'),'function update')&&!str_contains(file_get_contents(APPPATH.'Services/ManualSupplierCostService.php'),'function delete'),'manual history remains append-only; edit/delete are rejected by contract');
    echo "$passed passed, 0 failed. No PAC or source data accessed.\n";
}catch(Throwable $e){fwrite(STDERR,'[FAIL] '.$e->getMessage().PHP_EOL.$e->getTraceAsString().PHP_EOL);exit(1);}finally{if(isset($db))$db->close();}
