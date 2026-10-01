<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Version;

final class IkontrolVersion extends BaseCommand
{
    protected $group = 'iKontrol';
    protected $name = 'ikontrol:version';
    protected $description = 'Muestra la versión canónica de iKontrol.';

    public function run(array $params): void
    {
        CLI::write(json_encode([
            'version' => Version::VERSION,
            'release_date' => Version::RELEASE_DATE,
            'release_ref' => Version::RELEASE_REF,
        ], JSON_UNESCAPED_SLASHES));
    }
}
