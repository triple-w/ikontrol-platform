<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Fiscal\LegacyFiscalEnvironmentNormalizer;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use RuntimeException;
use Throwable;

final class IkontrolFiscalNormalizeLegacyEnvironment extends BaseCommand
{
    protected $group = 'iKontrol';
    protected $name = 'ikontrol:fiscal:normalize-legacy-environment';
    protected $description = 'Normalizes legacy issuer profiles to the configured fiscal environment without migrations.';

    public function run(array $params): int
    {
        $args = $_SERVER['argv'] ?? [];
        $json = in_array('--json', $args, true);
        $execute = in_array('--execute', $args, true);
        try {
            if ($execute && ! in_array('--yes', $args, true)) {
                throw new RuntimeException('Use --yes después de revisar el dry-run.');
            }
            $result = (new LegacyFiscalEnvironmentNormalizer())->normalize(! $execute);
            CLI::write($json
                ? json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : print_r($result, true));
            return 0;
        } catch (Throwable $error) {
            CLI::write($json
                ? json_encode(['error'=>$error->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : $error->getMessage());
            return 2;
        }
    }
}
