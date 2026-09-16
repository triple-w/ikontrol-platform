<?php
declare(strict_types=1);
namespace App\Services\Fiscal;
use App\Models\Invoices_model;
use App\Models\Invoice_items_model;
use App\Models\Fiscal\Fiscal_profiles_model;
use App\Models\Fiscal\Sat_tax_regimes_model;
use App\Models\Fiscal\Sat_cfdi_uses_model;
class InvoiceFiscalReviewService {
 public function review(int$invoiceId):array{
  $invoice=(new Invoices_model())->get_one($invoiceId);if(!$invoice->id||$invoice->deleted)throw new \RuntimeException('La venta no existe.');
  $profile=(new Fiscal_profiles_model())->get_one_where(['client_id'=>$invoice->client_id,'profile_type'=>'receiver','is_default'=>1]);$regime=$profile->tax_regime_id?(new Sat_tax_regimes_model())->get_one($profile->tax_regime_id):null;$use=$profile->default_cfdi_use_id?(new Sat_cfdi_uses_model())->get_one($profile->default_cfdi_use_id):null;$client=(new FiscalReadinessService())->evaluate($profile->id?$profile:null,$regime,$use);
  $items=[];$ready=0;foreach((new Invoice_items_model())->get_details(['invoice_id'=>$invoiceId])->getResult()as$row){if(!(int)$row->item_id){$check=['status'=>'incomplete','is_ready'=>false,'errors'=>['La partida manual no tiene producto asociado y requiere captura fiscal específica.'],'warnings'=>[],'missing_fields'=>['item_id']];}else{$check=(new ItemFiscalReadinessService())->evaluate((int)$row->item_id);}if($check['is_ready'])$ready++;$items[]=['invoice_item_id'=>(int)$row->id,'item_id'=>(int)$row->item_id,'title'=>$row->title,'readiness'=>$check];}
  $total=count($items);$isReady=$client['is_ready']&&$ready===$total&&$total>0;$warnings=[];foreach($items as$i)if($i['readiness']['warnings'])$warnings=array_merge($warnings,$i['readiness']['warnings']);$status=$isReady?($warnings?'ready_with_warnings':'ready'):'not_ready';
  return['invoice_id'=>$invoiceId,'status'=>$status,'client_readiness'=>$client,'item_count'=>$total,'ready_items'=>$ready,'incomplete_items'=>$total-$ready,'items'=>$items,'administrative_total'=>$invoice->invoice_total,'warning'=>'El total mostrado es administrativo y todavía no constituye un cálculo fiscal definitivo.'];
 }
}
