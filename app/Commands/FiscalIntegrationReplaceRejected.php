<?php
declare(strict_types=1);
namespace App\Commands;
use App\Services\Fiscal\FiscalRejectedDraftReplacementService;use CodeIgniter\CLI\BaseCommand;use CodeIgniter\CLI\CLI;use RuntimeException;
final class FiscalIntegrationReplaceRejected extends BaseCommand
{
 protected $group='Fiscal';protected $name='fiscal:integration:replace-rejected';protected $description='Reemplaza localmente una preparación PAC rechazada y valida el nuevo Pre-XML, sin red.';
 public function run(array$params):void{helper(['general','date_time','currency','plugin']);require_once APPPATH.'ThirdParty/PHP-Hooks/php-hooks.php';$draftId=(int)($params[0]??0);if($draftId<1)throw new RuntimeException('Indica el draft rechazado.');$db=db_connect();$user=(int)($db->table('users')->where(['is_admin'=>1,'deleted'=>0])->orderBy('id')->get(1)->getRow()->id??0);if(!$user)throw new RuntimeException('No existe administrador habilitado.');$r=(new FiscalRejectedDraftReplacementService($db))->prepare($draftId,$user);foreach($r as$key=>$value)CLI::write($key.': '.(is_array($value)?json_encode($value):$value));CLI::write('PAC calls: 0','green');}
}
