<?php
declare(strict_types=1);

namespace App\Commands;

use App\Services\Fiscal\FiscalIssuerResolver;
use App\Services\Fiscal\Pac\FiscalPacCreditService;
use App\Services\Fiscal\Stamps\FiscalStampAccountService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use RuntimeException;

final class FiscalIntegrationCredits extends BaseCommand
{
    protected $group='Fiscal';
    protected $name='fiscal:integration:credits';
    protected $description='Consulta créditos PAC development y opcionalmente sincroniza el control local con auditoría.';
    protected $usage='fiscal:integration:credits [--sync --confirm PAC-DEVELOPMENT-SYNC --actor=<id>]';

    public function run(array $params):void
    {
        helper(['general','date_time']);$db=db_connect();
        if($db->getDatabase()!=='ikontrol20_dold_preview')throw new RuntimeException('La base activa no es la base development canónica.');
        $issuer=(new FiscalIssuerResolver($db))->resolve(null,'development');
        if(!$issuer)throw new RuntimeException('No existe emisor development efectivo.');
        $actor=(int)(CLI::getOption('actor')?:0);if($actor<1)$actor=null;
        $sync=CLI::getOption('sync')!==null;
        if($sync)throw new RuntimeException('PAC_PROVIDER_CREDITS_MUST_NOT_MUTATE_CLIENT_WALLET');
        if($sync&&((string)CLI::getOption('confirm')!=='PAC-DEVELOPMENT-SYNC'||$actor===null))throw new RuntimeException('La sincronización exige --confirm=PAC-DEVELOPMENT-SYNC y --actor=<id>.');
        $before=(new FiscalStampAccountService($db))->getBalance((int)$issuer->id,'development');
        $result=(new FiscalPacCreditService($db))->consult((int)$issuer->id,$actor);
        CLI::write('provider: '.$result['provider']);CLI::write('environment: '.$result['environment']);
        CLI::write('available_credits: '.$result['available_credits']);CLI::write('provider_code: '.($result['provider_code']??'—'));
        CLI::write('provider_message: '.($result['provider_message']?:'—'));CLI::write('consulted_at: '.$result['consulted_at']);
        CLI::write('local_available_before: '.$before['available']);CLI::write('local_reserved_before: '.$before['reserved']);
        if($sync){
            $movement=(new FiscalStampAccountService($db))->synchronizeDevelopment((int)$issuer->id,(int)$result['available_credits'],(int)$result['consultation_id'],$actor);
            CLI::write('sync_reason: '.$movement->reason);CLI::write('sync_adjustment: '.$movement->quantity);
            $after=(new FiscalStampAccountService($db))->getBalance((int)$issuer->id,'development');
            CLI::write('local_available_after: '.$after['available']);CLI::write('local_reserved_after: '.$after['reserved']);
        }
    }
}
