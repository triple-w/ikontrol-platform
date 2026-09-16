<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Database\DatabaseTargetGuard;
use App\Services\Database\ExplicitConnectionSeederOrchestrator;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use RuntimeException;

final class DbSeedCleanSat extends BaseCommand
{
    protected $group = 'Database';
    protected $name = 'db:seed-clean-sat';
    protected $description = 'Carga únicamente catálogos SAT mediante una conexión clean explícita.';
    protected $usage = 'db:seed-clean-sat --expected-database=ikontrol20_clean --allow-write-clean-build';
    protected $options = [
        '--expected-database' => 'Debe ser exactamente ikontrol20_clean',
        '--allow-write-clean-build' => 'Confirmación explícita requerida para habilitar escrituras',
    ];

    public function run(array $params): void
    {
        $expected = (string) $this->option($params, 'expected-database', '');
        if ($this->option($params, 'allow-write-clean-build', null) === null) {
            throw new RuntimeException('Falta la autorización explícita --allow-write-clean-build.');
        }
        $identity = (new DatabaseTargetGuard())->confirm('clean_build', $expected);
        foreach (['host', 'port', 'database', 'prefix', 'environment'] as $key) {
            CLI::write($key . '=' . $identity[$key]);
        }
        $connection = Database::connect('clean_build', false);
        $executions = (new ExplicitConnectionSeederOrchestrator())->runSatCatalogs($connection, $expected);
        foreach ($executions as $execution) {
            CLI::write('seeded=' . $execution['seeder'] . ' database=' . $execution['database']);
        }
        (new DatabaseTargetGuard())->confirm('clean_build', $expected);
        CLI::write('clean_sat_complete=1', 'green');
    }

    private function option(array $params, string $name, mixed $default = null): mixed
    {
        $value = CLI::getOption($name);
        if ($value !== null) return $value;
        foreach ($params as $key => $unused) {
            if (is_string($key) && str_starts_with($key, $name . '=')) {
                return substr($key, strlen($name) + 1);
            }
        }
        return $default;
    }
}
