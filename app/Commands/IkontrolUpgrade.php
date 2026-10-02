<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Upgrade\InstanceUpgradeService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use RuntimeException;
use Throwable;

final class IkontrolUpgrade extends BaseCommand
{
    protected $group = 'iKontrol';
    protected $name = 'ikontrol:upgrade';
    protected $description = 'Executes an explicit, resumable iKontrol release upgrade.';

    public function run(array $params): int
    {
        $args = $_SERVER['argv'] ?? [];
        $json = in_array('--json', $args, true);
        try {
            if (! in_array('--yes', $args, true)) throw new RuntimeException('Use --yes after reviewing ikontrol:upgrade:plan.');
            $target = null;
            foreach ($args as $arg) if (is_string($arg) && str_starts_with($arg, '--target=')) $target = substr($arg, 9);
            $result = (new InstanceUpgradeService(db_connect()))->execute($target);
            CLI::write($json ? json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : print_r($result, true));
            return 0;
        } catch (Throwable $error) {
            CLI::write($json ? json_encode(['error' => $error->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $error->getMessage());
            return 2;
        }
    }
}
