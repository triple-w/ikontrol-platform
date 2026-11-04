<?php
declare(strict_types=1);

namespace App\Commands;

use App\Services\Fiscal\Signing\CsdEncryptionConfigurationService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

final class IkontrolCsdConfigCheck extends BaseCommand
{
    protected $group='iKontrol';
    protected $name='ikontrol:csd-config-check';
    protected $description='Read-only, non-secret validation of the CSD encryption configuration.';
    public function run(array $params): int
    {
        $result=(new CsdEncryptionConfigurationService(db_connect()))->inspect();
        CLI::write(json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));
        return $result['ready'] ? 0 : 2;
    }
}
