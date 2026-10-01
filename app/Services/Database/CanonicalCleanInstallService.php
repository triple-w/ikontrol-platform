<?php

declare(strict_types=1);

namespace App\Services\Database;

use CodeIgniter\Config\Services;
use CodeIgniter\Database\BaseConnection;
use Config\Migrations;
use RuntimeException;

/**
 * Installs the versioned iKontrol schema into an empty database only.
 *
 * This is deliberately separate from `spark migrate`: the old migrations
 * include data-reconciliation steps which are safe on an empty baseline but
 * must never be replayed against an existing customer installation.
 */
final class CanonicalCleanInstallService
{
    /** @var list<string> */
    private const REQUIRED_MIGRATIONS = [
        '2026-09-25-120000_AddProposalTemplateSelection.php',
        '2026-09-28-130000_EnableCanonicalManualSupplierCostHistory.php',
        '2026-09-29-120000_CreateCanonicalPaymentComplementExternalDocuments.php',
        '2026-09-30-100000_AddCanonicalFiscalStampTransportEvidence.php',
    ];

    public function install(BaseConnection $db, string $group, string $expectedDatabase, array $admin): array
    {
        $this->assertEmptyTarget($db, $expectedDatabase);
        $this->importRiseBaseline($db, $admin);
        $migrations = $this->applyCanonicalMigrations($db, $group);
        $seeded = (new ExplicitConnectionSeederOrchestrator())->runSatCatalogs($db, $expectedDatabase);

        return [
            'database' => $expectedDatabase,
            'migrations' => $migrations,
            'seeders' => array_column($seeded, 'seeder'),
            'admin_email' => $admin['email'],
        ];
    }

    private function assertEmptyTarget(BaseConnection $db, string $expectedDatabase): void
    {
        $selected = (string) ($db->query('SELECT DATABASE() AS name')->getRow()->name ?? '');
        if ($expectedDatabase === '' || $selected !== $expectedDatabase || $db->getDatabase() !== $expectedDatabase) {
            throw new RuntimeException('The selected database does not match --expected-database.');
        }
        if ($db->getPrefix() !== 'ikontrol_') {
            throw new RuntimeException('A clean canonical install requires DBPrefix=ikontrol_.');
        }
        if ($db->listTables() !== []) {
            throw new RuntimeException('The target database is not empty. Use the documented upgrade path for an existing instance.');
        }
    }

    private function importRiseBaseline(BaseConnection $db, array $admin): void
    {
        $path = ROOTPATH . 'install1/database.sql';
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException('The bundled RISE baseline SQL is missing.');
        }
        $connection = $db->connID;
        if (! $connection instanceof \mysqli) {
            throw new RuntimeException('The canonical clean installer requires a MySQLi connection.');
        }
        $replacements = [
            'admin_first_name' => $connection->real_escape_string($admin['first_name']),
            'admin_last_name' => $connection->real_escape_string($admin['last_name']),
            'admin_email' => $connection->real_escape_string($admin['email']),
            'admin_password' => $connection->real_escape_string($admin['password_hash']),
            'admin_created_at' => date('Y-m-d H:i:s'),
            'ITEM-PURCHASE-CODE' => 'CLEAN-LOCAL-NOT-LICENSED',
        ];
        $sql = str_replace(array_keys($replacements), array_values($replacements), $sql);
        $sql = str_replace('CREATE TABLE IF NOT EXISTS `', 'CREATE TABLE IF NOT EXISTS `ikontrol_', $sql);
        $sql = str_replace('INSERT INTO `', 'INSERT INTO `ikontrol_', $sql);
        if (! $connection->multi_query($sql)) {
            throw new RuntimeException('Unable to import the RISE baseline: ' . $connection->error);
        }
        do {
            if ($result = $connection->store_result()) {
                $result->free();
            }
        } while ($connection->more_results() && $connection->next_result());
        if ($connection->errno !== 0) {
            throw new RuntimeException('RISE baseline import failed: ' . $connection->error);
        }
        $db->resetDataCache();
    }

    /** @return list<string> */
    private function applyCanonicalMigrations(BaseConnection $db, string $group): array
    {
        $files = glob(APPPATH . 'Database/Migrations/*.php') ?: [];
        sort($files, SORT_STRING);
        foreach (self::REQUIRED_MIGRATIONS as $required) {
            if (! in_array(APPPATH . 'Database/Migrations/' . $required, $files, true)) {
                throw new RuntimeException('Required canonical migration is missing: ' . $required);
            }
        }
        $runner = Services::migrations(config(Migrations::class), $db, false);
        $applied = [];
        foreach ($files as $file) {
            if (! $runner->force($file, 'App\\Database\\Migrations', $group)) {
                $messages = implode('; ', $runner->getCliMessages());
                throw new RuntimeException('Canonical migration failed: ' . basename($file) . ($messages === '' ? '' : ' — ' . $messages));
            }
            $applied[] = basename($file);
        }
        return $applied;
    }
}
