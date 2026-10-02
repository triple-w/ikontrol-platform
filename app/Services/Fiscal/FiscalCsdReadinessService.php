<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Services\Fiscal\Signing\CsdOperationalStatusService;
use Throwable;

/** Separates a registered CSD record from material that can actually sign. */
final class FiscalCsdReadinessService
{
    public function __construct(private mixed $db = null, private mixed $evaluator = null)
    {
        $this->db ??= db_connect();
    }

    /** @return array<string, mixed> */
    public function inspect(?object $issuer): array
    {
        if (! $issuer || empty($issuer->id)) {
            return $this->result(false, false, 'certificate_missing', 'No hay un CSD registrado.', null, 0);
        }

        $certificates = $this->db->table('fiscal_issuer_certificates')
            ->where(['issuer_profile_id' => (int) $issuer->id, 'deleted' => 0])
            ->orderBy('is_default', 'DESC')
            ->orderBy('valid_to', 'DESC')
            ->orderBy('id', 'DESC')
            ->get()->getResult();

        if ($certificates === []) {
            return $this->result(false, false, 'certificate_missing', 'No hay un CSD registrado.', null, 0);
        }

        $fallback = null;
        $fallbackPriority = PHP_INT_MAX;
        foreach ($certificates as $certificate) {
            try {
                $status = $this->evaluator !== null
                    ? ($this->evaluator)($certificate)
                    : (new CsdOperationalStatusService($this->db))->forCertificate($certificate);
            } catch (Throwable) {
                $status = ['ready' => false, 'code' => 'requires_reconfiguration', 'label' => 'Requiere reconfiguración.'];
            }
            $candidate = $this->result(true, ! empty($status['ready']),
                (string) ($status['code'] ?? 'requires_reconfiguration'),
                (string) ($status['label'] ?? 'Requiere reconfiguración.'),
                $certificate, count($certificates));
            if ($candidate['ready']) return $candidate;
            $priority = match ($candidate['code']) {
                'private_files_unavailable' => 10,
                'password_pending' => 20,
                'encryption_configuration_missing', 'password_invalid' => 30,
                'certificate_not_ready', 'requires_reconfiguration' => 40,
                'certificate_expired' => 50,
                default => 60,
            };
            if ($priority < $fallbackPriority) {
                $fallback = $candidate;
                $fallbackPriority = $priority;
            }
        }

        return $fallback;
    }

    /** @return array<string, mixed> */
    private function result(bool $registered, bool $ready, string $code, string $label, ?object $certificate, int $count): array
    {
        return [
            'registered' => $registered, 'registered_count' => $count, 'ready' => $ready,
            'status' => $ready ? 'READY' : ($registered ? 'BLOCKED' : 'MISSING'),
            'code' => $code, 'label' => $label,
            'certificate_id' => isset($certificate->id) ? (int) $certificate->id : null,
            'certificate' => $certificate,
        ];
    }
}
