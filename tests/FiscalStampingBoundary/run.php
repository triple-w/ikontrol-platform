<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
$read = static fn(string $path): string => (string) file_get_contents($root . str_replace('/', DIRECTORY_SEPARATOR, $path));
$pass = 0;
$fail = 0;
$assert = static function (bool $condition, string $message) use (&$pass, &$fail): void {
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    $condition ? $pass++ : $fail++;
};

$draftStamping = $read('app/Services/Fiscal/FiscalDraftStampingService.php');
$allocation = $read('app/Services/Fiscal/FiscalSaleAllocationService.php');
$pac = $read('app/Services/Fiscal/Pac/FiscalStampingService.php');
$flow = $read('app/Services/Fiscal/FiscalInvoiceFlowService.php');
$lifecycle = $read('app/Services/Sales/SaleLifecycleService.php');
$controller = $read('app/Controllers/Invoices.php');

$gate = strpos($draftStamping, 'validateDraftDocumentConsistency($draftId, $documentId)');
$sign = strpos($draftStamping, '$this->signer->sign(');
$pacCall = strpos($draftStamping, '$this->stamping->stamp(');
$assert($gate !== false && $sign !== false && $pacCall !== false && $gate < $sign && $gate < $pacCall,
    'La igualdad draft/document/allocations se valida antes de firma y PAC.');
$assert(str_contains($allocation, '$draftNetSubtotal = $draftSubtotal - $draftDiscount')
    && str_contains($allocation, '$documentNetSubtotal = $documentSubtotal - $documentDiscount')
    && str_contains($allocation, "throw new RuntimeException('FISCAL_DOCUMENT_ALLOCATION_TOTAL_MISMATCH')"),
    'La compuerta compara componentes exactos y contempla descuento sin tolerancia.');
$assert(str_contains($pac, 'persistedStamp && trim')
    && str_contains($pac, "true, 'stamped_local_reconciliation_pending'")
    && str_contains($pac, "'success_local_reconciliation_pending'"),
    'Un UUID persistido convierte fallos locales posteriores en conciliacion pendiente, no en fallo fiscal.');
$assert(str_contains($draftStamping, "where('fiscal_document_id'")
    && str_contains($draftStamping, 'return $this->completeStampedLocally('),
    'El reintento con stamp/UUID existente completa trabajo local sin volver al PAC.');
$assert(str_contains($flow, "result['success']") && str_contains($flow, "result['uuid']")
    && str_contains($flow, 'CFDI_POST_STAMP_SALE_CLOSE_FAILURE'),
    'La venta se cierra solo tras UUID y un fallo de cierre conserva exito fiscal.');
$assert(str_contains($lifecycle, 'SALE_HAS_STAMPED_CFDI')
    && substr_count($controller, "'SALE_HAS_STAMPED_CFDI'") >= 2
    && str_contains($controller, "function save_discount()")
    && str_contains($controller, "function update_invoice_info("),
    'Los endpoints de mutacion quedan protegidos en backend por stamp/UUID.');

echo PHP_EOL . "$pass passed, $fail failed." . PHP_EOL;
exit($fail === 0 ? 0 : 1);