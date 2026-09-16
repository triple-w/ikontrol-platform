<?php
declare(strict_types=1);

namespace App\Commands;

use App\Services\Fiscal\FiscalDraftStampingService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use RuntimeException;
use Throwable;

final class FiscalIntegrationStamp extends BaseCommand
{
    protected $group='Fiscal';
    protected $name='fiscal:integration:stamp';
    protected $description='Preflight o ejecución explícita, sin reintento, de un borrador integration contra PAC development.';
    protected $usage='fiscal:integration:stamp <draft_id> [--confirm TIMBRAR-DESARROLLO]';

    public function run(array $params):void
    {
        helper(['general','date_time','currency','plugin']);
        require_once APPPATH.'ThirdParty/PHP-Hooks/php-hooks.php';
        try{
            $draftId=filter_var($params[0]??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if(!$draftId)throw new RuntimeException('Indica un draft_id válido.');
            $db=db_connect();$draft=$db->table('fiscal_drafts')->where('id',(int)$draftId)->get(1)->getRow();
            if(!$draft||$draft->environment!=='development')
                throw new RuntimeException('El borrador no pertenece al ambiente development.');
            $service=new FiscalDraftStampingService($db);$preflight=$service->preflight((int)$draftId);
            CLI::write('draft_id: '.$draftId);CLI::write('preflight: '.($preflight['allowed']?'aprobado':'bloqueado'));
            foreach($preflight['errors'] as$error)CLI::error($error);
            if(!$preflight['allowed'])return;
            if((string)CLI::getOption('confirm')!=='TIMBRAR-DESARROLLO'){
                CLI::write('No se realizó ninguna llamada externa.','yellow');
                CLI::write('Para ejecutar una sola vez: php spark fiscal:integration:stamp '.$draftId.' --confirm TIMBRAR-DESARROLLO');
                return;
            }
            $user=(int)($db->table('users')->where(['is_admin'=>1,'deleted'=>0])->orderBy('id')->get(1)->getRow()->id??0);
            if(!$user)throw new RuntimeException('No existe un administrador habilitado.');
            $result=$service->stamp((int)$draftId,$user,true);
            CLI::write('document_id: '.$result['document_id']);
            CLI::write('success: '.(($result['result']['success']??false)?'sí':'no'));
            CLI::write('status: '.($result['result']['status']??'unknown'));
            CLI::write('uuid_configured: '.(!empty($result['result']['uuid'])?'sí':'no'));
            CLI::write('xml_available: '.(!empty($result['result']['xmlAvailable'])?'sí':'no'));
        }catch(Throwable$error){CLI::error($error->getMessage());}
    }
}
