<?php
declare(strict_types=1);
namespace App\Services\Fiscal\Cancellation;
final class FiscalCancellationReasonCatalog
{
    public function options():array{return[''=>'Seleccionar','01'=>'01 · Comprobante emitido con errores con relación','02'=>'02 · Comprobante emitido con errores sin relación','03'=>'03 · No se llevó a cabo la operación','04'=>'04 · Operación nominativa relacionada en una factura global'];}
}
