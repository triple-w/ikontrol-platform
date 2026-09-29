<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/tests/bootstrap.php';

use App\Services\CommercialMarginService;
use App\Services\Fiscal\FiscalRuntimeContext;
use App\Services\ProposalTotalsService;

$passed = $failed = 0;
$assert = static function (bool $value, string $message) use (&$passed, &$failed): void {
    echo ($value ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    $value ? $passed++ : $failed++;
};

$totals = new ProposalTotalsService();
$zero = $totals->calculate('100.123456', '0', 'fixed_amount', 'before_tax', '16', '0');
$assert($zero['subtotal'] === '100.123456' && $zero['discount'] === '0.000000', 'importe decimal y descuento cero se normalizan');
$assert($zero['tax'] === '16.019753' && $zero['grand_total'] === '116.143209', 'redondeo fiscal de seis decimales es determinista');

$before = $totals->calculate('100', '10', 'percentage', 'before_tax', '16', '3');
$assert($before['discount'] === '10.000000' && $before['total_after_discount'] === '90.000000', 'descuento antes de impuestos usa subtotal');
$assert($before['tax'] === '14.400000' && $before['tax2'] === '2.700000' && $before['grand_total'] === '107.100000', 'múltiples impuestos usan base descontada');

$after = $totals->calculate('100', '10', 'percentage', 'after_tax', '16', '0');
$assert($after['tax'] === '16.000000' && $after['discount'] === '11.600000' && $after['grand_total'] === '104.400000', 'descuento posterior conserva base fiscal y descuenta el total');

$fixed = $totals->calculate('100', '20', 'fixed_amount', 'after_tax', '16', '0');
$assert($fixed['total_after_discount'] === '96.000000' && $fixed['grand_total'] === '96.000000', 'descuento fijo posterior conserva contrato de grand total');
try { $totals->calculate('100', '101', 'fixed_amount', 'before_tax', '0', '0'); $assert(false, 'descuento inválido se rechaza'); }
catch (InvalidArgumentException) { $assert(true, 'descuento inválido se rechaza'); }

$margin = new CommercialMarginService();
$assert($margin->priceOrigin('cost_margin', '650', '45', '1181.818182') === 'cost_margin', 'origen costo/margen conserva contrato decimal');
$assert($margin->priceOrigin('cost_margin', '650', '45', '1181.81') === 'manual', 'origen manual evita atribución de costo inexacta');
$supplierHistory = file_get_contents($root . '/app/Services/SupplierCostHistoryService.php');
$assert(str_contains($supplierHistory, "snapshotDocument('proposal'") && str_contains($supplierHistory, 'saveManual('), 'historial formal y manual permanecen en contratos separados');

$integration = FiscalRuntimeContext::from((object) ['runtimeMode'=>'integration','environment'=>'development','pacAdapter'=>'timbradorxpress','allowRealPac'=>true], (object) ['environment'=>'sandbox']);
$assert($integration['coherent'] && $integration['transport_environment'] === 'sandbox', 'contexto fiscal distingue desarrollo de sandbox');
$production = FiscalRuntimeContext::from((object) ['runtimeMode'=>'production','environment'=>'production','pacAdapter'=>'timbradorxpress','allowRealPac'=>false], (object) ['environment'=>'production']);
$assert($production['coherent'] && !$production['real_pac_allowed'], 'contexto no habilita PAC aunque el ambiente sea coherente');
$mismatch = FiscalRuntimeContext::from((object) ['runtimeMode'=>'integration','environment'=>'development','pacAdapter'=>'timbradorxpress','allowRealPac'=>true], (object) ['environment'=>'production']);
$assert(!$mismatch['coherent'], 'contexto detecta mezcla lógica/transport');

$model = file_get_contents($root . '/app/Models/Proposals_model.php');
$status = file_get_contents($root . '/app/Services/Fiscal/FiscalIntegrationStatusService.php');
$assert(str_contains($model, 'ProposalTotalsService') && !str_contains($model, 'number_format($result->discount_total'), 'consumidor principal usa el calculador compartido');
$assert(str_contains($status, 'FiscalRuntimeContext::from') && str_contains($status, 'environment_contract_coherent'), 'diagnóstico fiscal consume contrato de ambiente');
$assert(!str_contains($status, '->create('), 'fixture no crea ni transporta PAC');

echo "TOTAL PASS={$passed} FAIL={$failed}" . PHP_EOL;
exit($failed ? 1 : 0);
