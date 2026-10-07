<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Upgrade\LegacyBaselineAdoptionService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use RuntimeException;
use Throwable;

final class IkontrolAdoptBaseline extends BaseCommand
{
    protected $group = 'iKontrol';
    protected $name = 'ikontrol:adopt-baseline';
    protected $description = 'Adopta explícitamente una instancia RISE compatible como iKontrol 1.0.0; dry-run por defecto.';

    public function run(array $params): int
    {
        $args = $_SERVER['argv'] ?? [];
        $execute = in_array('--execute', $args, true);
        $json = in_array('--json', $args, true);
        try {
            if ($execute && ! in_array('--yes', $args, true)) {
                throw new RuntimeException('Use --yes después de revisar el dry-run.');
            }
            $service = new LegacyBaselineAdoptionService(db_connect());
            $result = $execute ? $service->adopt() : $service->inspect() + ['written'=>false];
            CLI::write($json ? json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : print_r($result, true));
            return $result['compatible'] ? 0 : 2;
        } catch (Throwable $error) {
            $payload = ['status'=>'ERROR','error'=>$error->getMessage()];
            CLI::write($json ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $error->getMessage());
            return 2;
        }
    }
}
