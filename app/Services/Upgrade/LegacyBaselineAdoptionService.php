<?php

declare(strict_types=1);

namespace App\Services\Upgrade;

use App\Services\Instance\InstanceVersionService;
use CodeIgniter\Database\BaseConnection;
use RuntimeException;
use Throwable;

/** Explicitly adopts a compatible historical RISE database as iKontrol 1.0.0. */
final class LegacyBaselineAdoptionService
{
    public const BASELINE_VERSION = '1.0.0';
    public const RISE_BASELINE = 'rise-administrative-baseline-1';

    /** @var list<string> */
    private const CORE_TABLES = [
        'settings', 'users', 'roles', 'clients', 'items', 'estimates',
        'estimate_items', 'invoices', 'invoice_items', 'invoice_payments',
        'payment_methods', 'taxes', 'company', 'app_schema_versions',
    ];

    public function __construct(private BaseConnection $db)
    {
    }

    /** @return array<string,mixed> */
    public function inspect(): array
    {
        $checks = [];
        $blockers = [];
        $versions = new InstanceVersionService($this->db);

        try {
            $checks['database'] = $this->db->query('SELECT 1 readiness')->getRow() ? 'READY' : 'FAILED';
        } catch (Throwable) {
            $checks['database'] = 'FAILED';
            $blockers[] = ['code'=>'DATABASE_NOT_READY','message'=>'No fue posible consultar la base configurada.'];
        }

        $missing = [];
        foreach (self::CORE_TABLES as $table) {
            if (! $this->db->tableExists($table)) $missing[] = $table;
        }
        $checks['core_schema'] = $missing === [] ? 'READY' : 'INCOMPLETE';
        $checks['missing_core_tables'] = $missing;
        if ($missing !== []) {
            $blockers[] = ['code'=>'CORE_SCHEMA_INCOMPLETE','message'=>'Faltan tablas administrativas requeridas: '.implode(', ', $missing).'.'];
        }

        $current = $this->db->tableExists('app_schema_versions') ? $versions->current() : null;
        $checks['current_version'] = $current;
        $ikontrolVersionRows = $this->db->tableExists('app_schema_versions')
            ? $this->db->table('app_schema_versions')->select('version')->like('version', 'ikontrol-', 'after')->get()->getResultArray()
            : [];
        $checks['ikontrol_version_evidence'] = array_column($ikontrolVersionRows, 'version');
        if ($current !== null && $current !== self::BASELINE_VERSION) {
            $blockers[] = ['code'=>'INSTANCE_ALREADY_VERSIONED','message'=>"La instancia ya registra iKontrol {$current}."];
        } elseif ($current === null && $ikontrolVersionRows !== []) {
            $blockers[] = ['code'=>'UNKNOWN_IKONTROL_VERSION_EVIDENCE','message'=>'Existen registros iKontrol no reconocidos; requieren revisión antes de adoptar.'];
        }

        $baseline = $this->db->tableExists('app_schema_versions')
            && $this->db->table('app_schema_versions')->where('version', self::RISE_BASELINE)->countAllResults() > 0;
        $checks['rise_baseline'] = $baseline ? 'READY' : 'MISSING';
        if (! $baseline) {
            $blockers[] = ['code'=>'RISE_BASELINE_MISSING','message'=>'No existe el baseline administrativo RISE esperado.'];
        }

        $adminCount = 0;
        if ($this->db->tableExists('users')) {
            $adminCount = $this->db->table('users')->where([
                'user_type'=>'staff', 'is_admin'=>1, 'status'=>'active',
                'disable_login'=>0, 'deleted'=>0,
            ])->countAllResults();
        }
        $checks['active_staff_admins'] = $adminCount;
        if ($adminCount < 1) {
            $blockers[] = ['code'=>'ACTIVE_STAFF_ADMIN_MISSING','message'=>'No existe un administrador staff activo.'];
        }

        $upgradeEvidence = $this->upgradeEvidence();
        $checks['directed_upgrade_evidence'] = $upgradeEvidence;
        if ($upgradeEvidence !== []) {
            $blockers[] = ['code'=>'LATER_UPGRADE_EVIDENCE','message'=>'Existen ejecuciones dirigidas incompatibles con una adopción inicial.'];
        }

        $alreadyAdopted = $current === self::BASELINE_VERSION;
        return $this->result(
            $blockers === [],
            $alreadyAdopted,
            $checks,
            $blockers,
            $blockers === [] ? ($alreadyAdopted ? 'ALREADY_ADOPTED' : 'READY_TO_ADOPT') : 'BLOCKED'
        );
    }

    /** @return array<string,mixed> */
    public function adopt(): array
    {
        $inspection = $this->inspect();
        if ($inspection['already_adopted']) return $inspection + ['written'=>false];
        if (! $inspection['compatible']) {
            throw new RuntimeException(json_encode($inspection, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        $this->db->transBegin();
        try {
            $versions = new InstanceVersionService($this->db);
            $current = $versions->current();
            if ($current !== null && $current !== self::BASELINE_VERSION) {
                throw new RuntimeException("La instancia fue versionada concurrentemente como {$current}.");
            }
            $versions->record(self::BASELINE_VERSION, 'Explicit adoption of compatible RISE administrative baseline.');
            if (! $this->db->transStatus()) throw new RuntimeException('No fue posible registrar la adopción del baseline.');
            $this->db->transCommit();
        } catch (Throwable $error) {
            $this->db->transRollback();
            throw $error;
        }

        $result = $this->inspect();
        if (! $result['already_adopted']) throw new RuntimeException('La adopción no quedó registrada como iKontrol 1.0.0.');
        return $result + ['written'=>true];
    }

    /** @return list<array<string,mixed>> */
    private function upgradeEvidence(): array
    {
        if (! $this->db->tableExists('instance_upgrade_runs')) return [];
        return $this->db->table('instance_upgrade_runs')
            ->select('id,from_version,to_version,release_id,status')
            ->whereIn('status', ['running','completed'])
            ->where('to_version >', self::BASELINE_VERSION)
            ->orderBy('id')->get()->getResultArray();
    }

    /** @param array<string,mixed> $checks @param list<array<string,string>> $blockers */
    private function result(bool $compatible, bool $alreadyAdopted, array $checks, array $blockers, string $status): array
    {
        return [
            'status'=>$status,
            'compatible'=>$compatible,
            'already_adopted'=>$alreadyAdopted,
            'baseline_version'=>self::BASELINE_VERSION,
            'checks'=>$checks,
            'blockers'=>$blockers,
        ];
    }
}
