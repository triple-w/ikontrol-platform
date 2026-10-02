<?php

declare(strict_types=1);

namespace App\Services\Instance;

use App\Services\Fiscal\FiscalInstanceModeService;
use App\Services\Upgrade\InstanceUpgradeService;
use CodeIgniter\Database\BaseConnection;

/** Safe read model intended for a future authenticated iKontrolAdmin adapter. */
final class InstanceControlSnapshotService
{
    public function __construct(private BaseConnection $db)
    {
    }

    public function snapshot(): array
    {
        $versions = new InstanceVersionService($this->db);
        $lastUpgrade = null;
        if ($this->db->tableExists('instance_upgrade_runs')) $lastUpgrade = $this->db->table('instance_upgrade_runs')->select('from_version,to_version,release_id,status,started_at,completed_at,failed_at')->orderBy('id', 'DESC')->get(1)->getRowArray();
        try {
            $fiscal = (new FiscalInstanceModeService($this->db))->inspect();
        } catch (\Throwable $exception) {
            $fiscal = [
                'mode' => 'INVALID_CONFIGURATION',
                'ready' => false,
                'blocking_reasons' => ['Fiscal configuration could not be evaluated: ' . $exception->getMessage()],
            ];
        }
        return [
            'instance_uuid' => (new InstanceIdentityService($this->db))->get(),
            'current_version' => $versions->current(),
            'target_version' => $versions->canonical(),
            'upgrade' => (new InstanceUpgradeService($this->db))->plan(),
            'features' => (new InstanceFeaturesService($this->db))->all(),
            'fiscal' => $fiscal,
            'last_upgrade' => $lastUpgrade,
        ];
    }
}
