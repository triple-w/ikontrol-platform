<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Sales\SaleStatusNormalizer;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use RuntimeException;
use Throwable;

final class IkontrolSalesNormalizeStatuses extends BaseCommand
{
    protected $group = 'iKontrol';
    protected $name = 'ikontrol:sales:normalize-statuses';
    protected $description = 'Normaliza ventas históricas closed + draft usando aplicaciones y notas de crédito; dry-run por defecto.';

    public function run(array $params): int
    {
        $args = $_SERVER['argv'] ?? [];
        $execute = in_array('--execute', $args, true);
        $json = in_array('--json', $args, true);
        try {
            if ($execute && ! in_array('--yes', $args, true)) {
                throw new RuntimeException('Use --yes después de revisar el dry-run.');
            }
            $result = (new SaleStatusNormalizer())->normalize(! $execute);
            CLI::write($json ? json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : print_r($result, true));
            return 0;
        } catch (Throwable $error) {
            CLI::write($json ? json_encode(['error'=>$error->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $error->getMessage());
            return 2;
        }
    }
}
