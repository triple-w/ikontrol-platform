<?php
declare(strict_types=1);

namespace App\Commands;

use App\Services\Fiscal\FiscalStampAttemptDiagnosticService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use RuntimeException;

final class IkontrolFiscalStampAttemptDiagnose extends BaseCommand
{
    protected $group='iKontrol';
    protected $name='ikontrol:fiscal-stamp-attempt-diagnose';
    protected $description='Read-only, non-secret diagnosis of one fiscal stamping attempt.';
    protected $usage='ikontrol:fiscal-stamp-attempt-diagnose <attempt-id>';
    public function run(array$params):int
    {
        $id=(int)($params[0]??0);if($id<1)throw new RuntimeException('Indique un attempt-id válido.');
        $result=(new FiscalStampAttemptDiagnosticService(db_connect()))->inspect($id);
        CLI::write(json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));
        return($result['status']??'')==='OK'?0:2;
    }
}
