<?php
declare(strict_types=1);
ob_start();$root=dirname(__DIR__,2);require $root.'/tests/bootstrap.php';helper(['plugin','general','date_time','currency','form']);$db=require $root.'/tests/Increment02/isolated_database.php';
$GLOBALS['hooks']=new class{public function apply_filters(string $name,array $data):array{return$data;}public function do_action(string $name,array $data):void{}};
use App\Models\Invoice_items_model;use App\Services\CommercialMarginService;use App\Services\Fiscal\FiscalDecimal;
$p=$f=0;$ok=function(bool$v,string$m)use(&$p,&$f){echo($v?'[PASS] ':'[FAIL] ').$m.PHP_EOL;$v?$p++:$f++;};
$view=file_get_contents($root.'/app/Views/invoices/item_modal_form.php');$fields=file_get_contents($root.'/app/Views/items/_supplier_cost_fields.php');
$ok(strpos($view,'form_close();')<strpos($view,'_supplier_cost_modals'),'submodal proveedor está fuera del form de invoice');
$ok(!str_contains($fields,'form_open'.'(get_uri'.'('.'suppliers/save'.')'),'parcial de campos no anida formularios');
$invoice=$db->table('invoices')->where('deleted',0)->orderBy('id','DESC')->get(1)->getRow();$product=$db->table('items')->where('deleted',0)->get(1)->getRow();$supplier=$db->table('suppliers')->where(['deleted'=>0,'status'=>'active'])->get(1)->getRow();
$margin=new CommercialMarginService();$rate=$margin->priceFromMargin('650','45');
$base=['invoice_id'=>$invoice->id,'title'=>'Regression invoice item','description'=>'','quantity'=>'1.000000','unit_type'=>$product->unit_type?:'Pieza','cost'=>'650.000000','profit_percentage'=>'45.000000','rate'=>$rate,'price_origin'=>'cost_margin','total'=>FiscalDecimal::multiply($rate,'1'),'taxable'=>'','item_id'=>$product->id,'supplier_id'=>null,'fiscal_override_json'=>null,'deleted'=>0];
$model=new Invoice_items_model($db);$id=$model->save_item_and_update_invoice($base,0,(int)$invoice->id);$ok($id>0&&$db->table('invoice_items')->where('id',$id)->countAllResults()===1,'producto sin proveedor se inserta');
$stored=$db->table('invoice_items')->where('id',$id)->get(1)->getRow();$ok($stored->rate==='1181.818182'&&$stored->price_origin==='cost_margin','costo 650 margen 45 guarda rate base');
$id2=$model->save_item_and_update_invoice(array_merge($base,['supplier_id'=>$supplier->id,'title'=>'Regression supplier item']),0,(int)$invoice->id);$ok($id2>0&&(int)$db->table('invoice_items')->where('id',$id2)->get(1)->getRow()->supplier_id===(int)$supplier->id,'producto con proveedor se inserta');
$updated=$model->save_item_and_update_invoice(['quantity'=>'2.000000','total'=>FiscalDecimal::multiply($rate,'2')],$id,(int)$invoice->id);$ok((int)$updated===(int)$id&&bccomp((string)$db->table('invoice_items')->where('id',$id)->get(1)->getRow()->quantity,'2',6)===0,'editar partida persiste');
$ok(str_contains($view,'newData: result.data')&&str_contains($view,'dataId: result.id'),'callback refresca invoice-item-table');
echo"TOTAL PASS=$p FAIL=$f".PHP_EOL;$out=ob_get_clean();echo$out;exit($f?1:0);
