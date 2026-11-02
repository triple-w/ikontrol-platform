<?php
declare(strict_types=1);

namespace App\Commands;

use App\Services\Upgrade\Fiscal116CompatibilityService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

final class IkontrolFiscal116Readiness extends BaseCommand
{
    protected $group='iKontrol';
    protected $name='ikontrol:fiscal-116-readiness';
    protected $description='Read-only, non-secret compatibility evidence for iKontrol 1.1.6.';
    public function run(array$params):int{$result=(new Fiscal116CompatibilityService(db_connect()))->inspect();CLI::write("IKONTROL_JSON_BEGIN\n".json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\nIKONTROL_JSON_END");return$result['status']==='READY'?0:2;}
}
