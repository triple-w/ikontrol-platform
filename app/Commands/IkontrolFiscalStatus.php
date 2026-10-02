<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Fiscal\FiscalInstanceModeService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

final class IkontrolFiscalStatus extends BaseCommand
{
    protected $group = 'iKontrol';
    protected $name = 'ikontrol:fiscal:status';
    protected $description = 'Reports the safe fiscal mode and blockers without exposing secrets.';

    public function run(array $params): int
    {
        $json = in_array('--json', $_SERVER['argv'] ?? [], true);
        try {
            $status = (new FiscalInstanceModeService())->inspect();
            CLI::write($json ? json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : print_r($status, true));
            return $status['mode'] === FiscalInstanceModeService::PRODUCTION ? 0 : 1;
        } catch (Throwable $error) {
            $payload = ['mode' => 'INVALID_CONFIGURATION', 'error' => $error->getMessage()];
            CLI::write($json ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : print_r($payload, true));
            return 2;
        }
    }
}
