<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Database\DatabaseTargetGuard;
use App\Services\Database\ExplicitConnectionSeederOrchestrator;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Config\Services;
use RuntimeException;

final class DbBuildClean extends BaseCommand
{
    protected $group = 'Database';
    protected $name = 'db:build-clean';
    protected $description = 'Aplica migraciones y catálogos exclusivamente a ikontrol20_clean.';
    protected $usage = 'db:build-clean --expected-database=ikontrol20_clean --allow-write-clean-build';
    protected $options = [
        '--expected-database' => 'Debe ser exactamente ikontrol20_clean',
        '--allow-write-clean-build' => 'Confirmación explícita requerida para habilitar escrituras',
    ];

    public function run(array $params): void
    {
        $expected = (string) $this->option($params, 'expected-database', '');
        $allowWrite = $this->option($params, 'allow-write-clean-build', null);
        if ($allowWrite === null || $allowWrite === false) {
            throw new RuntimeException('Falta la autorización explícita --allow-write-clean-build.');
        }
        $guard = new DatabaseTargetGuard();
        $identity = $guard->confirm('clean_build', $expected);
        foreach (['host', 'port', 'database', 'prefix', 'environment'] as $key) {
            CLI::write($key . '=' . $identity[$key]);
        }

        // A shared runner may already be bound to the default connection. Build a
        // fresh runner around the guarded clean connection so group filtering can
        // never be mistaken for connection selection.
        $clean = Database::connect('clean_build', false);
        $runner = Services::migrations(config('Migrations'), $clean, false);
        $runner->clearCliMessages();
        if (! $runner->latest('clean_build')) {
            throw new RuntimeException('No fue posible completar las migraciones de clean_build.');
        }
        foreach ($runner->getCliMessages() as $message) CLI::write($message);

        $guard->confirm('clean_build', $expected);
        (new ExplicitConnectionSeederOrchestrator())->runSatCatalogs($clean, $expected);

        $guard->confirm('clean_build', $expected);
        CLI::write('clean_build_complete=1', 'green');
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
