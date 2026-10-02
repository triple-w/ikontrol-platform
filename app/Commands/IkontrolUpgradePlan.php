<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Upgrade\InstanceUpgradeService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

final class IkontrolUpgradePlan extends BaseCommand
{
    protected $group = 'iKontrol';
    protected $name = 'ikontrol:upgrade:plan';
    protected $description = 'Builds a read-only directed upgrade plan.';

    public function run(array $params): int
    {
        $target = $this->option('target');
        $plan = (new InstanceUpgradeService(db_connect()))->plan($target);
        CLI::write(in_array('--json', $_SERVER['argv'] ?? [], true) ? json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : print_r($plan, true));
        return $plan['compatible'] ? 0 : 2;
    }

    private function option(string $name): ?string
    {
        foreach ($_SERVER['argv'] ?? [] as $arg) if (is_string($arg) && str_starts_with($arg, "--{$name}=")) return substr($arg, strlen($name) + 3);
        return null;
    }
}
