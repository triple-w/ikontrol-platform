<?php

declare(strict_types=1);

namespace App\Services\Upgrade;

use App\Services\Fiscal\SatCatalogImporterService;
use App\Services\Fiscal\SatCatalogInfrastructureService;
use App\Services\Instance\InstanceFeaturesService;
use App\Services\Instance\InstanceIdentityService;
use App\Services\Instance\InstanceVersionService;
use CodeIgniter\Database\BaseConnection;
use RuntimeException;
use Throwable;

/** Directed, resumable release updater. It never invokes the migration runner. */
final class InstanceUpgradeService
{
    private const RELEASES = [
        '1.0.0' => [
            'to' => '1.1.0',
            'release_id' => 'ikontrol-1.1.0-canonical-instances',
            'steps' => ['sat_catalog_schema', 'sat_catalog_import', 'instance_identity', 'instance_feature_defaults', 'record_version'],
        ],
        '1.1.0' => [
            'to' => '1.1.1',
            'release_id' => 'ikontrol-1.1.1-financial-ledger-compatibility',
            'steps' => ['financial_account_movements_schema', 'record_version'],
        ],
        '1.1.1' => [
            'to' => '1.1.2',
            'release_id' => 'ikontrol-1.1.2-payment-allocations-compatibility',
            'steps' => ['payment_allocations_schema', 'record_version'],
        ],
        '1.1.2' => [
            'to' => '1.1.3',
            'release_id' => 'ikontrol-1.1.3-expenses-financial-compatibility',
            'steps' => ['expenses_schema', 'record_version'],
        ],
        '1.1.3' => [
            'to' => '1.1.4',
            'release_id' => 'ikontrol-1.1.4-canonical-stamps-and-sale-statuses',
            'steps' => ['record_version'],
        ],
        '1.1.4' => [
            'to' => '1.1.5',
            'release_id' => 'ikontrol-1.1.5-canonical-stamp-wallet-resolution',
            'steps' => ['record_version'],
        ],
    ];

    public function __construct(private BaseConnection $db, private ?string $catalogRoot = null)
    {
    }

    public function plan(?string $target = null): array
    {
        $versions = new InstanceVersionService($this->db);
        $current = $versions->current();
        $target ??= $versions->canonical();
        if ($current === null) return ['compatible' => false, 'current_version' => null, 'target_version' => $target, 'blockers' => ['app_schema_versions has no iKontrol baseline.'], 'packages' => []];
        $packages = [];
        $cursor = $current;
        while (version_compare($cursor, $target, '<')) {
            $release = self::RELEASES[$cursor] ?? null;
            if ($release === null || version_compare($release['to'], $target, '>')) return ['compatible' => false, 'current_version' => $current, 'target_version' => $target, 'blockers' => ["No directed upgrade path from {$cursor} to {$target}."], 'packages' => $packages];
            $packages[] = ['from' => $cursor, 'checksum' => hash('sha256', $release['release_id'] . '|' . implode('|', $release['steps']))] + $release;
            $cursor = $release['to'];
        }
        return ['compatible' => $cursor === $target, 'current_version' => $current, 'target_version' => $target, 'blockers' => [], 'packages' => $packages];
    }

    public function execute(?string $target = null): array
    {
        $plan = $this->plan($target);
        if (! $plan['compatible']) throw new RuntimeException(implode(' ', $plan['blockers']));
        $this->ensureRegistry();
        $completed = [];
        foreach ($plan['packages'] as $package) $completed[] = $this->executePackage($package);
        return ['plan' => $plan, 'completed' => $completed, 'current_version' => (new InstanceVersionService($this->db))->current()];
    }

    private function executePackage(array $package): array
    {
        $runs = $this->db->table('instance_upgrade_runs');
        $run = $runs->where(['from_version' => $package['from'], 'to_version' => $package['to']])->whereIn('status', ['running', 'failed', 'completed'])->orderBy('id', 'DESC')->get(1)->getRowArray();
        $resumed = $run !== null;
        if ($run && $run['status'] === 'completed') return ['run_id' => (int) $run['id'], 'status' => 'completed', 'resumed' => false];
        if (! $run) {
            $runs->insert(['from_version' => $package['from'], 'to_version' => $package['to'], 'release_id' => $package['release_id'], 'release_checksum' => $package['checksum'], 'status' => 'running', 'started_at' => gmdate('Y-m-d H:i:s')]);
            $run = ['id' => $this->db->insertID()];
        } else {
            $runs->where('id', $run['id'])->update(['status' => 'running', 'failed_at' => null, 'error_message' => null]);
        }
        $runId = (int) $run['id'];
        $activeStep = null;
        try {
            foreach ($package['steps'] as $step) {
                if ($this->stepCompleted($runId, $step)) continue;
                $activeStep = $step;
                $this->markStep($runId, $step, 'running');
                $result = $this->runStep($step, $package);
                $this->markStep($runId, $step, 'completed', $result);
            }
            $runs->where('id', $runId)->update(['status' => 'completed', 'completed_at' => gmdate('Y-m-d H:i:s')]);
            return ['run_id' => $runId, 'status' => 'completed', 'resumed' => $resumed];
        } catch (Throwable $error) {
            if ($activeStep !== null) $this->markStep($runId, $activeStep, 'failed', ['error' => $error->getMessage()]);
            $runs->where('id', $runId)->update(['status' => 'failed', 'failed_at' => gmdate('Y-m-d H:i:s'), 'error_message' => substr($error->getMessage(), 0, 1000)]);
            throw $error;
        }
    }

    private function runStep(string $step, array $package): array
    {
        return match ($step) {
            'sat_catalog_schema' => (new SatCatalogInfrastructureService($this->db))->apply(),
            'sat_catalog_import' => (new SatCatalogImporterService($this->db, $this->catalogRoot))->update(),
            'instance_feature_defaults' => (new InstanceFeaturesService($this->db))->ensureDefaults(),
            'instance_identity' => ['instance_uuid' => (new InstanceIdentityService($this->db))->ensure()],
            'financial_account_movements_schema' => (new FinancialAccountMovementsSchemaUpgrade($this->db))->apply(),
            'payment_allocations_schema' => (new PaymentAllocationsSchemaUpgrade($this->db))->apply(),
            'expenses_schema' => (new ExpensesSchemaUpgrade($this->db))->apply(),
            'record_version' => $this->recordVersion($package['to'], $package['release_id']),
            default => throw new RuntimeException('Unknown directed upgrade step: ' . $step),
        };
    }

    private function recordVersion(string $version, string $releaseId): array
    {
        (new InstanceVersionService($this->db))->record($version, $releaseId);
        return ['version' => $version, 'release_id' => $releaseId];
    }

    private function ensureRegistry(): void
    {
        $this->db->query('CREATE TABLE IF NOT EXISTS ' . $this->q('instance_upgrade_runs') . ' (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, from_version VARCHAR(40) NOT NULL, to_version VARCHAR(40) NOT NULL, release_id VARCHAR(150) NOT NULL, release_checksum CHAR(64) NOT NULL, status VARCHAR(20) NOT NULL, started_at DATETIME NOT NULL, completed_at DATETIME NULL, failed_at DATETIME NULL, error_message VARCHAR(1000) NULL, KEY idx_instance_upgrade_release (from_version,to_version,status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->db->query('CREATE TABLE IF NOT EXISTS ' . $this->q('instance_upgrade_steps') . ' (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, upgrade_run_id BIGINT UNSIGNED NOT NULL, step VARCHAR(100) NOT NULL, status VARCHAR(20) NOT NULL, result_json LONGTEXT NULL, started_at DATETIME NULL, completed_at DATETIME NULL, UNIQUE KEY uq_instance_upgrade_step (upgrade_run_id,step)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    private function stepCompleted(int $runId, string $step): bool
    {
        return $this->db->table('instance_upgrade_steps')->where(['upgrade_run_id' => $runId, 'step' => $step, 'status' => 'completed'])->countAllResults() > 0;
    }

    private function markStep(int $runId, string $step, string $status, array $result = []): void
    {
        $table = $this->db->table('instance_upgrade_steps');
        $existing = $table->where(['upgrade_run_id' => $runId, 'step' => $step])->get(1)->getRowArray();
        $data = ['status' => $status, 'result_json' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'started_at' => gmdate('Y-m-d H:i:s'), 'completed_at' => $status === 'completed' ? gmdate('Y-m-d H:i:s') : null];
        $existing ? $table->where('id', $existing['id'])->update($data) : $table->insert($data + ['upgrade_run_id' => $runId, 'step' => $step]);
    }

    private function q(string $table): string
    {
        return $this->db->protectIdentifiers($this->db->prefixTable($table));
    }
}
