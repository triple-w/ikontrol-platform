<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
helper(['date_time','general','currency','form']);
use App\Services\Fiscal\CreditNoteFiscalCalculator;
$pass=$fail=0;$ok=static function(bool$c,string$m)use(&$pass,&$fail){echo($c?'[PASS] ':'[FAIL] ').$m.PHP_EOL;$c?$pass++:$fail++;};
try{
 $c=new CreditNoteFiscalCalculator();
 $source=(object)['quantity'=>'80.000000','gross_amount'=>'17241.379310','discount'=>'0.000000'];
 $iva=(object)['taxable_base'=>'17241.379310','amount'=>'2758.620690','factor_type'=>'Tasa','tax_type'=>'transferred','tax_code'=>'002','rate_or_quota'=>'0.160000'];
 $line=$c->line($source,'8.000000',[$iva]);
 $ok($line['subtotal']==='1724.14'&&$line['taxes'][0]['base']==='1724.14','Caso 80→8 redondea Importe y Base una sola vez a 1724.14.');
 $ok($line['transferred']==='275.86'&&$line['total']==='2000.00','Caso PAC cuadra IVA 275.86 y Total 2000.00.');
 $ok($c->round('1724.137931',2)==='1724.14','Redondeo decimal half-up reemplaza truncado.');
 $oneSource=(object)['quantity'=>'1.000000','gross_amount'=>'0.862069','discount'=>'0.000000'];
 $oneTax=(object)['taxable_base'=>'0.862069','amount'=>'0.137931','factor_type'=>'Tasa','tax_type'=>'transferred','tax_code'=>'002','rate_or_quota'=>'0.160000'];
 $one=$c->line($oneSource,'1.000000',[$oneTax]);
 $ok($one['total']==='1.00','El cálculo fiscal admite una línea relacionada cuyo total es $1.00.');
 $zeroTax=clone$iva;$zeroTax->rate_or_quota='0.000000';$zeroTax->amount='0.000000';$zero=$c->line($source,'8.000000',[$zeroTax]);$ok($zero['total']==='1724.14','IVA 0 conserva cuadre.');
 $exempt=clone$zeroTax;$exempt->factor_type='Exento';$ex=$c->line($source,'8.000000',[$exempt]);$ok($ex['taxes'][0]['amount']==='0.00'&&$ex['total']==='1724.14','Exento no inventa impuesto.');
 $discountSource=(object)['quantity'=>'10.000000','gross_amount'=>'1000.000000','discount'=>'100.000000'];$discount=$c->line($discountSource,'5.000000',[]);$ok($discount['subtotal']==='500.00'&&$discount['discount']==='50.00'&&$discount['total']==='450.00','Descuento proporcional entra en la fórmula.');
 $ret=(object)['taxable_base'=>'1000.000000','amount'=>'100.000000','factor_type'=>'Tasa','tax_type'=>'withheld','tax_code'=>'001','rate_or_quota'=>'0.100000'];$retained=$c->line((object)['quantity'=>'10.000000','gross_amount'=>'1000.000000','discount'=>'0.000000'],'5.000000',[$ret]);$ok($retained['withheld']==='50.00'&&$retained['total']==='450.00','Retención se resta del Total.');
 $totals=$c->totals([$line,$discount]);$ok($totals['total']===$totals['calculated']&&$totals['difference']==='0.00','Varias líneas cuadran suma global.');
 $ok($line['subtotal']!=='1724.13','Precisión original mayor a dos decimales no se trunca.');
 $db=require dirname(__DIR__).'/Increment02/isolated_database.php';$service=new App\Services\Fiscal\CreditNoteService($db);$user=(int)$db->table('users')->where(['is_admin'=>1,'deleted'=>0])->get(1)->getRow()->id;$candidate=null;$sourceItem=null;foreach($service->eligibleDocuments()as$doc){foreach($db->table('fiscal_document_items')->where('fiscal_document_id',$doc->id)->get()->getResult()as$item){if(bccomp((string)$item->quantity,'8',6)>=0){$candidate=$doc;$sourceItem=$item;break 2;}}}if(!$candidate)throw new RuntimeException('Sin fixture fiscal parcial.');$id=$service->create((int)$candidate->id,$user);$ctx=$service->context($id);$quantities=[];foreach($ctx['items']as$item){$quantities[(int)$item->id]=(int)$item->source_fiscal_document_item_id===(int)$sourceItem->id?'8.000000':'0.000001';}$service->update($id,$quantities,(string)$ctx['note']->issue_date,$user);$square=$service->fiscalSquare($id);$xml=$service->buildXml($id);$dom=new DOMDocument();$dom->loadXML($xml);$root=$dom->documentElement;
 $ok($square['valid']&&$square['difference']==='0.00','Preflight local valida el XML materializado, no sólo datos administrativos.');
 $ok($root->getAttribute('Total')===$square['calculated'],'Comprobante.Total proviene de los mismos componentes XML.');
 $review=$service->review($id);$ok(!empty($review['square'])&&$review['square']['valid'],'Revisión fiscal expone Cuadre fiscal correcto.');$material=$service->materialize($id,$user);foreach($ctx['items']as$item){$quantities[(int)$item->id]=(int)$item->source_fiscal_document_item_id===(int)$sourceItem->id?'7.000000':'0.000001';}$service->update($id,$quantities,(string)$service->context($id)['note']->issue_date,$user);$oldDocument=$db->table('fiscal_documents')->where('id',$material['document_id'])->get(1)->getRow();$updatedNote=$db->table('fiscal_credit_notes')->where('id',$id)->get(1)->getRow();$ok($oldDocument->status==='rejected'&&empty($updatedNote->fiscal_document_id),'Editar tras un rechazo invalida el pre-XML viejo sin borrar su evidencia.');
 $balance=(new App\Services\Fiscal\CreditNoteBalanceService($db))->available((int)$candidate->id);$ok(bccomp($root->getAttribute('Total'),$balance,6)<=0,'Saldo administrativo se usa sólo como límite máximo.');
 $view=file_get_contents(APPPATH.'Views/credit_notes/review.php');$ok(str_contains($view,'Cuadre fiscal')&&str_contains($view,'Total calculado')&&str_contains($view,'Diferencia'),'Modal muestra el desglose de cuadre fiscal.');
}catch(Throwable$e){echo'[FAIL] '.get_class($e).': '.$e->getMessage().' '.$e->getFile().':'.$e->getLine().PHP_EOL;$fail++;}
echo"TOTAL PASS=$pass FAIL=$fail".PHP_EOL;exit($fail?1:0);
