<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Pac;

use App\Domain\Fiscal\Pac\PacError;

final class TimbradorXpressErrorMapper
{
    private array $catalog;
    public function __construct(?array $catalog = null) { $this->catalog = $catalog ?? require APPPATH.'Config/fiscal_timbradorxpress_errors.php'; }
    public function isSuccess(?string $code): bool { return $code !== null && in_array($code, $this->catalog['success_codes'] ?? [], true); }
    public function isDuplicate(?string $code): bool { return $code !== null && in_array($code, $this->catalog['duplicate_codes'] ?? [], true); }
    public function map(?string $code, string $providerMessage): PacError
    {
        $entry = $code !== null ? ($this->catalog['codes'][$code] ?? null) : null;
        if (!$entry) return new PacError('unclassified','El PAC rechazó el CFDI',$providerMessage ?: 'Respuesta PAC no clasificada.','Revise el detalle técnico y no reintente sin confirmar la causa.',false,false);
        return new PacError($entry['category'],$entry['title_es'],$entry['message_es'],$entry['recommended_action_es'],(bool)$entry['retryable'],(bool)$entry['requires_reconciliation'],$entry['severity'] ?? 'error');
    }
}
