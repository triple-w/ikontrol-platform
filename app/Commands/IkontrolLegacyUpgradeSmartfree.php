<?php
declare(strict_types=1);
namespace App\Commands;
use App\Services\Upgrade\Legacy\SmartfreePrefiscalBridgeService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
final class IkontrolLegacyUpgradeSmartfree extends BaseCommand {
 protected $group='iKontrol'; protected $name='ikontrol:legacy-upgrade:smartfree'; protected $description='Directed Smartfree prefiscal bridge; never runs global migrations.';
 public function run(array $params): int { $args=$_SERVER['argv']??[];$get=function(string $key)use($args):?string{foreach($args as $arg)if(is_string($arg)&&str_starts_with($arg,"--$key="))return substr($arg,strlen($key)+3);return null;};$has=fn(string $flag)=>in_array("--$flag",$args,true);$json=$has('json');try{$db=$get('database')??throw new \RuntimeException('--database=<local-copy> is required.');$template=$get('template-database')??'ikontrol20_clean';$service=new SmartfreePrefiscalBridgeService($db,$template,$get('prefix'));if($has('dry-run'))$result=$service->dryRun();elseif($has('execute')&&$has('yes'))$result=$service->execute();else throw new \RuntimeException('Use --dry-run or --execute --yes.');CLI::write($json?json_encode($result,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):print_r($result,true));return 0;}catch(\Throwable $e){CLI::error($e->getMessage());return 2;}}
}
