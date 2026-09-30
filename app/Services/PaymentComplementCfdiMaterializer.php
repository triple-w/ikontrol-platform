<?php
declare(strict_types=1);

namespace App\Services;

use App\Services\Fiscal\FiscalDecimal;
use DOMDocument;
use DOMElement;
use RuntimeException;

final class PaymentComplementCfdiMaterializer
{
    public function materialize(array $snapshot): string
    {
        $dom=new DOMDocument('1.0','UTF-8');$dom->formatOutput=true;
        $root=$dom->createElementNS('http://www.sat.gob.mx/cfd/4','cfdi:Comprobante');$dom->appendChild($root);
        foreach(['Version'=>'4.0','Serie'=>$snapshot['series']??null,'Folio'=>$snapshot['folio']??null,'Fecha'=>$snapshot['issue_date'],'SubTotal'=>'0','Moneda'=>'XXX','Total'=>'0','TipoDeComprobante'=>'P','Exportacion'=>'01','LugarExpedicion'=>$snapshot['issuer']['expedition_postal_code']??''] as$key=>$value)if($value!==null&&$value!=='')$root->setAttribute($key,(string)$value);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/','xmlns:pago20','http://www.sat.gob.mx/Pagos20');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/','xmlns:xsi','http://www.w3.org/2001/XMLSchema-instance');
        $root->setAttributeNS('http://www.w3.org/2001/XMLSchema-instance','xsi:schemaLocation','http://www.sat.gob.mx/cfd/4 http://www.sat.gob.mx/sitio_internet/cfd/4/cfdv40.xsd http://www.sat.gob.mx/Pagos20 http://www.sat.gob.mx/sitio_internet/cfd/Pagos/Pagos20.xsd');
        $issuer=$dom->createElement('cfdi:Emisor');$this->attributes($issuer,['Rfc'=>$snapshot['issuer']['rfc']??'','Nombre'=>$snapshot['issuer']['legal_name']??'','RegimenFiscal'=>$snapshot['issuer']['tax_regime_code']??'']);$root->appendChild($issuer);
        $receiver=$dom->createElement('cfdi:Receptor');$this->attributes($receiver,['Rfc'=>$snapshot['receiver']['rfc']??'','Nombre'=>$snapshot['receiver']['legal_name']??'','DomicilioFiscalReceptor'=>$snapshot['receiver']['fiscal_postal_code']??'','RegimenFiscalReceptor'=>$snapshot['receiver']['tax_regime_code']??'','UsoCFDI'=>'CP01']);$root->appendChild($receiver);
        $concepts=$dom->createElement('cfdi:Conceptos');$concept=$dom->createElement('cfdi:Concepto');$this->attributes($concept,['ClaveProdServ'=>'84111506','Cantidad'=>'1','ClaveUnidad'=>'ACT','Descripcion'=>'Pago','ValorUnitario'=>'0','Importe'=>'0','ObjetoImp'=>'01']);$concepts->appendChild($concept);$root->appendChild($concepts);
        $complement=$dom->createElement('cfdi:Complemento');$paymentsNode=$dom->createElement('pago20:Pagos');$paymentsNode->setAttribute('Version','2.0');
        $totals=$dom->createElement('pago20:Totales');$this->attributes($totals,$snapshot['sat_totals']??['MontoTotalPagos'=>$snapshot['totals']['MontoTotalPagos']??'0.000000']);$paymentsNode->appendChild($totals);
        foreach($snapshot['payments']??[] as$payment)$this->appendPayment($dom,$paymentsNode,$payment,$snapshot['totals']??[]);
        $complement->appendChild($paymentsNode);$root->appendChild($complement);
        $xml=$dom->saveXML();if(!$xml)throw new RuntimeException('No fue posible materializar XML.');return$xml;
    }

    private function appendPayment(DOMDocument $dom,DOMElement $parent,array $payment,array $totals):void
    {
        $node=$dom->createElement('pago20:Pago');$currency=strtoupper(trim((string)($payment['MonedaP']??'')));
        $values=$payment;$values['Monto']=$this->moneyForCurrency((string)($payment['Monto']??''),$currency);
        if($currency==='MXN')$values['TipoCambioP']='1';elseif(isset($payment['TipoCambioP'])&&trim((string)$payment['TipoCambioP'])!=='')$values['TipoCambioP']=$this->exchangeRate((string)$payment['TipoCambioP']);
        $this->selectedAttributes($node,$values,['FechaPago','FormaDePagoP','MonedaP','TipoCambioP','Monto','NumOperacion','RfcEmisorCtaOrd','NomBancoOrdExt','CtaOrdenante','RfcEmisorCtaBen','CtaBeneficiario','TipoCadPago','CertPago','CadPago','SelloPago']);
        foreach($payment['DoctoRelacionado']??[] as$document)$this->appendDocument($dom,$node,$document,$currency);
        if(!empty($totals['RetencionesP'])||!empty($totals['TrasladosP'])){
            $taxes=$dom->createElement('pago20:ImpuestosP');
            $this->appendTaxGroup($dom,$taxes,'RetencionesP','RetencionP',$totals['RetencionesP']??[],$currency);
            $this->appendTaxGroup($dom,$taxes,'TrasladosP','TrasladoP',$totals['TrasladosP']??[],$currency);
            $node->appendChild($taxes);
        }
        $parent->appendChild($node);
    }

    private function appendDocument(DOMDocument$dom,DOMElement$parent,array$document,string$paymentCurrency):void
    {
        $node=$dom->createElement('pago20:DoctoRelacionado');$currency=strtoupper(trim((string)($document['MonedaDR']??'')));$values=$document;
        $values['EquivalenciaDR']=$currency===$paymentCurrency?'1':$this->documentEquivalence((string)($document['EquivalenciaDR']??''));
        foreach(['ImpSaldoAnt','ImpPagado','ImpSaldoInsoluto']as$name)$values[$name]=$this->moneyForCurrency((string)($document[$name]??''),$currency);
        $this->selectedAttributes($node,$values,['IdDocumento','Serie','Folio','MonedaDR','EquivalenciaDR','NumParcialidad','ImpSaldoAnt','ImpPagado','ImpSaldoInsoluto','ObjetoImpDR']);
        // MetodoDePagoDR is the canonical normalized key; Pagos 2.0 XSD does not
        // serialize it on DoctoRelacionado, but it remains frozen for PDF/audit.
        $tax=$document['ImpuestosDR']??[];
        if(!empty($tax['RetencionesDR'])||!empty($tax['TrasladosDR'])){$taxes=$dom->createElement('pago20:ImpuestosDR');$this->appendTaxGroup($dom,$taxes,'RetencionesDR','RetencionDR',$tax['RetencionesDR']??[],$currency);$this->appendTaxGroup($dom,$taxes,'TrasladosDR','TrasladoDR',$tax['TrasladosDR']??[],$currency);$node->appendChild($taxes);}
        $parent->appendChild($node);
    }

    private function appendTaxGroup(DOMDocument$dom,DOMElement$parent,string$groupName,string$itemName,array$rows,string$currency):void
    {
        if(!$rows)return;$group=$dom->createElement('pago20:'.$groupName);foreach($rows as$row){$node=$dom->createElement('pago20:'.$itemName);foreach(['BaseDR','ImporteDR','BaseP','ImporteP']as$name)if(isset($row[$name]))$row[$name]=$this->moneyForCurrency((string)$row[$name],$currency);$this->attributes($node,$row);$group->appendChild($node);}$parent->appendChild($group);
    }

    private function selectedAttributes(DOMElement$node,array$values,array$names):void{foreach($names as$name)if(isset($values[$name])&&$values[$name]!==null&&trim((string)$values[$name])!=='')$node->setAttribute($name,(string)$values[$name]);}
    private function attributes(DOMElement$node,array$values):void{foreach($values as$key=>$value)if($value!==null&&$value!=='')$node->setAttribute((string)$key,(string)$value);}
    private function documentEquivalence(string$value):string{$value=trim($value);if(!preg_match('/^\d+(?:\.\d{1,10})?$/',$value)||preg_match('/^0+(?:\.0+)?$/',$value))throw new RuntimeException('EquivalenciaDR no tiene un formato decimal valido.');return$value;}
    private function moneyForCurrency(string$value,string$currency):string{if($currency!=='MXN'){if(!preg_match('/^\d+(?:\.\d{1,6})?$/',trim($value)))throw new RuntimeException('Monto no tiene un formato decimal valido.');return trim($value);}$micros=FiscalDecimal::micros($value);if($micros<0)throw new RuntimeException('Monto no puede ser negativo.');$cents=intdiv($micros+5000,10000);return intdiv($cents,100).'.'.str_pad((string)($cents%100),2,'0',STR_PAD_LEFT);}
    private function exchangeRate(string$value):string{$value=trim($value);if(!preg_match('/^\d+(?:\.\d{1,6})?$/',$value))throw new RuntimeException('TipoCambioP no tiene un formato decimal valido.');return$value;}
}
