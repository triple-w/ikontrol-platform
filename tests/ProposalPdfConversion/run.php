<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/tests/bootstrap.php';
require_once APPPATH . 'ThirdParty/PHP-Hooks/php-hooks.php';
helper(['plugin', 'general', 'app_files', 'currency']);

$rise = config('Rise');
$rise->app_settings_array['system_file_path'] = 'files/system/';
$rise->app_settings_array['timeline_file_path'] = 'files/timeline_files/';

$passed = 0;
$failed = 0;
$assert = static function (bool $value, string $message) use (&$passed, &$failed): void {
    echo ($value ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    $value ? $passed++ : $failed++;
};

$pngSource = get_store_item_image_pdf_source('');
$assert(str_starts_with($pngSource, 'data:image/png;base64,'), 'PNG local se resuelve como data URI.');

$rise->app_settings_array['timeline_file_path'] = 'assets/images/';
$jpegSource = get_store_item_image_pdf_source(serialize([['file_name' => 'avatar.jpg']]));
$assert(str_starts_with($jpegSource, 'data:image/jpeg;base64,'), 'JPG local se resuelve con MIME image/jpeg.');
$rise->app_settings_array['timeline_file_path'] = 'files/timeline_files/';

foreach ([
    'png' => [$pngSource],
    'jpeg' => [$jpegSource],
    'sin-imagen' => [''],
    'fuente-invalida' => ['javascript:alert(1)'],
    'varias-imagenes' => [$pngSource, $jpegSource, $pngSource],
] as $case => $sources) {
    $items = [];
    foreach ($sources as $index => $source) {
        $items[] = (object) [
            'product_image' => $source,
            'title' => 'Producto ' . ($index + 1),
            'description' => 'Descripcion',
            'quantity' => '1',
            'unit_type' => 'pza',
            'rate' => '100.00',
            'total' => '100.00',
            'currency_symbol' => '$',
        ];
    }
    $summary = (object) [
        'discount_total' => 0,
        'discount_type' => 'before_tax',
        'proposal_subtotal' => 100 * count($items),
        'proposal_total' => 100 * count($items),
        'tax' => 0,
        'tax2' => 0,
        'tax_name' => '',
        'tax_name2' => '',
        'currency_symbol' => '$',
    ];
    $html = view('proposals/proposal_parts/proposal_items_table', [
        'proposal_items' => $items,
        'proposal_total_summary' => $summary,
        'mode' => 'download',
    ]);

    $pdf = new App\Libraries\Pdf('proposal');
    $pdf->AddPage();
    $pdf->writeHTML($html, true, false, true, false, '');
    $binary = $pdf->Output('proposal-test.pdf', 'S');
    $imageObjects = substr_count($binary, '/Subtype /Image');

    $assert(str_starts_with($binary, '%PDF-'), "Caso {$case}: binario PDF valido.");
    $assert(in_array($case, ['sin-imagen', 'fuente-invalida'], true) ? $imageObjects === 0 : $imageObjects > 0,
        "Caso {$case}: objetos /Image esperados.");
}


$legacyLayout = '<table width="100%"><tbody><tr><!-- IMAGEN DE REFERENCIA -->'
    . '<td width="20%"><img src="/assets/images/image_preview.png"></td>'
    . '<!-- TABLA REAL DE PRODUCTOS --><td width="80%">{PROPOSAL_ITEMS}</td>'
    . '</tr></tbody></table>';
$normalizedLayout = normalize_proposal_items_template_layout($legacyLayout);
$assert($normalizedLayout === '{PROPOSAL_ITEMS}',
    'El wrapper lateral legacy se elimina y PROPOSAL_ITEMS queda a ancho completo.');

$longItems = [];
for ($i = 1; $i <= 14; $i++) {
    $longItems[] = (object) [
        'product_image' => $i % 2 ? $pngSource : $jpegSource,
        'title' => 'Producto ' . $i,
        'description' => str_repeat('Descripcion extensa del producto para validar altura y salto de pagina. ', 5),
        'quantity' => '8',
        'unit_type' => 'PRS',
        'rate' => '1000.00',
        'total' => '8000.00',
        'currency_symbol' => '$',
    ];
}
$longSummary = (object) [
    'discount_total' => 0, 'discount_type' => 'before_tax',
    'proposal_subtotal' => 112000, 'proposal_total' => 112000,
    'tax' => 0, 'tax2' => 0, 'tax_name' => '', 'tax_name2' => '',
    'currency_symbol' => '$',
];
$longHtml = view('proposals/proposal_parts/proposal_items_table', [
    'proposal_items' => $longItems,
    'proposal_total_summary' => $longSummary,
    'mode' => 'download',
]);
$longPdf = new App\Libraries\Pdf('proposal');
$longPdf->AddPage();
$longPdf->writeHTML($longHtml, true, false, true, false, '');
$longPageCount = $longPdf->getNumPages();
$longBinary = $longPdf->Output('proposal-many-items.pdf', 'S');
$assert(str_starts_with($longBinary, '%PDF-') && $longPageCount > 1,
    'Descripcion larga y multiples productos generan saltos de pagina validos.');

$offer = (string) file_get_contents(APPPATH . 'Controllers/Offer.php');
$proposals = (string) file_get_contents(APPPATH . 'Controllers/Proposals.php');
$acceptance = (string) file_get_contents(APPPATH . 'Services/ProposalAcceptanceService.php');
$converter = (string) file_get_contents(APPPATH . 'Services/ProposalToInvoiceService.php');
$layout = (string) file_get_contents(APPPATH . 'Views/proposals/proposal_parts/proposal_items_table.php');
$pdfLibrary = (string) file_get_contents(APPPATH . 'Libraries/Pdf.php');

$assert(str_contains($offer, 'acceptAndConvert(') && !str_contains($offer, '$proposal_data["status"] = "accepted"'),
    'Offer delega aceptacion/conversion al servicio atomico.');
$assert(str_contains($offer, '$name ? (string) $public_key : null'),
    'Aceptacion interna no se valida como enlace publico.');
$assert(str_contains($acceptance, 'FOR UPDATE') && str_contains($acceptance, "'invoice_action' => 'existing'")
    && str_contains($acceptance, "'meta_data'"), 'Aceptacion conserva metadata e idempotencia.');
foreach (['supplier_id', 'cost', 'profit_percentage', 'price_origin', 'fiscal_override_json'] as $field) {
    $assert(str_contains($converter, "'{$field}'"), "Conversion conserva {$field}.");
}
$assert(str_contains($layout, "data:image/(?:png|jpeg);base64")
    && str_contains($layout, '$product_image_source = esc($candidate);'),
    'Partial valida data URI y usa escape compatible con TCPDF.');
$assert(str_contains($proposals, 'prepare_proposal_pdf($proposal_data, $mode)')
    && str_contains($offer, 'prepare_proposal_pdf($proposal_data)'),
    'Descargas interna y publica usan el mismo renderer corregido.');
$assert(str_contains($layout, 'nobr="true"') && str_contains($layout, '$image_column_width'),
    'Layout vertical existente permanece compartido.');
$assert(str_contains($pdfLibrary, "pdf_type === 'proposal'") && str_contains($pdfLibrary, '_rebuild_proposal_html'),
    'Proposal conserva su tratamiento PDF existente.');

echo PHP_EOL . "{$passed} passed, {$failed} failed." . PHP_EOL;
exit($failed ? 1 : 0);
