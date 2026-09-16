<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/Services/Fiscal/Cfdi40/CfdiSemanticValidationException.php';

use App\Services\Fiscal\Cfdi40\CfdiSemanticValidationException;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$validation = [
    'is_valid' => false,
    'validation_level' => 'pre_sign_validation',
    'errors' => ['Totales' => ['SubTotal no coincide.']],
    'warnings' => ['Advertencia controlada.'],
    'checks' => [
        ['group' => 'Totales', 'message' => 'SubTotal no coincide.', 'passed' => false],
    ],
    'api_key' => 'must-not-be-recorded',
    'private_key' => 'must-not-be-recorded',
    'seal' => 'must-not-be-recorded',
];

$failure = new CfdiSemanticValidationException($validation);
$diagnostic = $failure->diagnostic();
$encoded = json_encode($diagnostic, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$assert($failure->getMessage() === 'La validaci?n sem?ntica contiene errores.', 'La excepci?n conserva el mensaje controlado.');
$assert(($diagnostic['semantic_errors'][0]['group'] ?? null) === 'Totales', 'Conserva el grupo de la regla fallida.');
$assert(($diagnostic['semantic_errors'][0]['message'] ?? null) === 'SubTotal no coincide.', 'Conserva el mensaje sem?ntico real.');
$assert(($diagnostic['semantic_warnings'][0] ?? null) === 'Advertencia controlada.', 'Conserva warnings sin convertirlos en errores.');
$assert(($diagnostic['semantic_checks'][0]['passed'] ?? true) === false, 'Conserva el resultado del check.');
$assert(!str_contains($encoded, 'must-not-be-recorded'), 'No transporta secretos ni campos ajenos al contrato permitido.');

$service = file_get_contents(dirname(__DIR__, 2) . '/app/Services/Fiscal/Cfdi40/CfdiPreXmlArtifactService.php');
$generation = file_get_contents(dirname(__DIR__, 2) . '/app/Services/Fiscal/FiscalInvoiceGenerationService.php');
$assert(str_contains($service, 'CFDI_SEMANTIC_VALIDATION_FAILURE'), 'El log distintivo est? configurado.');
$assert(str_contains($service, "'event'=>'semantic_validation_failed'"), 'El detalle se conserva en fiscal_draft_audit.');
$assert(strpos($generation, '->generate($documentId, $userId, true)') < strpos($generation, '->sign('), 'Pre-XML sem?ntico ocurre antes de firma/PAC.');

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Semantic validation diagnostics: OK\n";
