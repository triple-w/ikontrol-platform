<?php
declare(strict_types=1);
namespace App\Commands;
use App\Services\Fiscal\FiscalIntegrationStatusService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use RuntimeException;
use Throwable;

final class FiscalIntegrationPrepare extends BaseCommand
{
    protected $group='Fiscal';protected $name='fiscal:integration:prepare';
    protected $description='Prepara de forma idempotente emisor, serie y datos existentes para integración real.';
    public function run(array$params):void
    {
        $db=db_connect();$f=config('Fiscal');$pac=config('TimbradorXpress');$pdf=config('FiscalPdfProvider');
        try{
            if($f->runtimeMode!=='integration'||$f->pacAdapter!=='timbradorxpress'||!$f->allowRealPac||$f->environment!=='development')throw new RuntimeException('Configura runtimeMode=integration, PAC real y environment=development.');
            if(!$pac->isConfigured())throw new RuntimeException('Falta la API key PAC de development.');
            if(!$pdf->enabled||$pdf->username===''||$pdf->password===''||$pdf->wsdl==='')throw new RuntimeException('Faltan credenciales o WSDL de WSTools33.');
            $issuer=$db->table('fiscal_profiles')->where('profile_type','issuer')->whereIn('status',['active','ready'])->orderBy('is_default','DESC')->get(1)->getRow();
            if(!$issuer)throw new RuntimeException('Falta un emisor activo.');
            $certificate=$db->table('fiscal_issuer_certificates')->where(['issuer_profile_id'=>$issuer->id,'status'=>'valid','deleted'=>0])->get(1)->getRow();
            if(!$certificate)throw new RuntimeException('Falta un CSD válido.');
            (new \App\Services\Fiscal\Signing\CsdCertificateSecretService($db))->passwordForSigning((int)$certificate->id,0);
            if(strtoupper((string)$certificate->certificate_rfc)!==strtoupper((string)$issuer->rfc))throw new RuntimeException('El RFC del CSD no coincide con el emisor.');
            if(!$db->table('fiscal_profiles')->where(['profile_type'=>'receiver'])->whereIn('status',['active','ready'])->countAllResults())throw new RuntimeException('Falta un cliente fiscal completo.');
            if(!$db->table('item_fiscal_settings')->whereIn('status',['active','ready'])->where('deleted',0)->countAllResults())throw new RuntimeException('Falta un producto con configuración fiscal.');
            $db->transBegin();
            $now=date('Y-m-d H:i:s');
            $db->table('fiscal_profiles')->where('id',$issuer->id)->update(['environment'=>'development','updated_at'=>$now]);
            $series=$db->table('fiscal_series')->where(['issuer_profile_id'=>$issuer->id,'series'=>'TEST','document_type'=>'income','deleted'=>0])->get(1)->getRow();
            if($series)$db->table('fiscal_series')->where('id',$series->id)->update(['environment'=>'development','is_active'=>1,'updated_at'=>$now]);
            else{$db->table('fiscal_series')->insert(['issuer_profile_id'=>$issuer->id,'document_type'=>'income','series'=>'TEST','environment'=>'development','initial_folio'=>1,'current_folio'=>0,'is_default'=>0,'is_active'=>1,'deleted'=>0,'created_at'=>$now,'updated_at'=>$now]);}
            $db->table('fiscal_profiles')->where('profile_type','receiver')->whereIn('status',['active','ready'])->update(['environment'=>'development']);
            if(!$db->transStatus())throw new RuntimeException('No fue posible preparar los datos de integración.');
            $db->transCommit();$status=(new FiscalIntegrationStatusService($db))->inspect();
            CLI::write('Preparación idempotente completada.','green');CLI::write('issuer_id: '.$status['issuer_id']);CLI::write('series_id: '.$status['series_id']);CLI::write('ready: '.($status['ready']?'sí':'no'));
        }catch(Throwable$e){$db->transRollback();CLI::error($e->getMessage());}
    }
}
