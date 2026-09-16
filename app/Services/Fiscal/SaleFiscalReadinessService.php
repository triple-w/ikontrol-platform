<?php
declare(strict_types=1);
namespace App\Services\Fiscal;

use App\Models\Invoices_model;
use App\Models\Invoice_items_model;
use App\Models\Fiscal\Fiscal_profiles_model;
use App\Models\Fiscal\Sat_cfdi_uses_model;
use App\Models\Fiscal\Sat_tax_regimes_model;

class SaleFiscalReadinessService
{
    public function review(int$invoiceId,?int$issuerId=null,?int$seriesId=null,?int$receiverId=null):array
    {
        $invoice=(new Invoices_model())->get_details(['id'=>$invoiceId])->getRow();
        if(!$invoice||!$invoice->id||$invoice->deleted)throw new \RuntimeException('La venta no existe.');
        $profiles=new Fiscal_profiles_model();
        if(!$issuerId){$default=$profiles->defaultIssuer($invoice->company_id?(int)$invoice->company_id:null);$issuerId=$default->id?(int)$default->id:null;}
        $issuerProfile=$issuerId?$profiles->get_one($issuerId):null;
        $issuer=(new IssuerFiscalReadinessService())->evaluate($issuerId,$invoice->company_id?(int)$invoice->company_id:null);
        $issuer['profile']=$issuerProfile&&$issuerProfile->id?$issuerProfile:null;
        if(!$seriesId&&$issuerId){$defaultSeries=(new FiscalFolioService())->getDefaultSeries($issuerId,'ingreso');$seriesId=$defaultSeries?(int)$defaultSeries->id:null;}
        $series=['series_id'=>$seriesId,'is_ready'=>false,'errors'=>[],'preview'=>null,'record'=>null];
        if(!$seriesId)$series['errors'][]='Falta seleccionar una serie fiscal de ingreso.';else{$row=db_connect()->table('fiscal_series')->where(['id'=>$seriesId,'issuer_profile_id'=>$issuerId,'document_type'=>'ingreso','is_active'=>1,'deleted'=>0])->get(1)->getRow();if(!$row)$series['errors'][]='La serie de ingreso seleccionada no está activa para el emisor.';else{$series['record']=$row;$series['preview']=(new FiscalFolioService())->previewNextFolio($seriesId);$series['is_ready']=true;}}
        if(!$receiverId){$p=$profiles->get_one_where(['client_id'=>$invoice->client_id,'profile_type'=>'receiver','is_default'=>1]);$receiverId=$p->id?(int)$p->id:null;}
        $receiverProfile=$receiverId?$profiles->get_one($receiverId):null;if($receiverProfile&&$receiverProfile->id&&((int)$receiverProfile->client_id!==(int)$invoice->client_id||$receiverProfile->profile_type!=='receiver'))$receiverProfile=null;$regime=$receiverProfile&&$receiverProfile->tax_regime_id?(new Sat_tax_regimes_model())->get_one($receiverProfile->tax_regime_id):null;$use=$receiverProfile&&$receiverProfile->default_cfdi_use_id?(new Sat_cfdi_uses_model())->get_one($receiverProfile->default_cfdi_use_id):null;$receiver=(new FiscalReadinessService())->evaluate($receiverProfile&&$receiverProfile->id?$receiverProfile:null,$regime,$use);$receiver['profile']=$receiverProfile&&$receiverProfile->id?$receiverProfile:null;$receiver['regime']=$regime;$receiver['cfdi_use']=$use;
        $items=[];$itemErrors=[];$itemWarnings=[];foreach((new Invoice_items_model())->get_details(['invoice_id'=>$invoiceId])->getResult()as$row){if(!(int)$row->item_id)$check=['status'=>'incomplete','is_ready'=>false,'errors'=>['La partida manual no tiene producto asociado ni configuración fiscal.'],'warnings'=>[],'missing_fields'=>['item_id'],'information'=>[],'tax_object_code'=>null];else$check=(new ItemFiscalReadinessService())->evaluate((int)$row->item_id);$settings=$row->item_id?db_connect()->table('item_fiscal_settings s')->select('s.*, p.code product_code, u.code unit_code')->join('sat_product_service_keys p','p.id=s.sat_product_service_key_id','left')->join('sat_unit_keys u','u.id=s.sat_unit_key_id','left')->where(['s.item_id'=>$row->item_id,'s.is_default'=>1,'s.deleted'=>0])->get(1)->getRow():null;$items[]=['invoice_item'=>$row,'readiness'=>$check,'settings'=>$settings];$itemErrors=array_merge($itemErrors,$check['errors']);$itemWarnings=array_merge($itemWarnings,$check['warnings']);}
        $currency=trim((string)($invoice->currency?:get_setting('default_currency')));$saleErrors=[];if(!$items)$saleErrors[]='La venta no contiene partidas.';if($currency==='')$saleErrors[]='La venta no tiene moneda administrativa identificable.';if((new FiscalDecimalCalculator())->compare((string)$invoice->invoice_total,'0')<0)$saleErrors[]='El total administrativo no puede ser negativo para una futura operación de ingreso.';if($invoice->status==='cancelled')$saleErrors[]='La venta está cancelada administrativamente.';
        $errors=['issuer'=>$issuer['errors'],'receiver'=>$receiver['errors'],'items'=>$itemErrors,'series'=>$series['errors'],'sale'=>$saleErrors];$warnings=['issuer'=>$issuer['warnings'],'receiver'=>$receiver['warnings'],'items'=>$itemWarnings,'series'=>[],'sale'=>['El cálculo fiscal definitivo, la forma y el método de pago SAT todavía no se han construido.']];$flatErrors=array_merge(...array_values($errors));$flatWarnings=array_merge(...array_values($warnings));$ready=!$flatErrors&&$issuer['is_ready']&&$receiver['is_ready']&&$series['is_ready'];
        return['invoice_id'=>$invoiceId,'is_ready'=>$ready,'status'=>$ready?($flatWarnings?'ready_with_warnings':'ready_for_fiscal_preparation'):'not_ready','issuer'=>$issuer,'receiver'=>$receiver,'series'=>$series,'items'=>$items,'sale'=>['currency'=>$currency,'subtotal'=>$invoice->invoice_subtotal,'discount'=>$invoice->discount_total,'tax'=>$invoice->tax,'tax2'=>$invoice->tax2,'tax3'=>$invoice->tax3,'total'=>$invoice->invoice_total,'status'=>$invoice->status,'payment_method'=>$invoice->payment_method??null],'errors'=>$errors,'warnings'=>$warnings];
    }
}
