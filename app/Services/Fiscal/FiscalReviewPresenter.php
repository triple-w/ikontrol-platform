<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use Throwable;

/** Builds the user-facing fiscal review without exposing persistence or PAC details. */
final class FiscalReviewPresenter
{
    public function __construct(private mixed $db = null)
    {
        $this->db ??= db_connect();
    }

    public function present(array $workflowData, bool $advanced = false): array
    {
        $draft = $workflowData['draft'] ?? null;
        $issuer = $workflowData['issuer'] ?? null;
        $receiver = $workflowData['receiver'] ?? null;
        $errors = [];
        $snapshot = null;

        if ($draft) {
            try {
                $snapshot = (new FiscalDraftSnapshotService($this->db))->getCompleteFiscalSnapshot((int) $draft->id);
                $concepts = array_map(static fn(array $item): array => [
                    'quantity' => $item['quantity'], 'total' => $item['total'], 'snapshot' => $item['snapshot'],
                ], $snapshot['items']);
                $validation = (new FiscalDraftValidationService($this->db))->validate(
                    $snapshot['draft'], $snapshot['allocations'], $concepts
                );
                $errors = $validation['errors'];
            } catch (Throwable) {
                $errors[] = ['field'=>'snapshot','code'=>'REVIEW_INCOMPLETE','message'=>'La información fiscal debe actualizarse.','section'=>'document'];
            }
        } elseif (!empty($workflowData['preparation'])) {
            $snapshot=$workflowData['preparation'];$errors=$snapshot['validation']['errors'];
        } else {
            $errors = $this->configurationErrors($workflowData);
        }

        $blockers = array_map(fn(array $error): array => $this->actionable($error), $errors);
        $clientComplete = !$this->hasSection($errors, 'receiver');
        $productsComplete = $snapshot!==null && !$this->hasSection($errors, 'concepts');
        $issuerReady = !$this->hasSection($errors, 'issuer');
        $totalsValid = $snapshot!==null && !$this->hasCode($errors, ['CONCEPT_TOTAL_MISMATCH','CONCEPT_TOTAL_INVALID','TAX_AMOUNT_MISMATCH']);
        $totals = $snapshot['totals'] ?? [
            'subtotal'=>(string)($draft->subtotal??'0'),'discount'=>(string)($draft->discount??'0'),
            'transferred'=>(string)($draft->tax_total??'0'),'withheld'=>'0.000000','total'=>(string)($draft->total??'0'),
        ];
        $items = $snapshot['items'] ?? [];

        return [
            'status'=>$blockers?'review_needed':'ready',
            'summary'=>[
                'issuer_name'=>(string)($issuer->legal_name??''),
                'receiver_name'=>(string)($receiver->legal_name??''),
                'receiver_rfc'=>(string)($receiver->rfc??''),
                'issue_date'=>(string)($draft->issue_date??$snapshot['draft']['issue_date']??''),
                'cfdi_use'=>(string)($draft->cfdi_use_code??$snapshot['draft']['cfdi_use_code']??''),
                'payment_method'=>(string)($draft->payment_method_code??$snapshot['draft']['payment_method_code']??''),
                'payment_form'=>(string)($draft->payment_form_code??$snapshot['draft']['payment_form_code']??''),
                'subtotal'=>(string)$totals['subtotal'],'discount'=>(string)$totals['discount'],
                'transferred_taxes'=>(string)$totals['transferred'],'withheld_taxes'=>(string)$totals['withheld'],
                'total'=>(string)$totals['total'],
            ],
            'checks'=>['client_complete'=>$clientComplete,'products_complete'=>$productsComplete,'issuer_ready'=>$issuerReady,'totals_valid'=>$totalsValid],
            'blockers'=>$blockers,
            'products'=>$this->products($items, $workflowData),
            'advanced'=>$advanced?[
                'draft_id'=>(int)($draft->id??0),'snapshot'=>$snapshot,
                'technical_status'=>(string)($draft->status??'not_created'),
            ]:null,
        ];
    }

    private function configurationErrors(array $data): array
    {
        return $this->configurationErrorsFromSources($data);
        $errors=[];
        if(empty($data['issuer']))$errors[]=['field'=>'issuer_id','code'=>'ISSUER_REQUIRED','message'=>'No hay un emisor disponible.','section'=>'issuer'];
        $receiver=$data['receiver']??null;
        if(!$receiver)$errors[]=['field'=>'receiver_profile_id','code'=>'RECEIVER_REQUIRED','message'=>'El cliente necesita completar sus datos fiscales.','section'=>'receiver'];
        else foreach(['rfc'=>'RFC','legal_name'=>'razón social','tax_regime_id'=>'régimen fiscal','fiscal_postal_code'=>'código postal fiscal']as$field=>$label)if(trim((string)($receiver->{$field}??''))===''||(str_ends_with($field,'_id')&&(int)$receiver->{$field}<1))$errors[]=['field'=>$field,'code'=>'RECEIVER_FIELD_REQUIRED','message'=>"El cliente necesita completar su {$label}.",'section'=>'receiver'];
        foreach(($data['sales']??[])as$entry)foreach($entry['items']as$item)foreach(['fiscal_description'=>'descripción fiscal','product_service_code'=>'ClaveProdServ','unit_code'=>'ClaveUnidad','tax_object_code'=>'Objeto de impuesto']as$field=>$label)if(trim((string)($item->{$field}??''))==='')$errors[]=['field'=>$field,'code'=>'CONCEPT_FISCAL_DATA_REQUIRED','message'=>trim((string)$item->title).": falta {$label}.",'section'=>'concepts'];
        return$errors;
    }

    private function actionable(array $error): array
    {
        $field=(string)($error['field']??'');$section=(string)($error['section']??'document');$message=(string)($error['message']??'Revisa la información fiscal.');
        if($field==='fiscal_postal_code')$message='El cliente necesita completar su código postal fiscal.';
        elseif($field==='tax_regime_id')$message='El cliente necesita completar su régimen fiscal.';
        elseif($field==='rfc')$message='El cliente necesita completar su RFC.';
        elseif($field==='total')$message='El total fiscal no coincide con el total de la venta.';
        return['field'=>$field,'section'=>$section,'message'=>$message,'action'=>$section==='receiver'?'Completar datos del cliente':($section==='concepts'?'Corregir':'Revisar')];
    }

    private function products(array $items,array$data):array
    {
        return $this->productsFromSources($items,$data);
        if($items)return array_map(static function(array$item):array{$s=$item['snapshot'];return['sale_item_id'=>(int)$item['sale_item_id'],'name'=>(string)($s['fiscal_description']??$s['title']??'Producto'),'quantity'=>(string)$item['quantity'],'unit'=>(string)($s['commercial_unit']??''),'unit_price'=>(string)$item['unit_price'],'tax_label'=>self::taxLabel($item['taxes']??[])];},$items);
        $out=[];foreach(($data['sales']??[])as$entry)foreach($entry['items']as$item)$out[]=['sale_item_id'=>(int)$item->id,'name'=>(string)($item->fiscal_description??$item->title),'quantity'=>(string)$item->quantity,'unit'=>(string)$item->unit_type,'unit_price'=>(string)$item->rate,'tax_label'=>'Configuración fiscal pendiente'];return$out;
    }
    private static function taxLabel(array$taxes):string{foreach($taxes as$tax)if(($tax['tax_type']??'')==='transfer'&&($tax['factor_type']??'')==='Tasa')return'IVA '.rtrim(rtrim(number_format((float)$tax['rate_or_quota']*100,2,'.',''),'0'),'.').'%';return$taxes?'Impuestos configurados':'Sin impuesto';}
    private function configurationErrorsFromSources(array$data):array
    {
        $errors=[];if(empty($data['issuer']))$errors[]=['field'=>'issuer_id','code'=>'ISSUER_REQUIRED','message'=>'No hay un emisor disponible.','section'=>'issuer'];
        $receiver=$data['receiver']??null;if(!$receiver)$errors[]=['field'=>'receiver_profile_id','code'=>'RECEIVER_REQUIRED','message'=>'El cliente necesita completar sus datos fiscales.','section'=>'receiver'];
        else foreach(['rfc'=>'RFC','legal_name'=>'razón social','tax_regime_id'=>'régimen fiscal','fiscal_postal_code'=>'código postal fiscal']as$field=>$label)if(trim((string)($receiver->{$field}??''))===''||(str_ends_with($field,'_id')&&(int)$receiver->{$field}<1))$errors[]=['field'=>$field,'code'=>'RECEIVER_FIELD_REQUIRED','message'=>"El cliente necesita completar su {$label}.",'section'=>'receiver'];
        $resolver=new ProductFiscalConfigurationResolver($this->db);foreach(($data['sales']??[])as$entry)foreach($entry['items']as$item){$effective=(new InvoiceItemFiscalOverrideService($this->db))->effective((int)$item->id);$resolved=!empty($effective['ready'])?$effective:$resolver->resolve((int)$item->item_id);if(empty($resolved['ready']))$errors[]=['field'=>'product_configuration','code'=>$resolved['source']==='manual_line'?'MANUAL_LINE_FISCAL_REQUIRED':'PRODUCT_FISCAL_CONFIGURATION_REQUIRED','message'=>$resolved['source']==='manual_line'?'Esta partida fue capturada como concepto libre y necesita datos fiscales antes de facturar.':trim((string)$item->title).': falta '.implode(', ',$resolved['missing']).'.','section'=>'concepts'];}return$errors;
    }
    private function productsFromSources(array$items,array$data):array
    {
        if($items)return array_map(static function(array$item):array{$s=$item['snapshot'];$pid=(int)($item['product_id']??0);return['sale_item_id'=>(int)$item['sale_item_id'],'product_id'=>$pid,'source'=>$pid?'master_product':'manual_line','complete'=>true,'missing'=>[],'name'=>(string)($s['fiscal_description']??$s['title']??'Producto'),'quantity'=>(string)$item['quantity'],'unit'=>(string)($s['commercial_unit']??''),'unit_price'=>(string)$item['unit_price'],'tax_label'=>self::taxLabel($item['taxes']??[])];},$items);
        $out=[];$resolver=new ProductFiscalConfigurationResolver($this->db);foreach(($data['sales']??[])as$entry)foreach($entry['items']as$item){$effective=(new InvoiceItemFiscalOverrideService($this->db))->effective((int)$item->id);$resolved=!empty($effective['ready'])?$effective:$resolver->resolve((int)$item->item_id);$out[]=['sale_id'=>(int)$entry['sale']->id,'sale_item_id'=>(int)$item->id,'product_id'=>(int)$item->item_id,'source'=>$resolved['source'],'complete'=>(bool)($resolved['ready']??false),'missing'=>$resolved['missing']??[],'name'=>(string)($item->fiscal_description??$item->title),'quantity'=>(string)$item->quantity,'unit'=>(string)$item->unit_type,'unit_price'=>(string)$item->rate,'tax_label'=>!empty($resolved['ready'])?self::taxLabel($resolved['taxes']??[]):'Configuración fiscal pendiente'];}return$out;
    }
    private function hasSection(array$errors,string$section):bool{return(bool)array_filter($errors,static fn($e)=>($e['section']??'')===$section);}
    private function hasCode(array$errors,array$codes):bool{return(bool)array_filter($errors,static fn($e)=>in_array($e['code']??'',$codes,true));}
}
