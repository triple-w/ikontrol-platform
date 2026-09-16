<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Domain\Fiscal\Cfdi40\{CfdiConcept, CfdiConceptTax, CfdiDocument, CfdiParty, CfdiTaxSummary};
use App\Services\Fiscal\Cfdi40\{CfdiCurrencyTotalsCalculator, CfdiSemanticValidator, CfdiXmlBuilder};

$passed = 0;
$failed = 0;
$assert = static function (bool $ok, string $message) use (&$passed, &$failed): void {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    $ok ? $passed++ : $failed++;
};

$totals = new CfdiCurrencyTotalsCalculator();
$aquashoes = $totals->fromLines([[
    'subtotal' => '4545.454550',
    'discount' => '0',
    'transferred' => '727.272728',
    'withheld' => '0',
]]);
$assert($aquashoes === [
    'subtotal' => '4545.45',
    'discount' => '0.00',
    'transferred' => '727.27',
    'withheld' => '0.00',
    'total' => '5272.72',
], 'Aquashoes derives Total from the same two-decimal CFDI components.');
$canonical = new App\Services\Fiscal\FiscalCanonicalCalculationService();
$canonicalAquashoes = $canonical->calculate([[
    'sale_id'=>10, 'subtotal'=>'4545.454550', 'discount'=>'0',
    'snapshot'=>['transferred_total'=>'727.272728','withheld_total'=>'0'],
]]);
$assert($canonicalAquashoes['totals'] === $aquashoes && $canonicalAquashoes['allocations'][0]['allocated_total'] === '5272.72', 'Review, draft and allocation consume one canonical result.');
$productionCase = $canonical->calculate([
 ['sale_id'=>8,'subtotal'=>'119729.265000','discount'=>'0','snapshot'=>['transferred_total'=>'19156.682500','withheld_total'=>'0']],
 ['sale_id'=>8,'subtotal'=>'119729.265000','discount'=>'0','snapshot'=>['transferred_total'=>'19156.682500','withheld_total'=>'0']],
]);
$assert($productionCase['totals']===['subtotal'=>'239458.54','discount'=>'0.00','transferred'=>'38313.36','withheld'=>'0.00','total'=>'277771.90']&&$productionCase['allocations'][0]['allocated_total']==='277771.90','Production rounding case is identical in review, draft, allocation and document.');
$invoice15Case = $canonical->calculate([
 ['sale_id'=>15,'subtotal'=>'16363.500000','discount'=>'0','snapshot'=>['transferred_total'=>'2618.160000','withheld_total'=>'0']],
 ['sale_id'=>15,'subtotal'=>'49230.769240','discount'=>'0','snapshot'=>['transferred_total'=>'7876.923078','withheld_total'=>'0']],
 ['sale_id'=>15,'subtotal'=>'74285.714320','discount'=>'0','snapshot'=>['transferred_total'=>'11885.714291','withheld_total'=>'0']],
 ['sale_id'=>15,'subtotal'=>'28619.999976','discount'=>'0','snapshot'=>['transferred_total'=>'4579.199996','withheld_total'=>'0']],
]);
$assert($invoice15Case['totals']===['subtotal'=>'168499.98','discount'=>'0.00','transferred'=>'26959.99','withheld'=>'0.00','total'=>'195459.97']&&$invoice15Case['allocations'][0]['allocated_total']==='195459.97','Four-line production fixture has one result for sale, draft, document, allocation and XML input.');

$standard = $totals->fromLines([[
    'subtotal' => '14286.000000',
    'discount' => '0',
    'transferred' => '2285.760000',
    'withheld' => '0',
]]);
$assert($standard['total'] === '16571.76', '100 x 142.86 plus IVA 16% remains stable.');

$multiple = $totals->fromLines([
    ['subtotal'=>'0.335000','discount'=>'0','transferred'=>'0.053600','withheld'=>'0'],
    ['subtotal'=>'0.335000','discount'=>'0','transferred'=>'0.053600','withheld'=>'0'],
    ['subtotal'=>'0.335000','discount'=>'0','transferred'=>'0.053600','withheld'=>'0'],
]);
$assert($multiple === [
    'subtotal'=>'1.02','discount'=>'0.00','transferred'=>'0.15','withheld'=>'0.00','total'=>'1.17',
], 'Several fractional-cent lines close from their serialized values.');



$allocationSubtotal = App\Services\Fiscal\FiscalDecimal::subtract($multiple['subtotal'], $multiple['discount']);
$allocationTax = App\Services\Fiscal\FiscalDecimal::subtract($multiple['transferred'], $multiple['withheld']);
$allocationTotal = App\Services\Fiscal\FiscalDecimal::add($allocationSubtotal, $allocationTax);
$assert(App\Services\Fiscal\FiscalDecimal::micros($allocationTotal) === App\Services\Fiscal\FiscalDecimal::micros($multiple['total']), 'Three-line allocation equals the canonical document total exactly.');
$manyLines = [];
for ($i=0; $i<100; $i++) $manyLines[]=['sale_id'=>20,'subtotal'=>'0.335000','discount'=>'0','snapshot'=>['transferred_total'=>'0.053600','withheld_total'=>'0']];
$many = $canonical->calculate($manyLines);
$assert($many['totals']['total']==='39.00' && $many['allocations'][0]['allocated_total']==='39.00', 'Many fractional products cannot accumulate an allocation remainder.');

$flowSource=file_get_contents(APPPATH.'Services/Fiscal/FiscalInvoiceFlowService.php');
$closePosition=strpos($flowSource,'posterior a timbrado CFDI');
$stampPosition=strpos($flowSource,"==='stamped'");
$assert($closePosition!==false&&$stampPosition!==false&&$closePosition>$stampPosition&&!str_contains($flowSource,'al confirmar facturaciÃ³n'),'Sale closes only inside the stamped-success branch.');
$preXmlSource=file_get_contents(APPPATH.'Services/Fiscal/Cfdi40/CfdiPreXmlArtifactService.php');
$assert(str_contains($preXmlSource,'storeDiagnosticXml')&&str_contains($preXmlSource,"'validation_status'=>'semantic_error'"),'Semantic failures retain a non-signable diagnostic Pre-XML.');

$workflowSource = file_get_contents(APPPATH . 'Services/Fiscal/FiscalDraftWorkflowService.php');
$assert(
    str_contains($workflowSource, 'FiscalCanonicalCalculationService')
    && str_contains($workflowSource, 'allocations'),
    'Draft headers and per-sale allocations use the shared currency calculator.'
);

$discount = $totals->fromLines([[
    'subtotal'=>'100.005000','discount'=>'10.005000','transferred'=>'14.400000','withheld'=>'0',
]]);
$assert($discount['total'] === '104.40', 'Discount plus IVA closes exactly.');

$mixed = $totals->fromLines([[
    'subtotal'=>'100.000000','discount'=>'0','transferred'=>'16.000000','withheld'=>'10.000000',
]]);
$assert($mixed['total'] === '106.00', 'Transfer plus withholding closes exactly.');

$partyIssuer = new CfdiParty(['rfc'=>'AAA010101AAA','legal_name'=>'EMISOR','tax_regime_code'=>'601']);
$partyReceiver = new CfdiParty(['rfc'=>'XAXX010101000','legal_name'=>'PUBLICO GENERAL','tax_regime_code'=>'616','fiscal_postal_code'=>'06000','cfdi_use_code'=>'S01']);
$tax = new CfdiConceptTax('002','transferred','Tasa','0.160000','4545.454550','727.272728');
$concept = new CfdiConcept([
    'product_service_code'=>'53121600','quantity'=>'10.000000','unit_code'=>'H87',
    'description'=>'Aquashoes Unisex','unit_value'=>'454.545455',
    'gross_amount'=>'4545.454550','discount'=>'0.000000','tax_object_code'=>'02',
], [$tax]);
$document = new CfdiDocument([
    'status'=>'locked','document_type'=>'income','series'=>'T','folio'=>'4',
    'issue_date'=>'2026-09-02 10:00:00','currency_code'=>'MXN',
    'payment_form_code'=>'01','payment_method_code'=>'PUE','export_code'=>'01',
    'expedition_postal_code'=>'06000','subtotal'=>'4545.45','discount'=>'0.00',
    'transferred_tax_total'=>'727.27','withheld_tax_total'=>'0.00','total'=>'5272.72',
], $partyIssuer, $partyReceiver, [$concept], [
    new CfdiTaxSummary('002','transferred','Tasa','0.160000','4545.45','727.27'),
]);
$semantic = (new CfdiSemanticValidator())->validate($document);
$assert($semantic['is_valid'], 'Aquashoes passes semantic validation with canonical header totals.');
$xml=(new CfdiXmlBuilder())->build($document);
$assert(str_contains($xml,'SubTotal="4545.45"')&&str_contains($xml,'TotalImpuestosTrasladados="727.27"')&&str_contains($xml,'Total="5272.72"'),'Aquashoes XML serializes the same canonical equation.');

$fixtureAmounts=['18939.39','49292.31','74285.71','28619.76'];
$fixtureTaxes=['3030.30','7886.77','11885.71','4579.16'];
$fixtureLines=[];$fixtureConcepts=[];
foreach($fixtureAmounts as $i=>$amount){
    $fixtureLines[]=['subtotal'=>$amount,'discount'=>'0','transferred'=>$fixtureTaxes[$i],'withheld'=>'0'];
    $fixtureConcepts[]=new CfdiConcept([
        'product_service_code'=>'53121600','quantity'=>'1.000000','unit_code'=>'H87',
        'description'=>'Producto '.($i+1),'unit_value'=>$amount,'gross_amount'=>$amount,
        'discount'=>'0.00','tax_object_code'=>'02',
    ],[new CfdiConceptTax('002','transferred','Tasa','0.160000',$amount,$fixtureTaxes[$i])]);
}
$fixtureTotals=$totals->fromLines($fixtureLines);
$fixtureDocument=new CfdiDocument([
    'status'=>'locked','document_type'=>'income','series'=>'T','folio'=>'9',
    'issue_date'=>'2026-09-02 10:00:00','currency_code'=>'MXN','payment_form_code'=>'01',
    'payment_method_code'=>'PUE','export_code'=>'01','expedition_postal_code'=>'06000',
    'subtotal'=>$fixtureTotals['subtotal'],'discount'=>$fixtureTotals['discount'],
    'transferred_tax_total'=>$fixtureTotals['transferred'],'withheld_tax_total'=>$fixtureTotals['withheld'],
    'total'=>$fixtureTotals['total'],
],$partyIssuer,$partyReceiver,$fixtureConcepts,[new CfdiTaxSummary('002','transferred','Tasa','0.160000','171137.17','27381.94')]);
$fixtureXml=(new CfdiXmlBuilder())->build($fixtureDocument);
$dom=new DOMDocument();$dom->loadXML($fixtureXml);$xpath=new DOMXPath($dom);$xpath->registerNamespace('cfdi','http://www.sat.gob.mx/cfd/4');
$sumAttribute=static function(DOMNodeList $nodes,string $attribute):string{$sum='0';foreach($nodes as $node)$sum=App\Services\Fiscal\FiscalDecimal::add($sum,(string)$node->attributes->getNamedItem($attribute)->nodeValue);return(new App\Services\Fiscal\FiscalDecimalCalculator())->money($sum);};
$root=$xpath->query('/cfdi:Comprobante')->item(0);
$conceptSum=$sumAttribute($xpath->query('//cfdi:Concepto'),'Importe');
$conceptTaxSum=$sumAttribute($xpath->query('//cfdi:Concepto/cfdi:Impuestos/cfdi:Traslados/cfdi:Traslado'),'Importe');
$globalTaxSum=$sumAttribute($xpath->query('/cfdi:Comprobante/cfdi:Impuestos/cfdi:Traslados/cfdi:Traslado'),'Importe');
$xmlExpected=(new App\Services\Fiscal\FiscalDecimalCalculator())->money(App\Services\Fiscal\FiscalDecimal::add((string)$root->getAttribute('SubTotal'),$globalTaxSum));
$assert($fixtureTotals===['subtotal'=>'171137.17','discount'=>'0.00','transferred'=>'27381.94','withheld'=>'0.00','total'=>'198519.11'],'Exact production PreXML fixture uses serialized operands as canonical totals.');
$assert($conceptSum===(string)$root->getAttribute('SubTotal')&&$conceptTaxSum===$globalTaxSum&&$globalTaxSum===(string)$xpath->query('/cfdi:Comprobante/cfdi:Impuestos')->item(0)->attributes->getNamedItem('TotalImpuestosTrasladados')->nodeValue&&$xmlExpected===(string)$root->getAttribute('Total'),'Final PreXML is mathematically self-consistent when parsed back from XML.');
$assert((new CfdiSemanticValidator())->validate($fixtureDocument)['is_valid'],'Semantic validator consumes the same serialized monetary operands.');
$inclusive = $totals->fromAggregates('86.21','0','13.79','0');
$exclusive = $totals->fromAggregates('100.00','0','16.00','0');
$assert($inclusive['total']==='100.00', 'Resolved tax-inclusive components close exactly.');
$assert($exclusive['total']==='116.00', 'Resolved tax-exclusive components close exactly.');

echo PHP_EOL . $passed . ' passed, ' . $failed . ' failed.' . PHP_EOL;
exit($failed ? 1 : 0);
