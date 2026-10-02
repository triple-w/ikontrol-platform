<?php

declare(strict_types=1);

namespace App\Services\Instance;

use CodeIgniter\Database\BaseConnection;
use RuntimeException;

/** Single source of truth for instance module availability. */
final class InstanceFeaturesService
{
    private const CORE = ['clients', 'sales', 'payments'];
    private const SETTINGS = [
        'suppliers' => ['module_supplier', false],
        'warehouses' => ['module_warehouse', false],
        'proposals' => ['module_proposal', false],
        'advanced_estimates' => ['module_estimate', false],
        'tasks_kanban' => ['module_task', true],
        'calendar' => ['module_event', false],
        'reports' => ['module_reports', true],
        'banks' => ['module_banks', false],
        'cash' => ['module_cash', false],
        'inventory' => ['module_inventory', false],
        'fiscal' => ['fiscal_enabled', false],
    ];

    public function __construct(private BaseConnection $db)
    {
    }

    public function all(): array
    {
        $result = array_fill_keys(self::CORE, true);
        foreach (self::SETTINGS as $feature => [$setting, $default]) $result[$feature] = $this->setting($setting, $default);
        return $result;
    }

    public function enabled(string $feature): bool
    {
        $features = $this->all();
        if (! array_key_exists($feature, $features)) throw new RuntimeException('Unknown instance feature: ' . $feature);
        return $features[$feature];
    }

    public function assertEnabled(string $feature): void
    {
        if (! $this->enabled($feature)) throw new RuntimeException('INSTANCE_FEATURE_DISABLED: ' . $feature);
    }

    public function ensureDefaults(): array
    {
        $created = [];
        foreach (self::SETTINGS as $feature => [$setting, $default]) {
            $exists = $this->db->table('settings')->where(['setting_name' => $setting, 'deleted' => 0])->countAllResults() > 0;
            if ($exists) continue;
            $this->db->table('settings')->insert([
                'setting_name' => $setting,
                'setting_value' => $default ? '1' : '0',
                'type' => 'app',
                'deleted' => 0,
            ]);
            $created[] = $feature;
        }
        return ['created' => $created, 'features' => $this->all()];
    }

    private function setting(string $name, bool $default): bool
    {
        $row = $this->db->table('settings')->select('setting_value')->where(['setting_name' => $name, 'deleted' => 0])->get(1)->getRowArray();
        return $row === null ? $default : filter_var($row['setting_value'], FILTER_VALIDATE_BOOL);
    }
}
