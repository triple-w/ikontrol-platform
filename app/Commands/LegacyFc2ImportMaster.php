<?php
declare(strict_types=1);
namespace App\Commands;
use App\Services\Legacy\Fc2\Fc2MasterDataPreviewImporter;
use App\Services\Legacy\PreviewDatabaseTargetGuard;
use CodeIgniter\CLI\BaseCommand;use CodeIgniter\CLI\CLI;use RuntimeException;
final class LegacyFc2ImportMaster extends BaseCommand
{
 protected $group='Legacy';protected $name='legacy:fc2-import-master';protected $description='Imports FC2 master data into the isolated DOLD preview database.';
 protected $usage='legacy:fc2-import-master <RFC> --owner-id=15 --target=dold_preview --dry-run|--execute --confirm-database=ikontrol20_dold_preview';
 public function run(array$params):void{helper(['general','date_time']);$rfc=trim((string)($params[0]??''));$owner=(int)$this->option($params,'owner-id',0);$target=(string)$this->option($params,'target','');$execute=$this->has($params,'execute');$dry=$this->has($params,'dry-run');if($rfc===''||$owner<1||$target!=='dold_preview'||$execute===$dry)throw new RuntimeException('RFC, owner, explicit target and exactly one mode are required.');$db=db_connect('dold_preview',false);$confirmed=$execute?(string)$this->option($params,'confirm-database',''):'ikontrol20_dold_preview';$identity=(new PreviewDatabaseTargetGuard($db,'dold_preview'))->verify($confirmed);CLI::write('Target: '.$identity['database'].'; prefix: '.$identity['prefix']);$result=(new Fc2MasterDataPreviewImporter(db_connect('fc2_legacy',false),$db))->run($rfc,$owner,$execute);CLI::write(json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));}
 private function option(array$params,string$name,mixed$default=null):mixed{$v=CLI::getOption($name);if($v!==null)return$v;foreach($params as$k=>$unused)if(is_string($k)&&str_starts_with($k,$name.'='))return substr($k,strlen($name)+1);return$default;}
 private function has(array$params,string$name):bool{if(CLI::getOption($name)!==null)return true;return array_key_exists($name,$params)||in_array('--'.$name,$params,true);}
}
