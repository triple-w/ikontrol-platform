<?php
declare(strict_types=1);
namespace App\Commands;
use App\Services\Fiscal\ProductFiscalConfigurationAuditService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use RuntimeException;
final class FiscalProductsAudit extends BaseCommand
{
 protected$group='Fiscal';
 protected$name='fiscal:products-audit';
 protected$description='Audita configuraciones fiscales de productos; dry-run por defecto.';
 protected$usage='fiscal:products-audit [--repair --actor-id=ID] [--format=json]';
 protected$options=['--repair'=>'Repara solo LEGACY_REPAIRABLE','--actor-id'=>'Usuario responsable requerido','--format'=>'console|json'];
 public function run(array$params):void
 {
  $service=new ProductFiscalConfigurationAuditService();$rows=$service->auditAll();$repair=CLI::getOption('repair')!==null;
  if($repair){$actor=(int)CLI::getOption('actor-id');if($actor<1)throw new RuntimeException('--actor-id es obligatorio para reparar.');foreach($rows as$row)if($row['classification']==='LEGACY_REPAIRABLE')$service->repair($row,$actor);$rows=$service->auditAll();}
  $summary=[];foreach($rows as$row)$summary[$row['classification']]=($summary[$row['classification']]??0)+1;
  if(CLI::getOption('format')==='json')CLI::write(json_encode(['dry_run'=>!$repair,'summary'=>$summary,'products'=>$rows],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
  else{CLI::write(($repair?'REPAIR':'DRY-RUN').' '.json_encode($summary));foreach($rows as$row)if($row['classification']!=='READY')CLI::write($row['classification'].' #'.$row['product_id'].' '.$row['product'].': '.implode(', ',$row['issues']));}
 }
}
