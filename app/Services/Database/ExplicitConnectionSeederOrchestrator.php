<?php

declare(strict_types=1);

namespace App\Services\Database;

use App\Database\Seeds\SatCfdiUsesSeeder;
use App\Database\Seeds\SatProductServiceKeysSeeder;
use App\Database\Seeds\SatTaxCodesSeeder;
use App\Database\Seeds\SatTaxFactorTypesSeeder;
use App\Database\Seeds\SatTaxObjectCodesSeeder;
use App\Database\Seeds\SatTaxRegimesSeeder;
use App\Database\Seeds\SatUnitKeysSeeder;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Seeder;
use Config\Database as DatabaseConfig;
use ReflectionObject;
use RuntimeException;

final class ExplicitConnectionSeederOrchestrator
{
    private const FORBIDDEN_DATABASES = ['ikontrol_new', 'fc2_migration_source', 'tws001_factucare'];

    /** @var list<class-string<Seeder>> */
    private const SAT_SEEDERS = [
        SatTaxCodesSeeder::class,
        SatTaxFactorTypesSeeder::class,
        SatTaxRegimesSeeder::class,
        SatCfdiUsesSeeder::class,
        SatProductServiceKeysSeeder::class,
        SatUnitKeysSeeder::class,
        SatTaxObjectCodesSeeder::class,
    ];

    /**
     * @return list<array{seeder: class-string<Seeder>, database: string, connection_id: int}>
     */
    public function runSatCatalogs(BaseConnection $connection, string $expectedDatabase): array
    {
        $this->assertConnection($connection, $expectedDatabase);
        $executions = [];
        foreach (self::SAT_SEEDERS as $class) {
            $this->assertConnection($connection, $expectedDatabase);
            $seeder = new $class(config(DatabaseConfig::class), $connection);
            $seederConnection = $this->extractConnection($seeder);
            if ($seederConnection !== $connection) {
                throw new RuntimeException("Seeder {$class} did not retain the orchestrator connection.");
            }
            $this->assertConnection($seederConnection, $expectedDatabase);
            $seeder->run();
            $this->assertConnection($seederConnection, $expectedDatabase);
            $executions[] = [
                'seeder' => $class,
                'database' => $expectedDatabase,
                'connection_id' => spl_object_id($seederConnection),
            ];
        }
        return $executions;
    }

    private function assertConnection(BaseConnection $connection, string $expectedDatabase): void
    {
        if ($expectedDatabase === '' || in_array($expectedDatabase, self::FORBIDDEN_DATABASES, true)) {
            throw new RuntimeException('Seeder target is empty or forbidden.');
        }
        $selected = (string) $connection->query('SELECT DATABASE() AS name')->getRow()->name;
        if ($selected !== $expectedDatabase || $connection->getDatabase() !== $expectedDatabase) {
            throw new RuntimeException("Seeder connection mismatch: expected {$expectedDatabase}, got {$selected}.");
        }
    }

    private function extractConnection(Seeder $seeder): BaseConnection
    {
        $reflection = new ReflectionObject($seeder);
        $property = $reflection->getProperty('db');
        $property->setAccessible(true);
        $connection = $property->getValue($seeder);
        if (! $connection instanceof BaseConnection) {
            throw new RuntimeException('Seeder has no valid database connection.');
        }
        return $connection;
    }
}
