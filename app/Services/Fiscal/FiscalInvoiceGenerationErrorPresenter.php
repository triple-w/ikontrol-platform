<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Domain\Fiscal\FiscalInvoiceGenerationResult;
use App\Domain\Fiscal\Signing\CsdSecretException;
use Throwable;

final class FiscalInvoiceGenerationErrorPresenter
{
    public function present(Throwable $error, string $stage, ?int $documentId = null): FiscalInvoiceGenerationResult
    {
        if ($error instanceof CsdSecretException) {
            return new FiscalInvoiceGenerationResult(
                false,
                'csd',
                'correctable_error',
                $documentId,
                null,
                $error->errorCode,
                $error->getMessage(),
                correctable: true,
                recommendedAction: 'Configurar certificado'
            );
        }

        $correctableStages = ['readiness', 'pricing', 'snapshot', 'xml', 'xsd', 'csd', 'signing', 'verification'];
        $correctable = in_array($stage, $correctableStages, true);

        return new FiscalInvoiceGenerationResult(
            false,
            $stage,
            $correctable ? 'correctable_error' : 'generation_error',
            $documentId,
            null,
            'FISCAL_' . strtoupper($stage) . '_FAILED',
            $this->safeMessage($stage),
            retryable: false,
            correctable: $correctable,
            recommendedAction: $correctable ? 'Corregir datos fiscales' : 'Revisar el detalle técnico'
        );
    }

    private function safeMessage(string $stage): string
    {
        return match ($stage) {
            'authorization' => 'No tiene permiso para generar la factura.',
            'readiness' => 'La venta todavía no reúne los datos fiscales necesarios.',
            'pricing' => 'La preparación de precios requiere revisión.',
            'snapshot' => 'No fue posible crear una nueva versión fiscal.',
            'csd', 'signing' => 'El CSD no está listo para firmar automáticamente.',
            'xml', 'xsd', 'verification' => 'El XML fiscal no superó la validación local.',
            'configuration' => 'La configuración fiscal local no está lista.',
            'stamping' => 'No fue posible completar el timbrado simulado.',
            default => 'No fue posible completar la generación fiscal.',
        };
    }
}
