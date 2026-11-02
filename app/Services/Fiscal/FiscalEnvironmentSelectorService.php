<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use RuntimeException;

/** Persists audited previews; it cannot mutate server configuration. */
final class FiscalEnvironmentSelectorService
{
    public function __construct(
        private mixed $db = null,
        private ?object $fiscal = null,
        private ?object $pac = null,
        private ?FiscalEnvironmentActivationDriverInterface $driver = null
    ) {
        $this->db ??= db_connect();
        $this->fiscal ??= config('Fiscal');
        $this->pac ??= config('TimbradorXpress');
        $this->driver ??= new PreviewOnlyFiscalEnvironmentDriver($this->fiscal, $this->pac);
    }

    public function inspect(string $target): array
    {
        $target = $this->target($target);
        $current = (new FiscalRuntimeDiagnosticService())->inspect($this->fiscal, $this->pac);
        $operations = $this->operationsInProgress();
        $issuer = (new FiscalIssuerResolver($this->db))->resolve(null, $target);
        $series = $issuer && $this->db->tableExists('fiscal_series')
            ? (int) $this->db->table('fiscal_series')->where([
                'issuer_profile_id' => $issuer->id, 'environment' => $target, 'is_active' => 1, 'deleted' => 0,
            ])->countAllResults()
            : 0;
        $wallet = $issuer && $this->db->tableExists('fiscal_stamp_accounts')
            ? (new Stamps\FiscalStampWalletResolutionService($this->db))->resolve((int) $issuer->id, $target)
            : ['status' => 'not_installed', 'account' => null, 'environment' => $target];
        $sameEnvironment = $target === $current['fiscal_environment'];
        return [
            'feature_enabled' => !empty($this->fiscal->environmentSelectorEnabled),
            'preview_only' => true,
            'current' => $current,
            'target_environment' => $target,
            'target_transport' => FiscalRuntimeContext::transportFor($target),
            'target_endpoint' => FiscalRuntimeContext::endpointFor($target),
            'target_endpoint_allowed' => true,
            'runtime_activation_required' => !$sameEnvironment,
            'credential_check' => $sameEnvironment ? ($current['credential_present'] ? 'present' : 'missing') : 'requires_server_readback',
            'issuer' => ['ready' => (bool) $issuer, 'id' => $issuer?->id],
            'series' => ['ready' => $series > 0, 'active' => $series],
            'wallet' => ['ready' => !empty($wallet['account']) && ($wallet['status'] ?? '') === 'active', 'status' => $wallet['status'] ?? 'unknown'],
            'operations' => $operations,
            'can_request' => !empty($this->fiscal->environmentSelectorEnabled) && $operations['total'] === 0,
            'can_activate' => false,
            'blocking_reasons' => array_values(array_filter([
                empty($this->fiscal->environmentSelectorEnabled) ? 'La feature flag del selector está deshabilitada.' : null,
                $operations['total'] > 0 ? 'Existen operaciones fiscales en curso o pendientes de conciliación.' : null,
                !$issuer ? 'No existe un emisor listo en el ambiente destino.' : null,
                $series < 1 ? 'No existe una serie activa para el emisor y ambiente destino.' : null,
                !$sameEnvironment ? 'Las credenciales y el transporte del destino requieren activación y read-back del servidor.' : null,
            ])),
        ];
    }

    public function requestPreview(string $target, string $reason, string $confirmation, int $actorId, bool $authorized): array
    {
        if (!$authorized) throw new RuntimeException('FISCAL_ENVIRONMENT_PERMISSION_REQUIRED');
        if (empty($this->fiscal->environmentSelectorEnabled)) throw new RuntimeException('FISCAL_ENVIRONMENT_SELECTOR_DISABLED');
        if (!$this->db->tableExists('fiscal_environment_change_requests')) throw new RuntimeException('FISCAL_ENVIRONMENT_SELECTOR_SCHEMA_MISSING');
        $target = $this->target($target);
        $reason = trim($reason);
        if (mb_strlen($reason) < 12 || mb_strlen($reason) > 500) throw new RuntimeException('FISCAL_ENVIRONMENT_REASON_INVALID');
        $expected = $target === FiscalRuntimeContext::PRODUCTION ? 'ACTIVAR PRODUCCION' : 'CAMBIAR AMBIENTE';
        if (!hash_equals($expected, strtoupper(trim($confirmation)))) throw new RuntimeException('FISCAL_ENVIRONMENT_CONFIRMATION_INVALID');
        $preview = $this->inspect($target);
        if ($preview['operations']['total'] > 0) throw new RuntimeException('FISCAL_ENVIRONMENT_OPERATIONS_IN_PROGRESS');
        $before = $preview['current'];
        $now = function_exists('get_current_utc_time') ? get_current_utc_time() : gmdate('Y-m-d H:i:s');
        $row = [
            'requested_environment' => $target,
            'current_environment' => $before['fiscal_environment'],
            'status' => 'preview_only',
            'reason' => $reason,
            'before_context_id' => $before['context_id'],
            'after_context_id' => $before['context_id'],
            'preflight_json' => json_encode($this->safeAuditProjection($preview), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'requested_by' => $actorId,
            'requested_at' => $now,
            'verified_at' => $now,
        ];
        $this->db->table('fiscal_environment_change_requests')->insert($row);
        $id = (int) $this->db->insertID();
        $readBack = $this->driver->readBack();
        $changed = $readBack['context_id'] !== $before['context_id'];
        $this->db->table('fiscal_environment_change_requests')->where('id', $id)->update([
            'status' => $changed ? 'read_back_mismatch' : 'preview_only',
            'after_context_id' => $readBack['context_id'],
            'verified_at' => function_exists('get_current_utc_time') ? get_current_utc_time() : gmdate('Y-m-d H:i:s'),
        ]);
        if ($changed) throw new RuntimeException('FISCAL_ENVIRONMENT_READ_BACK_MISMATCH');
        return ['id' => $id, 'status' => 'preview_only', 'preview' => $preview, 'read_back' => $readBack,
            'environment_changed' => false];
    }

    public function latest(): ?object
    {
        if (!$this->db->tableExists('fiscal_environment_change_requests')) return null;
        return $this->db->table('fiscal_environment_change_requests')->orderBy('id', 'DESC')->get(1)->getRow();
    }

    private function operationsInProgress(): array
    {
        $counts = ['stamping' => 0, 'cancellation' => 0, 'pdf' => 0];
        if ($this->db->tableExists('fiscal_stamp_attempts')) {
            $counts['stamping'] = (int) $this->db->table('fiscal_stamp_attempts')->groupStart()
                ->whereIn('status', ['pending','sending','unknown','timeout_unknown','transport_unknown','reconciliation_required'])
                ->orWhere('requires_reconciliation', 1)->groupEnd()->countAllResults();
        }
        if ($this->db->tableExists('fiscal_cancellation_requests')) {
            $counts['cancellation'] = (int) $this->db->table('fiscal_cancellation_requests')->groupStart()
                ->whereIn('status', ['pending','sending','unknown','requested','pending_acceptance'])
                ->orWhere('requires_reconciliation', 1)->groupEnd()->countAllResults();
        }
        if ($this->db->tableExists('fiscal_pdf_generation_attempts')) {
            $counts['pdf'] = (int) $this->db->table('fiscal_pdf_generation_attempts')->groupStart()
                ->whereIn('status', ['pending','sending','unknown'])
                ->orWhere('requires_reconciliation', 1)->groupEnd()->countAllResults();
        }
        return $counts + ['total' => array_sum($counts)];
    }

    private function safeAuditProjection(array $preview): array
    {
        return [
            'current_context_id' => $preview['current']['context_id'],
            'current_environment' => $preview['current']['fiscal_environment'],
            'target_environment' => $preview['target_environment'],
            'target_transport' => $preview['target_transport'],
            'target_endpoint' => $preview['target_endpoint'],
            'credential_check' => $preview['credential_check'],
            'issuer' => $preview['issuer'], 'series' => $preview['series'], 'wallet' => $preview['wallet'],
            'operations' => $preview['operations'], 'blocking_reasons' => $preview['blocking_reasons'],
        ];
    }

    private function target(string $target): string
    {
        $target = strtolower(trim($target));
        if (!in_array($target, [FiscalRuntimeContext::DEVELOPMENT, FiscalRuntimeContext::PRODUCTION], true)) {
            throw new RuntimeException('FISCAL_ENVIRONMENT_TARGET_INVALID');
        }
        return $target;
    }
}
