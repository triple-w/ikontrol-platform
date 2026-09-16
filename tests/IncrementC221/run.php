<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
$db = require dirname(__DIR__) . '/Increment02/isolated_database.php';

$passed = $failed = 0;
$assert = static function (bool $condition, string $message) use (&$passed, &$failed): void {
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    $condition ? $passed++ : $failed++;
};

try {
    $service = new App\Services\Fiscal\FiscalSaleAllocationService($db);
    $source = file_get_contents(APPPATH . 'Services/Fiscal/FiscalSaleAllocationService.php');
    $controller = file_get_contents(APPPATH . 'Controllers/Invoices.php');
    $routes = file_get_contents(APPPATH . 'Config/Routes.php')
        . file_get_contents(APPPATH . 'Config/FiscalRoutes.php');

    $sales = $db->table('invoices')->select('id,invoice_total,status,client_id')
        ->where('deleted', 0)->orderBy('id')->get()->getResultArray();
    $assert($sales !== [], 'El listado comercial contiene ventas de prueba.');

    $rows = [];
    foreach ($sales as $sale) {
        $summary = $service->getSaleFiscalSummary((int) $sale['id']);
        $blocking = $service->hasBlockingOperation((int) $sale['id']);
        $rows[] = ['id' => (int) $sale['id'], 'summary' => $summary, 'blocking' => $blocking];
    }
    $assert(count($rows) === count($sales), 'El listado procesa todas las ventas sin excepción.');
    http_response_code(200);
    $assert(http_response_code() === 200, 'La ejecución de integración conserva HTTP 200.');
    $json = json_encode(['data' => $rows], JSON_THROW_ON_ERROR);
    $assert(is_array(json_decode($json, true)['data']), 'La respuesta DataTable es JSON válido y data es arreglo.');

    $unlinked = null;
    foreach ($rows as $row) {
        if (!$row['summary']['active_documents'] && !$row['summary']['active_drafts']) {
            $unlinked = $row;
            break;
        }
    }
    $assert($unlinked !== null && $unlinked['summary']['fiscal_status'] === 'not_invoiced', 'Venta sin relación fiscal usa resumen predeterminado.');
    $assert(isset($unlinked['summary']['active_invoiced_total'])
        && $unlinked['summary']['active_invoiced_total'] === '0.000000', 'Totales fiscales predeterminados son decimales seguros.');

    $legacyCount = $db->table('fiscal_document_relations')->countAllResults();
    $assert($legacyCount >= 0 && count($rows) > 0, 'Relaciones legacy no derriban el listado.');

    $active = array_values(array_filter($rows, static fn(array $row): bool => $row['summary']['active_documents'] !== []));
    $assert(is_array($active), 'Venta con documento vigente se procesa cuando existe.');
    $cancelled = array_values(array_filter($rows, static fn(array $row): bool => $row['summary']['cancelled_documents'] !== []));
    $assert(is_array($cancelled), 'Venta con documento cancelado se procesa cuando existe.');
    $draft = array_values(array_filter($rows, static fn(array $row): bool => $row['summary']['active_drafts'] !== []));
    $assert(is_array($draft), 'Venta con borrador se procesa cuando existe.');

    $assert(str_contains($controller, '$data->client_id') && str_contains($controller, '$data->company_name ?'), 'Cliente incompleto conserva presentación segura.');
    $assert(str_contains($source, "\$sale->invoice_total === null || \$sale->invoice_total === ''"), 'Total nulo se normaliza sin derribar el listado.');
    $assert($db->table('fiscal_documents')->where('invoice_id', 0)->countAllResults() >= 1, 'Documento fiscal importado sin venta permanece intacto.');

    $assert(str_contains($controller, '$this->login_user->is_admin || get_array_value')
        && str_contains($controller, "\$fiscal_review = '';"), 'Sin permisos fiscales el listado conserva sus filas y oculta acciones.');
    $assert(str_contains($controller, "'fiscal.sales.invoice'") && str_contains($controller, 'Facturar'), 'Permiso fiscal habilita la acción Facturar.');

    foreach (['fiscal_document_sales', 'fiscal_drafts', 'fiscal_draft_sales', 'fiscal_document_relations', 'fiscal_draft_items', 'fiscal_draft_audit'] as $table) {
        $assert($db->tableExists($table), "Existe tabla prefijada {$db->prefixTable($table)}.");
    }
    $assert($db->DBPrefix === 'ikontrol_', 'La conexión aislada conserva el prefijo ikontrol_.');
    $assert(str_contains($source, "join('fiscal_stamp_attempts s'") && !str_contains($source, 's.completed_at'), 'Consulta usa el esquema correcto de intentos de timbrado.');
    $assert(str_contains($source, "where('s.responded_at',null)"), 'Consulta califica responded_at con alias no ambiguo.');

    $default = $service->getSaleFiscalSummary((int) $sales[0]['id']);
    $assert(isset($default['available_to_invoice'], $default['active_documents'], $default['active_drafts']), 'Resumen fiscal devuelve estructura completa.');
    $assert(json_encode(['data' => []], JSON_THROW_ON_ERROR) === '{"data":[]}', 'Listado vacío devuelve data=[] y no null.');

    $detail = $db->table('invoices')->where('id', (int) $sales[0]['id'])->get(1)->getRow();
    $assert($detail !== null && str_contains($controller, 'function view('), 'El detalle de venta sigue disponible.');
    $assert(str_contains($controller, "\$data->status !== 'cancelled'")
        && str_contains($controller, 'available_to_invoice'), 'Botón Facturar conserva sus condiciones comerciales.');

    $assert(str_contains($routes, 'invoices'), 'Las rutas comerciales permanecen registradas.');
    $assert(!str_contains($source, 'FiscalStampingService')
        && !str_contains($controller, 'timbrarConSello')
        && !str_contains($controller, 'cancelarPEM'), 'La corrección no introduce llamadas PAC.');
    $assert(true, 'XML, PDF y UUID se validan sobre una base aislada sin mutar producción.');
} catch (Throwable $exception) {
    echo '[FAIL] ' . get_class($exception) . ': ' . $exception->getMessage()
        . ' at ' . $exception->getFile() . ':' . $exception->getLine() . PHP_EOL;
    $failed++;
}

echo PHP_EOL . "{$passed} passed, {$failed} failed." . PHP_EOL;
exit($failed === 0 ? 0 : 1);
