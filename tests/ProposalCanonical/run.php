<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/tests/bootstrap.php';
require_once APPPATH . 'ThirdParty/PHP-Hooks/php-hooks.php';
helper(['plugin', 'general', 'currency']);

$rise = config('Rise');
$rise->app_settings_array['system_file_path'] = 'files/system/';

$passed = $failed = 0;
$assert = static function (bool $value, string $message) use (&$passed, &$failed): void {
    echo ($value ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    $value ? $passed++ : $failed++;
};

$totals = new App\Services\ProposalTotalsService();
$before = $totals->calculate('100', '10', 'percentage', 'before_tax', '16', '3');
$after = $totals->calculate('100', '10', 'percentage', 'after_tax', '16', '0');
$assert($before['total_after_discount'] === '90.000000' && $before['grand_total'] === '107.100000', 'before_tax conserva subtotal, descuento e impuestos múltiples');
$assert($after['discount'] === '11.600000' && $after['grand_total'] === '104.400000', 'after_tax conserva impuestos y grand total');

$item = (object) ['id'=>1,'title'=>'Producto','description'=>'','quantity'=>'1','unit_type'=>'pz','rate'=>'100.000000','total'=>'100.000000','currency_symbol'=>'$','product_image'=>''];
$summary = (object) ['proposal_subtotal'=>'100.000000','discount_total'=>'0.000000','discount_type'=>'before_tax','total_after_discount'=>'100.000000','tax'=>'0.000000','tax2'=>'0.000000','tax_name'=>'IVA','tax_name2'=>'','proposal_total'=>'100.000000','currency_symbol'=>'$'];
$fiscalTable = view('proposals/proposal_parts/proposal_items_table', ['proposal_items'=>[$item],'proposal_total_summary'=>$summary,'table_only'=>true,'show_taxes'=>true,'proposal_item_tax_labels'=>[1=>'Exento']]);
$legacyTable = view('proposals/proposal_parts/proposal_items_table', ['proposal_items'=>[$item],'proposal_total_summary'=>$summary]);
$assert(str_contains($fiscalTable, 'Precio sin impuestos') && str_contains($fiscalTable, 'Impuestos') && str_contains($fiscalTable, 'Exento'), 'placeholder fiscal produce sólo tabla con columnas esperadas');
$tableSource = file_get_contents($root . '/app/Views/proposals/proposal_parts/proposal_items_table.php');
$assert(str_contains($tableSource, 'if ($table_only) { return; }') && str_contains($legacyTable, app_lang('total')), 'tabla fiscal no duplica resumen y legacy conserva resumen');

$helper = file_get_contents($root . '/app/Helpers/general_helper.php');
$controller = file_get_contents($root . '/app/Controllers/Proposals.php');
$converter = file_get_contents($root . '/app/Services/ProposalToInvoiceService.php');
$acceptance = file_get_contents($root . '/app/Services/ProposalAcceptanceService.php');
foreach (['PROPOSAL_ITEMS_WITH_TAXES', 'PROPOSAL_DISCOUNT_ROW', 'PROPOSAL_TOTAL_AFTER_DISCOUNT_ROW', 'PROPOSAL_TAXES', 'PROPOSAL_GRAND_TOTAL'] as $placeholder) {
    $assert(str_contains($helper, $placeholder), "placeholder {$placeholder} está disponible");
}
$assert(str_contains($controller, 'proposal_template_id') && str_contains($controller, 'Plantilla legacy'), 'selección de plantilla es nullable y no infiere históricos');
$assert(str_contains($converter, 'assertTotalsPreserved') && str_contains($converter, "'tax_id' => (int)"), 'conversión copia impuestos y verifica totales');
$assert(str_contains($acceptance, 'FOR UPDATE') && str_contains($acceptance, "'invoice_action' => 'existing'") && str_contains($acceptance, 'transRollback'), 'aceptación conserva lock, idempotencia y rollback');
$assert(!str_contains($converter, 'FiscalStampingService') && !str_contains($helper, 'timbrarConSello'), 'P06 no crea tráfico PAC');

echo "TOTAL PASS={$passed} FAIL={$failed}" . PHP_EOL;
exit($failed ? 1 : 0);
