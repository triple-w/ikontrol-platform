<?php
declare(strict_types=1);
namespace App\Services\Fiscal;
use RuntimeException;
final class FiscalPreviewModeGuard
{
    public function __construct(private $db=null) {}
    public function isPreview(): bool
    {
        // Runtime policy is explicit. A database name must never silently override
        // the active integration environment (this database is now canonical dev).
        return (bool)config('Fiscal')->previewMode;
    }
    public function assertStampingAllowed(): void
    {
        $fiscal=config('Fiscal');
        if($this->isPreview() || !$fiscal->stampingEnabled) throw new RuntimeException('AMBIENTE DE VISTA PREVIA — TIMBRADO FISCAL DESHABILITADO');
    }
    public function assertCancellationAllowed(): void
    {
        $fiscal=config('Fiscal');
        if($this->isPreview() || !$fiscal->stampingEnabled) throw new RuntimeException('AMBIENTE DE VISTA PREVIA — CANCELACIÓN FISCAL DESHABILITADA');
    }
}
