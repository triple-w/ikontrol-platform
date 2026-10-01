<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/tests/bootstrap.php';

use CodeIgniter\Security\Exceptions\SecurityException;
use Config\Services;

$passed = $failed = 0;
$assert = static function (bool $condition, string $message) use (&$passed, &$failed): void {
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    $condition ? $passed++ : $failed++;
};

$routes = Services::routes(false);
require $root . '/app/Config/Routes.php';
$getRoutes = $routes->getRoutes('GET');
$postRoutes = $routes->getRoutes('POST');

$match = static function (array $registered, string $uri): ?array {
    foreach ($registered as $pattern => $handler) {
        if (@preg_match('#^' . $pattern . '$#u', $uri) === 1) {
            return ['pattern' => $pattern, 'handler' => $handler];
        }
    }
    return null;
};
$hasCsrf = static function (array $route) use ($routes): bool {
    return in_array('csrf', $routes->getFiltersForRoute($route['pattern'], 'POST'), true);
};

$assert($match($getRoutes, 'proposals/view/7') !== null, 'GET de lectura Proposal permanece disponible.');
$assert($match($getRoutes, 'suppliers/view/7') !== null, 'GET de lectura Supplier permanece disponible.');
$assert($match($getRoutes, 'warehouses/transfers/view/7') !== null, 'GET de lectura Warehouse permanece disponible.');
$assert($match($getRoutes, 'payment_complements/edit/7') !== null, 'GET de lectura de complemento permanece disponible.');
$assert($match($getRoutes, 'fiscal/stamping/xml/download/7') !== null && $match($getRoutes, 'fiscal/documents/7/pdf/download') !== null, 'Descargas XML/PDF son rutas GET puras.');
$assert(config('Routing')->autoRoute === false, 'Auto-routing del framework permanece deshabilitado.');

$getMutations = [
    'proposals/update_proposal_status/7/accepted',
    'offer/update_proposal_status/7/public-key/accepted',
    'suppliers/manual-cost/save',
    'warehouses/movements/7/confirm',
    'payment_complements/7/external-documents',
    'payment_complements/7/stamp',
    'credit_notes/7/stamp',
    'fiscal/stamping/reconcile',
    'fiscal/documents/7/pdf/regenerate',
];
$assert(array_reduce($getMutations, static fn (bool $ok, string $uri): bool => $ok && $match($getRoutes, $uri) === null, true), 'GET no resuelve acciones mutantes de P06-P12.');

$postMutations = [
    'proposals/update_proposal_status/7/accepted',
    'offer/update_proposal_status/7/public-key/accepted',
    'suppliers/manual-cost/save',
    'warehouses/movements/7/confirm',
    'warehouses/transfers/7/receive',
    'payment_complements/7/external-documents',
    'payment_complements/7/external-documents/9/remove',
    'payment_complements/7/fiscal-snapshot',
    'payment_complements/7/stamp',
    'credit_notes/7/stamp',
    'fiscal/stamping/stamp',
    'fiscal/stamping/reconcile',
    'fiscal/invoices/cancel',
    'fiscal/documents/7/pdf/regenerate',
];
$resolvedPost = array_map(static fn (string $uri): ?array => $match($postRoutes, $uri), $postMutations);
$assert(!in_array(null, $resolvedPost, true), 'Las mutaciones críticas resuelven únicamente por POST explícito.');
$assert(array_reduce($resolvedPost, static fn (bool $ok, ?array $route): bool => $ok && $route !== null && $hasCsrf($route), true), 'Toda mutación crítica tiene filtro CSRF en la colección real.');

$scopedPrefixes = ['proposals/', 'proposal_templates/', 'offer/', 'suppliers/', 'warehouses/', 'payment_complements/', 'credit_notes/', 'fiscal/'];
$unfiltered = [];
foreach ($postRoutes as $pattern => $handler) {
    $scoped = false;
    foreach ($scopedPrefixes as $prefix) {
        if (str_starts_with($pattern, $prefix)) {$scoped = true; break;}
    }
    if ($scoped && !in_array('csrf', $routes->getFiltersForRoute($pattern, 'POST'), true)) {
        $unfiltered[] = $pattern;
    }
}
$assert($unfiltered === [], 'No queda POST P06-P12 sin filtro CSRF.');

$routeSource = file_get_contents($root . '/app/Config/Routes.php');
$proposalSource = file_get_contents($root . '/app/Controllers/Proposals.php');
$proposalUi = file_get_contents($root . '/app/Views/proposals/proposal_info.php') . file_get_contents($root . '/app/Views/proposals/proposal_preview.php');
$supplierSource = file_get_contents($root . '/app/Controllers/Suppliers.php');
$warehouseSource = file_get_contents($root . '/app/Controllers/Warehouse_transfers.php') . file_get_contents($root . '/app/Controllers/Warehouse_logistics.php');
$paymentSource = file_get_contents($root . '/app/Controllers/Payment_complements.php');
$stampingSource = file_get_contents($root . '/app/Controllers/Fiscal/Stamping.php');
$cancellationSource = file_get_contents($root . '/app/Controllers/Payment_complement_cancellations.php');
$rolesSource = file_get_contents($root . '/app/Controllers/Roles.php');
$securitySource = file_get_contents($root . '/app/Controllers/Security_Controller.php');

$assert(str_contains($routeSource, '$p13_explicit_controllers') && str_contains($routeSource, "'Proposal_templates', 'Offer', 'Suppliers'") && str_contains($routeSource, "'Payment_complements', 'Payment_complement_cancellations', 'Credit_notes'"), 'El catch-all excluye los controladores raíz reconciliados.');
$assert(str_contains($proposalSource, 'proposal.accept_and_convert') && str_contains($proposalSource, 'app_redirect("forbidden")'), 'Conversión Proposal exige permiso específico al personal.');
$assert(str_contains($proposalUi, 'can_accept_and_convert'), 'La UI no ofrece aceptar/convertir a personal sin permiso.');
$assert(str_contains($supplierSource, "guard('supplier_costs_edit')"), 'Costo manual Supplier exige permiso de operación.');
$assert(str_contains($warehouseSource, "guard('warehouse_movements_create')") && str_contains($warehouseSource, "guard('warehouse_transfers_receive')"), 'Movimientos y recepción Warehouse conservan permisos separados.');
$assert(str_contains($paymentSource, "guard('fiscal.drafts.create')") && str_contains($paymentSource, "guard('fiscal.drafts.edit')") && str_contains($paymentSource, "guard('fiscal.drafts.discard')"), 'Complementos separan crear, editar y descartar de timbrar.');
$assert(str_contains($paymentSource, "allowed('fiscal_stamp_sandbox')") && str_contains($paymentSource, 'No tiene permiso para timbrar'), 'Permiso de lectura no habilita timbrado de complementos.');
$assert(str_contains($stampingSource, "guardDocument(\$id, 'fiscal_stamp_status')") && str_contains($stampingSource, "'fiscal_stamp_reconcile'") && str_contains($stampingSource, 'canAccessFiscalDocument'), 'Estado, retry/reconciliación y artefactos verifican permiso y documento.');
$assert(str_contains($cancellationSource, "guard('fiscal_invoices_cancel')") && str_contains($cancellationSource, "guard('fiscal_status_query')"), 'Cancelar y consultar cancelación usan permisos distintos.');
$assert(str_contains($stampingSource, "['fiscal_stamped_xml_view','fiscal_invoices_download_xml']") && str_contains($stampingSource, "['fiscal_pdf_download','fiscal_invoices_download_pdf']"), 'Descargas XML/PDF exigen capacidades explícitas.');
$assert(str_contains($rolesSource, 'proposal.accept_and_convert') && str_contains($rolesSource, 'supplier_costs_edit') && str_contains($rolesSource, 'warehouse_transfers_receive') && str_contains($rolesSource, 'fiscal_stamp_reconcile'), 'El registro de roles conserva las capacidades transversales utilizadas.');
$assert(str_contains($securitySource, 'protected function can_view_invoices'), 'El acceso fiscal por documento reutiliza la política de invoices de Security_Controller.');

$request = service('request');
$request->setMethod('POST');
$request->setGlobal('post', []);
$rejected = false;
try {
    service('security')->verify($request);
} catch (SecurityException) {
    $rejected = true;
}
$assert($rejected, 'POST sin token CSRF es rechazado por el framework.');

$request->setGlobal('post', [csrf_token() => csrf_hash()]);
$accepted = true;
try {
    service('security')->verify($request);
} catch (SecurityException) {
    $accepted = false;
}
$assert($accepted, 'POST con token CSRF válido es aceptado por el framework.');

echo PHP_EOL . $passed . ' passed, ' . $failed . ' failed. No database or PAC accessed.' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
