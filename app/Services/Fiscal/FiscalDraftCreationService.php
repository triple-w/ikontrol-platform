<?php
declare(strict_types=1);
namespace App\Services\Fiscal;
use RuntimeException;
use Throwable;

class FiscalDraftCreationService
{
 private $db;private FiscalDecimalCalculator$d;private FiscalIssueDateService$issueDates;
 public function __construct($db=null,?FiscalIssueDateService$issueDates=null){$this->db=$db?:db_connect();$this->d=new FiscalDecimalCalculator();$this->issueDates=$issueDates?:new FiscalIssueDateService();}
 public function create(int$invoiceId,int$issuerId,int$receiverId,int$seriesId,int$preparationId,array$options,int$userId,bool$authorized,bool$confirmSupersede=false):array{
  if(!$authorized)throw new RuntimeException('No tiene permiso para crear borradores fiscales.');
  foreach(['payment_form_code','payment_method_code']as$key)if(trim((string)($options[$key]??''))==='')throw new RuntimeException('Falta seleccionar forma y método de pago SAT.');
  $currency=strtoupper(trim((string)($options['currency_code']??'')));$exchange=trim((string)($options['exchange_rate']??''));
  $this->assertCatalog('sat_payment_forms',(string)$options['payment_form_code']);
  $this->assertCatalog('sat_payment_methods',(string)$options['payment_method_code']);
  (new CfdiPaymentRuleService($this->db))->validate((string)$options['payment_method_code'],(string)$options['payment_form_code']);
  $currencyRow=$this->assertCatalog('sat_currencies',$currency);
  if((int)$currencyRow->requires_exchange_rate&&($exchange===''||$this->d->compare($exchange,'0')<=0))throw new RuntimeException('La moneda seleccionada requiere un tipo de cambio válido.');
  if(!$currencyRow->requires_exchange_rate)$exchange=null;
  $review=(new SaleFiscalReadinessService())->review($invoiceId,$issuerId,$seriesId,$receiverId);
  if(!$review['is_ready'])throw new RuntimeException('La venta no está fiscalmente lista.');
  $prep=$this->db->table('sale_fiscal_pricing_preparations')->where(['id'=>$preparationId,'invoice_id'=>$invoiceId])->get(1)->getRow();
  if(!$prep||in_array($prep->status,['superseded','stale'],true))throw new RuntimeException('La preparación de precios no existe o dejó de estar vigente.');
  if((int)$prep->issuer_profile_id!==$issuerId||(int)$prep->receiver_profile_id!==$receiverId||(int)$prep->fiscal_series_id!==$seriesId)throw new RuntimeException('La preparación de precios no corresponde a la selección fiscal actual.');
  $payload=json_decode((string)$prep->calculation_payload,true);if(!$payload||!empty($payload['errors']))throw new RuntimeException('La preparación de precios contiene errores.');
  $invoice=$this->db->table('invoices')->where(['id'=>$invoiceId,'deleted'=>0])->get(1)->getRow();if(!$invoice)throw new RuntimeException('La venta no existe.');
  $paymentTotal=$this->paymentTotal($invoiceId);
  if($this->d->money((string)$invoice->invoice_total)!==$this->d->money((string)$prep->administrative_total)||$paymentTotal!==$this->d->money((string)$prep->payment_total_snapshot))throw new RuntimeException('La venta o sus pagos cambiaron después de la preparación de precios.');
  $source=$this->sourcePayload($invoice,$review,$prep,$options,$currency,$exchange,$paymentTotal);
  $hash=hash('sha256',$this->canonicalJson($source));
  $existing=$this->db->table('fiscal_documents')->where(['invoice_id'=>$invoiceId,'source_snapshot_hash'=>$hash,'deleted'=>0])->whereIn('status',['draft','ready','locked'])->get(1)->getRow();
  if($existing&&!$confirmSupersede)return['id'=>(int)$existing->id,'status'=>$existing->status,'action'=>'existing','hash'=>$hash];
  $active=$this->db->table('fiscal_documents')->where(['invoice_id'=>$invoiceId,'deleted'=>0])->whereIn('status',['draft','ready','locked'])->orderBy('id','DESC')->get(1)->getRow();
  if($active&&$active->status==='locked'&&!$confirmSupersede)throw new RuntimeException('Existe una preparación cerrada. Debe confirmar su reemplazo.');
  if($active&&!$confirmSupersede)throw new RuntimeException('La venta cambió después del borrador activo. Confirme el reemplazo.');
  $this->db->transBegin();
  try{
   $lockedInvoice=$this->db->query('SELECT * FROM '.$this->db->prefixTable('invoices').' WHERE id=? AND deleted=0 FOR UPDATE',[$invoiceId])->getRow();
   if(!$lockedInvoice||$this->d->money((string)$lockedInvoice->invoice_total)!==$this->d->money((string)$invoice->invoice_total)||$this->paymentTotal($invoiceId)!==$paymentTotal)throw new RuntimeException('La venta cambió durante la creación del borrador.');
   $series=$this->db->query('SELECT * FROM '.$this->db->prefixTable('fiscal_series').' WHERE id=? AND issuer_profile_id=? AND document_type=? AND is_active=1 AND deleted=0 FOR UPDATE',[$seriesId,$issuerId,'ingreso'])->getRow();
   if(!$series)throw new RuntimeException('La serie fiscal no está activa.');
   $folio=max((int)$series->initial_folio,(int)$series->current_folio+1);
   $version=1+(int)($this->db->table('fiscal_documents')->selectMax('version')->where('invoice_id',$invoiceId)->get()->getRow()->version??0);
   $now=get_current_utc_time();$fiscalIssueDate=$this->issueDates->nowForSnapshot();$header=[
    'invoice_id'=>$invoiceId,'issuer_profile_id'=>$issuerId,'receiver_profile_id'=>$receiverId,'fiscal_series_id'=>$seriesId,
    'pricing_preparation_id'=>$preparationId,'document_type'=>'income','status'=>'ready','version'=>$version,'series'=>(string)$series->series,
    'folio'=>$folio,'issue_date'=>$fiscalIssueDate,'expedition_postal_code'=>(string)$review['issuer']['profile']->expedition_postal_code,
    'currency_code'=>$currency,'exchange_rate'=>$exchange,'payment_form_code'=>$options['payment_form_code'],
    'payment_method_code'=>$options['payment_method_code'],'cfdi_use_code'=>(string)$review['receiver']['cfdi_use']->code,
    'export_code'=>(string)($options['export_code']??'01'),'subtotal'=>'0.00','discount'=>'0.00','transferred_tax_total'=>'0.00',
    'withheld_tax_total'=>'0.00','total'=>'0.00','administrative_total_reference'=>$this->d->money((string)$invoice->invoice_total),
    'pricing_mode'=>(string)$prep->pricing_mode,'source_snapshot_hash'=>$hash,'created_by'=>$userId,'created_at'=>$now,'updated_at'=>$now,'deleted'=>0];
   $this->db->table('fiscal_documents')->insert($header);$documentId=(int)$this->db->insertID();
   $this->snapshotParty($documentId,$review['issuer']['profile'],$review['issuer']['regime']??null,true,$review);
   $this->snapshotParty($documentId,$review['receiver']['profile'],$review['receiver']['regime']??null,false,$review);
   $totals=$this->snapshotItems($documentId,$review,$payload,$now);
   $expected=$this->d->money($this->d->sub($this->d->add($this->d->sub($totals['subtotal'],$totals['discount']),$totals['transferred']),$totals['withheld']));
   if($expected!==$totals['total'])throw new RuntimeException('Los totales del borrador no concilian de forma exacta.');
   $this->db->table('fiscal_documents')->where('id',$documentId)->update(['subtotal'=>$totals['subtotal'],'discount'=>$totals['discount'],'transferred_tax_total'=>$totals['transferred'],'withheld_tax_total'=>$totals['withheld'],'total'=>$totals['total']]);
   $this->db->table('fiscal_document_metadata')->insert(['fiscal_document_id'=>$documentId,'metadata_json'=>$this->canonicalJson($source),'warnings_json'=>json_encode($review['warnings'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'rules_version'=>'ikontrol-fiscal-draft-v1','payment_total_snapshot'=>$paymentTotal,'created_at'=>$now]);
   if($active){$this->db->table('fiscal_documents')->where('id',$active->id)->update(['status'=>'superseded','updated_at'=>$now]);$this->audit((int)$active->id,$invoiceId,$userId,'superseded','Reemplazado por una nueva preparación',$active->source_snapshot_hash,$hash);}
   $this->db->table('fiscal_series')->where('id',$seriesId)->update(['current_folio'=>$folio,'updated_at'=>$now]);
   $this->audit($documentId,$invoiceId,$userId,'created',null,null,$hash);
   if(!$this->db->transStatus())throw new RuntimeException('No fue posible guardar el borrador fiscal.');
   $this->db->transCommit();return['id'=>$documentId,'status'=>'ready','action'=>'created','series'=>$series->series,'folio'=>$folio,'hash'=>$hash];
  }catch(Throwable$e){$this->db->transRollback();$this->audit(null,$invoiceId,$userId,'creation_failed',$e->getMessage(),null,$hash);throw$e;}
 }
 public function changeStatus(int$id,string$action,int$userId,bool$authorized,string$reason=''):array{
  if(!$authorized)throw new RuntimeException('No tiene permiso para esta acción fiscal.');$map=['lock'=>[['ready'],'locked'],'cancel'=>[['draft','ready'],'cancelled_internal']];
  if(!isset($map[$action]))throw new RuntimeException('Acción fiscal inválida.');
  $this->db->transBegin();
  try{
   $row=$this->db->query('SELECT * FROM '.$this->db->prefixTable('fiscal_documents').' WHERE id=? AND deleted=0 FOR UPDATE',[$id])->getRow();
   if(!$row)throw new RuntimeException('El borrador fiscal no existe.');
   if($row->status===$map[$action][1]){$this->db->transCommit();return['id'=>$id,'status'=>$row->status,'action'=>'existing','issue_date'=>$row->issue_date];}
   if(!in_array($row->status,$map[$action][0],true))throw new RuntimeException('El estado actual no permite esta acción.');
   $now=get_current_utc_time();$update=['status'=>$map[$action][1],'updated_at'=>$now];
   if($action==='lock'){$update['locked_at']=$now;$update['issue_date']=$this->issueDates->nowForSnapshot();}else$update['cancelled_at']=$now;
   $this->db->table('fiscal_documents')->where('id',$id)->update($update);
   $this->audit($id,(int)$row->invoice_id,$userId,$action==='lock'?'locked':'cancelled_internal',$reason,$row->source_snapshot_hash,$row->source_snapshot_hash);
   $this->db->transCommit();
   return['id'=>$id,'status'=>$update['status'],'action'=>$action,'issue_date'=>$update['issue_date']??$row->issue_date];
  }catch(Throwable$e){$this->db->transRollback();throw$e;}
 }
 private function assertCatalog(string$table,string$code):object{$row=$this->db->table($table)->where(['code'=>$code,'is_active'=>1])->get(1)->getRow();if(!$row)throw new RuntimeException("La clave {$code} no está activa en {$table}.");return$row;}
 private function paymentTotal(int$id):string{$row=$this->db->table('invoice_payments')->selectSum('amount','total')->where(['invoice_id'=>$id,'deleted'=>0])->get()->getRow();return$this->d->money((string)($row->total??'0'));}
 private function sourcePayload(object$invoice,array$review,object$prep,array$options,string$currency,?string$exchange,string$payment):array{$series=$review['series']['record'];$fiscalItems=[];foreach($review['items']as$entry)$fiscalItems[]=['invoice_item'=>(array)$entry['invoice_item'],'settings'=>(array)$entry['settings'],'tax_object_code'=>$entry['readiness']['tax_object_code']??null];return['rules_version'=>'ikontrol-fiscal-draft-v1','invoice'=>['id'=>(int)$invoice->id,'estimate_id'=>(int)$invoice->estimate_id,'project_id'=>(int)$invoice->project_id,'company_id'=>(int)$invoice->company_id,'total'=>$this->d->money((string)$invoice->invoice_total),'subtotal'=>$this->d->money((string)$invoice->invoice_subtotal),'discount'=>$this->d->money((string)$invoice->discount_total),'payment_total'=>$payment,'status'=>$invoice->status],'issuer'=>(array)$review['issuer']['profile'],'receiver'=>(array)$review['receiver']['profile'],'series'=>['id'=>(int)$series->id,'issuer_profile_id'=>(int)$series->issuer_profile_id,'document_type'=>$series->document_type,'series'=>$series->series],'preparation'=>['id'=>(int)$prep->id,'mode'=>$prep->pricing_mode,'payload'=>json_decode((string)$prep->calculation_payload,true)],'fiscal_items'=>$fiscalItems,'payment_form'=>$options['payment_form_code'],'payment_method'=>$options['payment_method_code'],'currency'=>$currency,'exchange_rate'=>$exchange,'export_code'=>$options['export_code']??'01'];}
 private function snapshotParty(int$doc,object$p,?object$regime,bool$issuer,array$review):void{if(!$regime&&$p->tax_regime_id)$regime=$this->db->table('sat_tax_regimes')->where('id',$p->tax_regime_id)->get(1)->getRow();$base=['fiscal_document_id'=>$doc,'rfc'=>(string)$p->rfc,'legal_name'=>(string)$p->legal_name,'tax_regime_code'=>(string)($regime->code??''),'fiscal_postal_code'=>(string)$p->fiscal_postal_code,'street'=>$p->fiscal_street,'external_number'=>$p->fiscal_external_number,'internal_number'=>$p->fiscal_internal_number,'neighborhood'=>$p->fiscal_neighborhood,'locality'=>$p->fiscal_locality,'municipality'=>$p->fiscal_municipality,'state'=>$p->fiscal_state,'created_at'=>get_current_utc_time()];if($issuer){$base+=['expedition_postal_code'=>(string)$p->expedition_postal_code,'country_code'=>(string)($p->fiscal_country_code?:'MEX')];$table='fiscal_document_issuers';}else{$base+=['cfdi_use_code'=>(string)$review['receiver']['cfdi_use']->code,'fiscal_residence_country_code'=>$p->tax_residency_country,'foreign_tax_registration'=>$p->foreign_tax_registration];$table='fiscal_document_receivers';}$this->db->table($table)->insert($base);}
 private function snapshotItems(int$doc,array$review,array$payload,string$now):array{$detail=[];foreach($payload['items']as$row)$detail[(int)$row['invoice_item_id']]=$row;$subtotal='0';$discount='0';$transferred='0';$withheld='0';$summary=[];$line=0;
  foreach($review['items']as$entry){$item=$entry['invoice_item'];if(!(int)$item->item_id||!$entry['settings'])throw new RuntimeException('Una partida manual o incompleta impide crear el borrador.');$sim=$detail[(int)$item->id]??null;if(!$sim)throw new RuntimeException('La preparación no contiene todas las partidas.');$line++;$base=$this->d->money((string)$sim['base']);$lineDiscount=$this->d->money((string)$sim['discount']);$gross=$this->d->money($this->d->add($base,$lineDiscount));$quantity=$this->d->normalize((string)$item->quantity,6);if($this->d->compare($quantity,'0')<=0)throw new RuntimeException('Las cantidades deben ser positivas.');$unitValue=$this->d->normalize($this->d->div($gross,$quantity,6),6);$taxObject=(string)($entry['readiness']['tax_object_code']??'');if($taxObject==='')throw new RuntimeException('Falta ObjetoImp en una partida.');
   $this->db->table('fiscal_document_items')->insert(['fiscal_document_id'=>$doc,'invoice_item_id'=>(int)$item->id,'item_id'=>(int)$item->item_id,'line_number'=>$line,'product_service_code'=>(string)$entry['settings']->product_code,'identification_number'=>$entry['settings']->identification_number,'quantity'=>$quantity,'unit_code'=>(string)$entry['settings']->unit_code,'unit_name'=>(string)($item->unit_type?:$entry['settings']->commercial_unit),'description'=>trim((string)$item->description)!==''?(string)$item->description:(string)$item->title,'unit_value'=>$unitValue,'gross_amount'=>$gross,'discount'=>$lineDiscount,'tax_object_code'=>$taxObject,'taxable_base'=>$base,'transferred_tax_total'=>'0.00','withheld_tax_total'=>'0.00','line_total'=>'0.00','created_at'=>$now]);$fiscalItemId=(int)$this->db->insertID();$lineTransfer='0';$lineWithheld='0';$order=0;
   foreach($sim['taxes']as$sig){[$taxId,$type,$factor,$rate,$quota]=$sig;$tax=$this->db->table('taxes t')->select('c.code')->join('sat_tax_codes c','c.id=t.sat_tax_code_id')->where('t.id',$taxId)->get(1)->getRow();if(!$tax)throw new RuntimeException('Un impuesto fiscal ya no está disponible.');$value=$factor==='Cuota'?$quota:($factor==='Tasa'?$rate:null);$amount='0';if($factor==='Tasa')$amount=$this->d->money($this->d->mul($base,(string)$rate));elseif($factor==='Cuota')$amount=$this->d->money($this->d->mul($quantity,(string)$quota));if($type==='withholding')$lineWithheld=$this->d->add($lineWithheld,$amount);else$lineTransfer=$this->d->add($lineTransfer,$amount);$order++;$this->db->table('fiscal_document_item_taxes')->insert(['fiscal_document_item_id'=>$fiscalItemId,'administrative_tax_id'=>$taxId,'tax_code'=>$tax->code,'tax_type'=>$type==='withholding'?'withheld':'transferred','factor_type'=>$factor,'rate_or_quota'=>$value,'taxable_base'=>$base,'amount'=>$amount,'sort_order'=>$order,'created_at'=>$now]);$key=implode('|',[$tax->code,$type,$factor,(string)$value]);if(!isset($summary[$key]))$summary[$key]=['tax_code'=>$tax->code,'tax_type'=>$type==='withholding'?'withheld':'transferred','factor_type'=>$factor,'rate_or_quota'=>$value,'base'=>'0','amount'=>'0'];$summary[$key]['base']=$this->d->add($summary[$key]['base'],$base);$summary[$key]['amount']=$this->d->add($summary[$key]['amount'],$amount);}
   $lineTotal=$this->d->money($this->d->sub($this->d->add($base,$lineTransfer),$lineWithheld));$this->db->table('fiscal_document_items')->where('id',$fiscalItemId)->update(['transferred_tax_total'=>$this->d->money($lineTransfer),'withheld_tax_total'=>$this->d->money($lineWithheld),'line_total'=>$lineTotal]);$subtotal=$this->d->add($subtotal,$gross);$discount=$this->d->add($discount,$lineDiscount);$transferred=$this->d->add($transferred,$lineTransfer);$withheld=$this->d->add($withheld,$lineWithheld);}
  ksort($summary);foreach($summary as$row)$this->db->table('fiscal_document_tax_totals')->insert(['fiscal_document_id'=>$doc,'tax_code'=>$row['tax_code'],'tax_type'=>$row['tax_type'],'factor_type'=>$row['factor_type'],'rate_or_quota'=>$row['rate_or_quota'],'taxable_base'=>$this->d->money($row['base']),'amount'=>$this->d->money($row['amount']),'created_at'=>$now]);
  return(new \App\Services\Fiscal\Cfdi40\CfdiCurrencyTotalsCalculator())->fromAggregates($subtotal,$discount,$transferred,$withheld);}
 private function canonicalJson(array$data):string{$sort=function(&$v)use(&$sort){if(!is_array($v))return;foreach($v as&$x)$sort($x);if(array_keys($v)!==range(0,count($v)-1))ksort($v);};$sort($data);return json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION);}
 private function audit(?int$doc,int$invoice,int$user,string$action,?string$reason,?string$old,?string$new):void{try{$this->db->table('fiscal_document_audit')->insert(['fiscal_document_id'=>$doc,'invoice_id'=>$invoice,'user_id'=>$user,'action'=>$action,'reason'=>$reason,'previous_hash'=>$old,'new_hash'=>$new,'created_at'=>get_current_utc_time()]);}catch(Throwable$ignored){}}
}
