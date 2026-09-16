<?php

declare(strict_types=1);

namespace App\Services\Database;

use Config\Database;
use RuntimeException;

final class DatabaseTargetGuard
{
    private const FORBIDDEN = ['ikontrol_new', 'fc2_migration_source', 'tws001_factucare'];

    public function confirm(string $group, string $expected): array
    {
        if ($group !== 'clean_build' || $expected !== 'ikontrol20_clean') {
            throw new RuntimeException('El build limpio sólo admite clean_build -> ikontrol20_clean.');
        }

        $config = config(Database::class);
        if (! isset($config->{$group}) || ! is_array($config->{$group})) {
            throw new RuntimeException('El grupo de conexión solicitado no existe.');
        }
        $settings = $config->{$group};
        if (($settings['database'] ?? null) !== $expected || ($settings['DBPrefix'] ?? null) !== 'ikontrol_') {
            throw new RuntimeException('La configuración clean_build no coincide con el destino esperado.');
        }

        $db = Database::connect($group, false);
        $selected = (string) ($db->query('SELECT DATABASE() AS name')->getRow()->name ?? '');
        $reported = (string) $db->getDatabase();
        $exists = (int) ($db->query(
            'SELECT COUNT(*) AS total FROM information_schema.schemata WHERE schema_name = ?',
            [$expected]
        )->getRow()->total ?? 0);

        if ($exists !== 1 || $selected !== $expected || $reported !== $expected) {
            throw new RuntimeException('La conexión activa no coincide exactamente con ikontrol20_clean.');
        }
        if (in_array($selected, self::FORBIDDEN, true)) {
            throw new RuntimeException('La conexión activa apunta a una base expresamente prohibida.');
        }

        return [
            'group' => $group,
            'host' => (string) ($settings['hostname'] ?? ''),
            'port' => (int) ($settings['port'] ?? 3306),
            'database' => $selected,
            'get_database' => $reported,
            'prefix' => (string) ($settings['DBPrefix'] ?? ''),
            'environment' => ENVIRONMENT,
        ];
    }
}
