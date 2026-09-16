<?php
declare(strict_types=1);

namespace App\Commands;

use App\Services\Fiscal\Stamps\FiscalStampAccountService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use RuntimeException;

final class FiscalIntegrationCreditsSync extends BaseCommand
{
    protected $group='Fiscal';protected $name='fiscal:integration:credits-sync';
    protected $description='Sincroniza desde una consulta PAC durable existente; no realiza llamadas externas.';
    protected $usage='fiscal:integration:credits-sync <consultation_id> PAC-DEVELOPMENT-SYNC <actor_id>';
    public function run(array$params):void
    {
        throw new RuntimeException('PAC_PROVIDER_CREDITS_MUST_NOT_MUTATE_CLIENT_WALLET');
        /* Legacy command retained as an explicit fail-closed compatibility entry.
        helper(['general','date_time']);$id=(int)($params[0]??0);$confirm=(string)($params[1]??'');$actor=(int)($params[2]??0);
        if($id<1||$actor<1||$confirm!=='PAC-DEVELOPMENT-SYNC')throw new RuntimeException('Consulta, confirmación y actor son obligatorios.');
        $db=db_connect();$row=$db->table('fiscal_pac_credit_consultations')->where(['id'=>$id,'provider'=>'timbradorxpress','environment'=>'development'])->get(1)->getRow();
        if(!$row)throw new RuntimeException('Consulta development no encontrada.');
        $movement=(new FiscalStampAccountService($db))->synchronizeDevelopment((int)$row->issuer_profile_id,(int)$row->available_credits,$id,$actor);
        CLI::write('external_calls: 0');CLI::write('reason: '.$movement->reason);CLI::write('adjustment: '.$movement->quantity);
        CLI::write('available_after: '.$movement->available_after);CLI::write('reserved_after: '.$movement->reserved_after);
        */
    }
}
