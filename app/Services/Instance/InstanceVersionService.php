<?php

declare(strict_types=1);

namespace App\Services\Instance;

use CodeIgniter\Database\BaseConnection;
use Config\Version;

final class InstanceVersionService
{
    public function __construct(private BaseConnection $db)
    {
    }

    public function current(): ?string
    {
        if (! $this->db->tableExists('app_schema_versions')) return null;
        $versions = [];
        foreach ($this->db->table('app_schema_versions')->select('version')->get()->getResultArray() as $row) {
            $version = preg_replace('/^ikontrol-/i', '', trim((string) $row['version']));
            if (is_string($version) && preg_match('/^\d+\.\d+\.\d+$/', $version)) $versions[] = $version;
        }
        usort($versions, 'version_compare');
        return $versions === [] ? null : end($versions);
    }

    public function canonical(): string
    {
        return Version::VERSION;
    }

    public function record(string $version, string $description): void
    {
        $value = 'ikontrol-' . $version;
        if ($this->db->table('app_schema_versions')->where('version', $value)->countAllResults()) return;
        $this->db->table('app_schema_versions')->insert([
            'version' => $value,
            'description' => $description,
            'applied_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }
}
