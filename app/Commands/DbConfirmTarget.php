<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Database\DatabaseTargetGuard;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

final class DbConfirmTarget extends BaseCommand
{
    protected $group = 'Database';
    protected $name = 'db:confirm-target';
    protected $description = 'Confirma explícitamente el destino aislado antes de cualquier build limpio.';
    protected $usage = 'db:confirm-target --expected=ikontrol20_clean --group=clean_build';
    protected $options = ['--expected' => 'Base exacta esperada', '--group' => 'Grupo dedicado esperado'];

    public function run(array $params): void
    {
        $expected = (string) $this->option($params, 'expected', '');
        $group = (string) $this->option($params, 'group', 'clean_build');
        $result = (new DatabaseTargetGuard())->confirm($group, $expected);
        foreach (['host', 'port', 'database', 'get_database', 'prefix', 'environment'] as $key) {
            CLI::write($key . '=' . $result[$key]);
        }
        CLI::write('target_confirmed=1', 'green');
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
