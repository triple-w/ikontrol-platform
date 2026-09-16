<?php
declare(strict_types=1);
namespace App\Commands;
use App\Services\Fiscal\Stamps\FiscalStampAccountService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use RuntimeException;
final class FiscalStampsAdjust extends BaseCommand{
 protected$group='Fiscal';protected$name='fiscal:stamps-adjust';protected$description='Asigna o retira timbres mediante un movimiento inmutable.';
 protected$usage='fiscal:stamps-adjust <RFC> <cantidad> --reason="..." [--reference="..."] --dry-run|--execute [--confirm-rfc=<RFC>] [--actor=<email>]';
 public function run(array$params):void{
  $rfc=strtoupper(trim((string)($params[0]??'')));$raw=(string)($params[1]??'');if(!preg_match('/^-?[1-9][0-9]*$/',$raw))throw new RuntimeException('La cantidad debe ser un entero distinto de cero.');$qty=(int)$raw;
  $reason=trim((string)CLI::getOption('reason'));$reference=trim((string)CLI::getOption('reference'));$dry=CLI::getOption('dry-run')!==null;$execute=CLI::getOption('execute')!==null;
  if($rfc===''||$reason===''||$dry===$execute)throw new RuntimeException('Indique RFC, motivo y exactamente uno de --dry-run o --execute.');
  $db=Database::connect('default',false);$profiles=$db->table('fiscal_profiles')->where(['rfc'=>$rfc,'profile_type'=>'issuer'])->get()->getResult();if(count($profiles)!==1)throw new RuntimeException('El RFC emisor no existe o es ambiguo.');$issuer=(int)$profiles[0]->id;$service=new FiscalStampAccountService($db);$before=$service->getBalance($issuer);
  CLI::write('Saldo disponible actual: '.$before['available']);CLI::write('Saldo disponible previsto: '.($before['available']+$qty));if($before['available']+$qty<0)throw new RuntimeException('El ajuste dejaría saldo negativo.');if($dry){CLI::write('DRY-RUN: no se realizaron escrituras.','yellow');return;}
  if(strtoupper((string)CLI::getOption('confirm-rfc'))!==$rfc)throw new RuntimeException('Debe confirmar el RFC mediante --confirm-rfc.');$actor=$this->platformActor($db,trim((string)CLI::getOption('actor')));
  if(strtolower(trim(CLI::prompt('Escriba SI para confirmar'))) !== 'si')throw new RuntimeException('Operación cancelada.');
  $key='manual:'.hash('sha256',implode('|',[$issuer,$qty,$reason,$reference]));$movement=$qty>0?$service->allocate($issuer,$qty,$reason,$actor,$key,$reference?:null):$service->adjust($issuer,$qty,$reason,$actor,$key,$reference?:null);
  CLI::write('Saldo disponible final: '.$movement->available_after,'green');
 }
 private function platformActor($db,string$id):int{if($id==='')throw new RuntimeException('La ejecución exige --actor=<correo o ID> de plataforma.');$b=$db->table('users')->where('deleted',0);ctype_digit($id)?$b->where('id',(int)$id):$b->where('email',$id);$u=$b->get(1)->getRow();if(!$u||(int)$u->is_platform_superadmin!==1)throw new RuntimeException('El actor no es superadministrador de plataforma.');$p=@unserialize((string)$u->permissions);if(!is_array($p)||empty($p['platform.fiscal_stamps.manage']))throw new RuntimeException('El actor no tiene platform.fiscal_stamps.manage.');return(int)$u->id;}
}
