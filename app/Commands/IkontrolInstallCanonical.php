<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Database\CanonicalCleanInstallService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use RuntimeException;

final class IkontrolInstallCanonical extends BaseCommand
{
    protected $group = 'iKontrol';
    protected $name = 'ikontrol:install-canonical';
    protected $description = 'Instala iKontrol en una base MySQL vacía mediante el plan canónico dirigido.';
    protected $usage = 'ikontrol:install-canonical --group=default --expected-database=NAME --admin-name="Nombre" --admin-email=admin@example.test --allow-empty-install';
    protected $options = [
        '--group' => 'Grupo de conexión configurado; default por omisión.',
        '--expected-database' => 'Nombre exacto de la base vacía.',
        '--admin-name' => 'Nombre del administrador inicial.',
        '--admin-email' => 'Correo del administrador inicial.',
        '--allow-empty-install' => 'Confirmación explícita: importa el baseline y escribe el schema.',
    ];

    public function run(array $params): void
    {
        if ($this->option('allow-empty-install') === null) {
            throw new RuntimeException('Pass --allow-empty-install to write the verified empty target.');
        }
        $group = (string) $this->option('group', 'default');
        $expected = (string) $this->option('expected-database');
        $name = trim((string) $this->option('admin-name'));
        $email = strtolower(trim((string) $this->option('admin-email')));
        if ($expected === '' || $name === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('expected-database, admin-name and a valid admin-email are required.');
        }
        $password = rtrim((string) fgets(STDIN), "\r\n");
        if (strlen($password) < 12) {
            throw new RuntimeException('The initial administrator password must contain at least 12 characters.');
        }
        $parts = preg_split('/\s+/', $name, 2) ?: [];
        $db = Database::connect($group, false);
        $result = (new CanonicalCleanInstallService())->install($db, $group, $expected, [
            'first_name' => $parts[0],
            'last_name' => $parts[1] ?? '',
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);
        unset($password);
        CLI::write(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function option(string $name, mixed $default = null): mixed
    {
        return CLI::getOption($name) ?? $default;
    }
}
