<?php
declare(strict_types=1);
namespace App\Commands;
use App\Services\Fiscal\FiscalIntegrationStatusService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

final class FiscalIntegrationStatus extends BaseCommand
{
    protected $group='Fiscal';protected $name='fiscal:integration:status';
    protected $description='Muestra el estado seguro del entorno PAC de integración, sin secretos.';
    public function run(array$params):void
    {
        helper(['general','date_time','currency','plugin']);
        require_once APPPATH.'ThirdParty/PHP-Hooks/php-hooks.php';
        $draftId=filter_var($params[0]??3,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:3;
        foreach((new FiscalIntegrationStatusService())->inspect((int)$draftId)as$key=>$value)CLI::write($key.': '.(is_bool($value)?($value?'sí':'no'):($value??'—')),str_starts_with($key,'ready')&&$value?'green':null);
    }
}
