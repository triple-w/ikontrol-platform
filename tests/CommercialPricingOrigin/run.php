<?php
declare(strict_types=1);
ob_start();
$root=dirname(__DIR__,2);require $root.'/tests/bootstrap.php';helper(['general','date_time']);
$db=require $root.'/tests/Increment02/isolated_database.php';
use App\Services\CommercialMarginService;
use App\Services\Fiscal\CommercialItemTaxResolver;
use App\Services\Fiscal\FiscalItemOverrideContract;
use App\Services\SupplierComparisonService;
use App\Services\SupplierCostHistoryService;
$p=$f=0;$ok=function(bool$v,string$m)use(&$p,&$f){echo($v?'[PASS] ':'[FAIL] ').$m.PHP_EOL;$v?$p++:$f++;};
$margin=new CommercialMarginService();$contract=new FiscalItemOverrideContract();
$input=['fiscal_override_enabled'=>'1','product_service_code'=>'01010101','unit_code'=>'H87','fiscal_commercial_unit'=>'Pieza','tax_object_code'=>'02','fiscal_description'=>'Producto','fiscal_taxes'=>[['tax_code'=>'002','tax_type'=>'transfer','factor_type'=>'Tasa','rate_or_quota'=>'0.160000']]];
$fiscal=$contract->fromInput($input,0);$resolver=new CommercialItemTaxResolver(new stdClass());
$manual=$resolver->product(0,1,'1','166.666667','0',null,$fiscal,'manual');
$derived=$resolver->product(0,1,'1',$margin->priceFromMargin('100','40'),'0',null,$fiscal,'cost_margin');
$derived650=$resolver->product(0,1,'1',$margin->priceFromMargin('650','45'),'0',null,$fiscal,'cost_margin');
$ok($manual['pricing_mode']==='tax_inclusive'&&$manual['total']==='166.666667','precio manual conserva tax_inclusive');
$ok($derived['base']==='166.666667'&&$derived['transfers']==='26.666667'&&$derived['total']==='193.333334','costo 100 margen 40 es base tax_exclusive');
$ok($derived650['base']==='1181.818182'&&$derived650['transfers']==='189.090909'&&$derived650['total']==='1370.909091','costo 650 margen 45 es base tax_exclusive');
$ok($margin->priceOrigin('cost_margin','650','45','1181.818182')==='cost_margin'&&$margin->priceOrigin('cost_margin','650','45','1181.81')==='manual','origen cost_margin exige coincidencia decimal canónica');
$ok($db->fieldExists('price_origin','proposal_items')&&$db->fieldExists('price_origin','estimate_items')&&$db->fieldExists('price_origin','invoice_items'),'los tres módulos persisten price_origin');
$ok($db->fieldExists('source_type','product_supplier_cost_history')&&$db->fieldExists('source_item_id','product_supplier_cost_history'),'histórico posee origen documental genérico');
$supplier=$db->table('suppliers')->where(['deleted'=>0,'status'=>'active'])->get(1)->getRow();$actor=(int)$db->table('users')->where('deleted',0)->get(1)->getRow()->id;
$service=new SupplierCostHistoryService($db);$sources=[];
foreach(['proposal'=>['proposals','proposal_items','proposal_id','sent'],'estimate'=>['estimates','estimate_items','estimate_id','sent'],'invoice'=>['invoices','invoice_items','invoice_id','not_paid']] as$type=>$cfg){[$parentTable,$itemTable,$fk,$status]=$cfg;$parent=$db->table($parentTable)->where('deleted',0)->get(1)->getRow();$item=$parent?$db->table($itemTable)->where([$fk=>$parent->id,'deleted'=>0])->where('item_id >',0)->get(1)->getRow():null;if(!$item)continue;$db->table($itemTable)->where('id',$item->id)->update(['supplier_id'=>$supplier->id,'cost'=>'321.123456','rate'=>'500.000000','quantity'=>'1.000000']);$method='snapshot'.ucfirst($type);$service->$method((int)$parent->id,$status,$actor);$row=$db->table('product_supplier_cost_history')->where(['source_type'=>$type,'source_item_id'=>$item->id])->orderBy('id','DESC')->get(1)->getRow();$ok((bool)$row,"snapshot común desde {$type}");if($row)$sources[$type]=[$row,(int)$item->item_id];}
$types=array_keys($sources);$ok(!array_diff(['proposal','estimate','invoice'],$types),'histórico reúne Proposal, Estimate e Invoice');
if($sources){$productId=reset($sources)[1];$comparison=(new SupplierComparisonService($db))->compare($productId);$ok(count($comparison['suppliers'])>0,'comparador consulta la fuente común');}
$controllers=file_get_contents($root.'/app/Controllers/Proposals.php').file_get_contents($root.'/app/Controllers/Estimates.php').file_get_contents($root.'/app/Controllers/Invoices.php');
$ok(substr_count($controllers,'priceOrigin(')>=3,'los tres guardados resuelven el mismo origen de precio');
$public=file_get_contents($root.'/app/Views/estimates/estimate_public_preview.php').file_get_contents($root.'/app/Views/invoices/invoice_pdf.php');
$ok(!str_contains($public,'supplier_id')&&!str_contains($public,'supplier_cost_history'),'proveedor y costo siguen privados');
echo"TOTAL PASS=$p FAIL=$f".PHP_EOL;$out=ob_get_clean();echo$out;exit($f?1:0);
