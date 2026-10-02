<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Instance\InstanceVersionService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

final class IkontrolVersion extends BaseCommand
{
    protected $group = 'iKontrol';
    protected $name = 'ikontrol:version';
    protected $description = 'Reports installed and canonical iKontrol versions.';

    public function run(array $params): int
    {
        $service = new InstanceVersionService(db_connect());
        $payload = ['current_version' => $service->current(), 'canonical_version' => $service->canonical()];
        CLI::write(in_array('--json', $_SERVER['argv'] ?? [], true) ? json_encode($payload, JSON_UNESCAPED_SLASHES) : print_r($payload, true));
        return 0;
    }
}
