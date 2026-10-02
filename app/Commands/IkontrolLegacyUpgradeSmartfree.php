<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Upgrade\Legacy\SmartfreePrefiscalBridgeService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use RuntimeException;
use Throwable;

final class IkontrolLegacyUpgradeSmartfree extends BaseCommand
{
    protected $group = 'iKontrol';
    protected $name = 'ikontrol:legacy-upgrade:smartfree';
    protected $description = 'Directed self-contained Smartfree prefiscal bridge; never runs global migrations.';

    public function run(array $params): int
    {
        $args = $_SERVER['argv'] ?? [];
        $get = static function (string $key) use ($args): ?string {
            foreach ($args as $arg) {
                if (is_string($arg) && str_starts_with($arg, "--{$key}=")) return substr($arg, strlen($key) + 3);
            }
            return null;
        };
        $has = static fn (string $flag): bool => in_array("--{$flag}", $args, true);
        $json = $has('json');

        try {
            $database = $get('database') ?? throw new RuntimeException('--database=<local-copy> is required.');
            $service = new SmartfreePrefiscalBridgeService($database, $get('prefix'));
            if ($has('dry-run')) $result = $service->dryRun();
            elseif ($has('execute') && $has('yes')) $result = $service->execute();
            else throw new RuntimeException('Use --dry-run or --execute --yes.');
            CLI::write($json ? json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : print_r($result, true));
            return 0;
        } catch (Throwable $error) {
            CLI::error($error->getMessage());
            return 2;
        }
    }
}
