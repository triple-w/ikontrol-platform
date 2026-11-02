<?php

declare(strict_types=1);

namespace App\Services\Upgrade;

use App\Services\Fiscal\SatCatalogImporterService;
use App\Services\Fiscal\SatCatalogInfrastructureService;
use App\Services\Instance\InstanceFeaturesService;
use App\Services\Instance\InstanceIdentityService;
use App\Services\Instance\InstanceVersionService;
use App\Services\Upgrade\Legacy\PhysicalSchemaInspector;
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
        '1.1.5' => [
            'to' => '1.1.6',
            'release_id' => 'ikontrol-1.1.6-fiscal-unification',
            'steps' => ['fiscal_116_schema', 'fiscal_116_verify', 'record_version'],
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
            'fiscal_116_schema' => $this->applyFiscal116Schema(),
            'fiscal_116_verify' => $this->verifyFiscal116Schema(),
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

    private function applyFiscal116Schema(): array
    {
        $schema=new PhysicalSchemaInspector($this->db,(string)$this->db->DBPrefix);
        foreach (['fiscal_documents','fiscal_document_stamps'] as $required) {
            if (! $schema->tableExists($required)) throw new RuntimeException('FISCAL_116_PREREQUISITE_MISSING:' . $required);
        }
        $this->assertFiscal116Prerequisites($schema);
        $before=[];
        foreach (['fiscal_environment_change_requests','fiscal_sat_status_queries','fiscal_sat_status_artifacts'] as $table) {
            $before[$table]=$schema->tableExists($table)?(int)$this->db->table($table)->countAllResults():0;
        }
        $this->db->query('CREATE TABLE IF NOT EXISTS '.$this->q('fiscal_environment_change_requests').' (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, requested_environment VARCHAR(20) NOT NULL, current_environment VARCHAR(20) NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT \'preview_only\', reason VARCHAR(500) NOT NULL, before_context_id VARCHAR(40) NOT NULL,
            after_context_id VARCHAR(40) NOT NULL, preflight_json TEXT NOT NULL, requested_by BIGINT UNSIGNED NOT NULL, requested_at DATETIME NOT NULL,
            verified_at DATETIME NULL, KEY idx_fiscal_env_request_environment (requested_environment,requested_at),
            KEY idx_fiscal_env_request_user (requested_by,requested_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->db->query('CREATE TABLE IF NOT EXISTS '.$this->q('fiscal_sat_status_queries').' (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, fiscal_document_id BIGINT UNSIGNED NOT NULL, fiscal_document_stamp_id BIGINT UNSIGNED NOT NULL,
            cancellation_request_id BIGINT UNSIGNED NULL, uuid CHAR(36) NOT NULL, provider VARCHAR(40) NOT NULL, environment VARCHAR(20) NOT NULL,
            idempotency_key VARCHAR(80) NOT NULL, lock_document_id BIGINT UNSIGNED NULL, status VARCHAR(40) NOT NULL DEFAULT \'prepared\',
            normalized_status VARCHAR(40) NOT NULL DEFAULT \'unknown\', authoritative_status VARCHAR(40) NOT NULL DEFAULT \'unknown\',
            previous_authoritative_status VARCHAR(40) NOT NULL DEFAULT \'unknown\', provider_code VARCHAR(100) NULL, provider_message TEXT NULL,
            http_status INT NULL, request_sent TINYINT(1) NOT NULL DEFAULT 0, timed_out TINYINT(1) NOT NULL DEFAULT 0,
            requires_reconciliation TINYINT(1) NOT NULL DEFAULT 0, commercial_policy VARCHAR(60) NOT NULL, commercial_credit_delta INT NOT NULL DEFAULT 0,
            may_have_provider_cost TINYINT(1) NOT NULL DEFAULT 1, queried_by BIGINT UNSIGNED NOT NULL, started_at DATETIME NOT NULL,
            completed_at DATETIME NULL, cooldown_until DATETIME NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
            UNIQUE KEY uq_fiscal_sat_status_idempotency (idempotency_key), UNIQUE KEY uq_fiscal_sat_status_document_lock (lock_document_id),
            KEY idx_fiscal_sat_status_document (fiscal_document_id,created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->db->query('CREATE TABLE IF NOT EXISTS '.$this->q('fiscal_sat_status_artifacts').' (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, fiscal_sat_status_query_id BIGINT UNSIGNED NOT NULL,
            artifact_type VARCHAR(30) NOT NULL DEFAULT \'provider_response\', content_encoding VARCHAR(20) NOT NULL DEFAULT \'base64\',
            content_base64 LONGTEXT NOT NULL, decoded_mime_type VARCHAR(80) NOT NULL DEFAULT \'application/json\',
            decoded_size_bytes BIGINT UNSIGNED NOT NULL, decoded_sha256 CHAR(64) NOT NULL, created_by BIGINT UNSIGNED NOT NULL, created_at DATETIME NOT NULL,
            UNIQUE KEY uq_fiscal_sat_status_artifact (fiscal_sat_status_query_id,artifact_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        foreach(['fiscal_drafts','fiscal_documents']as$table){
            if(!$schema->columnExists($table,'global_periodicity'))$this->db->query('ALTER TABLE '.$this->q($table).' ADD global_periodicity CHAR(2) NULL');
            if(!$schema->columnExists($table,'global_months'))$this->db->query('ALTER TABLE '.$this->q($table).' ADD global_months CHAR(2) NULL');
            if(!$schema->columnExists($table,'global_year'))$this->db->query('ALTER TABLE '.$this->q($table).' ADD global_year SMALLINT UNSIGNED NULL');
        }
        $verified=$this->verifyFiscal116Schema();
        foreach ($before as $table=>$count) if ((int)$this->db->table($table)->countAllResults()!==$count) throw new RuntimeException('FISCAL_116_HISTORICAL_ROWS_CHANGED:'.$table);
        return ['created_or_present'=>array_keys($before),'historical_row_counts'=>$before,'verification'=>$verified];
    }

    private function verifyFiscal116Schema(): array
    {
        $schemaInspector=new PhysicalSchemaInspector($this->db,(string)$this->db->DBPrefix);
        $this->assertFiscal116Prerequisites($schemaInspector);
        $contract=Fiscal116SchemaContract::NEW_TABLES;
        $shape=[];$errors=[];
        foreach ($contract as $table=>$required) {
            if (! $schemaInspector->tableExists($table)) {$errors[]='missing_table:'.$table;continue;}
            $actual=array_map(static fn(array$row):string=>(string)$row['column_name'],$this->db->query('SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? ORDER BY ordinal_position',[$this->db->prefixTable($table)])->getResultArray());
            foreach($required['columns'] as $column)if(!in_array(strtolower($column),array_map('strtolower',$actual),true))$errors[]='missing_column:'.$table.'.'.$column;
            $indexShape=[];
            foreach($required['indexes'] as $name=>$columns){$observed=$this->indexDefinition($table,$name);$indexShape[$name]=$observed;$expectedUnique=$name==='PRIMARY'||str_starts_with($name,'uq_');if($observed['columns']!==$columns||$observed['unique']!==$expectedUnique)$errors[]='invalid_index:'.$table.'.'.$name;}
            $shape[$table]=['physical'=>$this->db->prefixTable($table),'columns'=>$actual,'indexes'=>$indexShape,'rows'=>(int)$this->db->table($table)->countAllResults()];
        }
        foreach(['fiscal_drafts','fiscal_documents']as$table)foreach(['global_periodicity','global_months','global_year']as$column)if(!$schemaInspector->columnExists($table,$column))$errors[]='missing_column:'.$table.'.'.$column;
        if($errors!==[])throw new RuntimeException('FISCAL_116_SCHEMA_INCOMPLETE:'.implode(',',$errors));
        return ['ready'=>true,'fingerprint'=>hash('sha256',json_encode($shape,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),'tables'=>$shape];
    }

    private function indexDefinition(string $table,string $index): array
    {
        $rows=$this->db->query('SELECT column_name,non_unique FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=? ORDER BY seq_in_index',[$this->db->prefixTable($table),$index])->getResultArray();
        return ['columns'=>array_map(static fn(array$row):string=>(string)$row['column_name'],$rows),'unique'=>$rows!==[]&&(int)$rows[0]['non_unique']===0];
    }

    private function assertFiscal116Prerequisites(PhysicalSchemaInspector $schema): void
    {
        $required=Fiscal116SchemaContract::PREREQUISITE_COLUMNS;
        $errors=[];
        foreach($required as$table=>$columns){
            if(!$schema->tableExists($table)){$errors[]='missing_table:'.$table;continue;}
            $actual=array_map(static fn(array$row):string=>strtolower((string)$row['column_name']),$this->db->query('SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=?',[$this->db->prefixTable($table)])->getResultArray());
            foreach($columns as$column)if(!in_array(strtolower($column),$actual,true))$errors[]='missing_column:'.$table.'.'.$column;
        }
        foreach(Fiscal116SchemaContract::PREREQUISITE_INDEXES as$table=>$indexes)foreach($indexes as$name=>$expected){$observed=$this->indexDefinition($table,$name);if($observed['columns']!==$expected['columns']||$observed['unique']!==$expected['unique'])$errors[]='invalid_index:'.$table.'.'.$name;}
        if($errors!==[])throw new RuntimeException('FISCAL_116_PREREQUISITE_INCOMPATIBLE:'.implode(',',$errors));
    }

    private function q(string $table): string
    {
        return $this->db->protectIdentifiers($this->db->prefixTable($table));
    }
}
