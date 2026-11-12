<?php
declare(strict_types=1);

namespace App\Commands;

use App\Services\Upgrade\FiscalDraftDocumentSchemaUpgradeService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use RuntimeException;
use Throwable;

final class IkontrolFiscalDraftDocumentsSchema extends BaseCommand
{
    protected $group='iKontrol';
    protected $name='ikontrol:fiscal-draft-documents-schema';
    protected $description='Checks or applies the directed, idempotent fiscal draft document index upgrade.';

    public function run(array $params): int
    {
        $args=$_SERVER['argv']??[];
        $apply=in_array('--apply',$args,true);
        try{
            if($apply&&!in_array('--yes',$args,true)) throw new RuntimeException('Use --yes después de revisar el diagnóstico read-only.');
            $service=new FiscalDraftDocumentSchemaUpgradeService(db_connect());
            $result=$apply?$service->apply():$service->inspect();
            CLI::write(json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));
            return(($result['after']['compatible']??$result['compatible']??false)===true)?0:2;
        }catch(Throwable $error){CLI::error($error->getMessage());return 2;}
    }
}
