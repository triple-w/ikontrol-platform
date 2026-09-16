<?php
declare(strict_types=1);
namespace App\Services\Fiscal\Cfdi40;
use App\Domain\Fiscal\Cfdi40\{CfdiDocument,CfdiParty,CfdiConcept,CfdiConceptTax,CfdiTaxSummary};
use App\Models\Fiscal\Fiscal_documents_model;
use RuntimeException;
final class CfdiDraftMapper{
 public function map(int$id):CfdiDocument{$snapshot=(new Fiscal_documents_model())->complete($id);if(!$snapshot)throw new RuntimeException('La preparación fiscal no existe.');$d=$snapshot['document'];if($d->status!=='locked')throw new RuntimeException('Sólo una preparación fiscal cerrada puede generar Pre-XML.');if($d->document_type!=='income')throw new RuntimeException('Sólo se admite CFDI de ingreso.');if(!$snapshot['issuer']||!$snapshot['receiver']||!$snapshot['items'])throw new RuntimeException('El snapshot fiscal está incompleto.');
  $concepts=[];foreach($snapshot['items']as$i){$taxes=[];foreach($i->taxes as$t)$taxes[]=new CfdiConceptTax((string)$t->tax_code,(string)$t->tax_type,(string)$t->factor_type,$t->rate_or_quota===null?null:(string)$t->rate_or_quota,(string)$t->taxable_base,(string)$t->amount);$concepts[]=new CfdiConcept((array)$i,$taxes);}
  $totals=[];foreach($snapshot['tax_totals']as$t)$totals[]=new CfdiTaxSummary((string)$t->tax_code,(string)$t->tax_type,(string)$t->factor_type,$t->rate_or_quota===null?null:(string)$t->rate_or_quota,(string)$t->taxable_base,(string)$t->amount);
  return new CfdiDocument((array)$d,new CfdiParty((array)$snapshot['issuer']),new CfdiParty((array)$snapshot['receiver']),$concepts,$totals);
 }
}
