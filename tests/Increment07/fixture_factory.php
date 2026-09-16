<?php
declare(strict_types=1);
use App\Domain\Fiscal\Cfdi40\{CfdiDocument,CfdiParty,CfdiConcept,CfdiConceptTax,CfdiTaxSummary};
return static function(array$override=[]):CfdiDocument{
 $data=array_merge(['id'=>7001,'status'=>'locked','document_type'=>'income','series'=>'T','folio'=>'7','issue_date'=>'2026-07-23 10:20:30','currency_code'=>'MXN','exchange_rate'=>null,'payment_form_code'=>'01','payment_method_code'=>'PUE','export_code'=>'01','expedition_postal_code'=>'06000','subtotal'=>'100.00','discount'=>'0.00','transferred_tax_total'=>'16.00','withheld_tax_total'=>'0.00','total'=>'116.00'],$override);
 $issuer=new CfdiParty(['rfc'=>'AAA010101AAA','legal_name'=>'EMISOR & COMPAÑÍA','tax_regime_code'=>'601']);
 $receiver=new CfdiParty(['rfc'=>'XAXX010101000','legal_name'=>'PÚBLICO <GENERAL>','tax_regime_code'=>'616','fiscal_postal_code'=>'06000','cfdi_use_code'=>'S01','fiscal_residence_country_code'=>'MEX','foreign_tax_registration'=>null]);
 $tax=new CfdiConceptTax('002','transferred','Tasa','0.160000','100.00','16.00');
 $concept=new CfdiConcept(['product_service_code'=>'01010101','identification_number'=>null,'quantity'=>'2.000000','unit_code'=>'H87','unit_name'=>'Pieza','description'=>'Artículo "especial" & prueba','unit_value'=>'50.000000','gross_amount'=>'100.00','discount'=>'0.00','tax_object_code'=>'02'],[$tax]);
 return new CfdiDocument($data,$issuer,$receiver,[$concept],[new CfdiTaxSummary('002','transferred','Tasa','0.160000','100.00','16.00')]);
};
