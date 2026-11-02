<?php
declare(strict_types=1);

use App\Services\Upgrade\Fiscal116SchemaContract;
use Ikontrol\LegacyBootstrap\CanonicalFileHasher;

$root=dirname(__DIR__,2);
require_once $root.'/app/Services/Upgrade/Fiscal116SchemaContract.php';
require_once $root.'/tools/legacy-bootstrap/CanonicalFileHasher.php';
$commit='3c4a65d00d833743378ce526da7e5891da7509f9';
$scopes=['app/Commands','app/Config/Fiscal.php','app/Config/FiscalRoutes.php','app/Config/TimbradorXpress.php','app/Config/Version.php','app/Controllers/Fiscal','app/Database/Migrations','app/FiscalServices','app/Models/Fiscal','app/Services/Fiscal'];
$run=static function(array$command)use($root):string{$descriptors=[1=>['pipe','w'],2=>['pipe','w']];$process=proc_open($command,$descriptors,$pipes,$root);if(!is_resource($process))throw new RuntimeException('GIT_PROCESS_FAILED');$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);if($code!==0)throw new RuntimeException('GIT_COMMAND_FAILED:'.trim((string)$err));return(string)$out;};
$paths=preg_split('/\R/',trim($run(array_merge(['git','ls-tree','-r','--name-only',$commit,'--'],$scopes))))?:[];$files=[];
foreach($paths as$path){if($path==='')continue;$mode=CanonicalFileHasher::mode($path);$bytes=$run(['git','show',$commit.':'.$path]);$files[$path]=['sha256'=>CanonicalFileHasher::hashString($bytes,$path),'hash_mode'=>$mode];}
ksort($files);$treeHash=hash('sha256',json_encode($files,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
$tables=[];foreach(Fiscal116SchemaContract::PREREQUISITE_COLUMNS as$table=>$columns)$tables[$table]=['columns'=>$columns,'indexes'=>Fiscal116SchemaContract::PREREQUISITE_INDEXES[$table]??[]];
$contract=['schema_version'=>1,'inspector_version'=>'1.0.0','target_version'=>'1.1.6','source'=>['version'=>'1.1.5','commit'=>$commit,'tree_sha256'=>$treeHash],'scopes'=>$scopes,'files'=>$files,'required_schema'=>['tables'=>$tables],'upgrade_schema'=>['tables'=>Fiscal116SchemaContract::NEW_TABLES],'result_states'=>['COMPATIBLE','REVIEW_REQUIRED','BLOCKED','NOT_VERIFIED']];
$bundleDirectory=$root.'/updates/1.1.6/preflight';
if(!is_dir($bundleDirectory)&&!mkdir($bundleDirectory,0775,true)&&!is_dir($bundleDirectory))throw new RuntimeException('BUNDLE_DIRECTORY_CREATE_FAILED');
$output=$bundleDirectory.'/contract-1.1.6.json';
file_put_contents($output,json_encode($contract,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n");
$bundleFiles=[];
foreach(['inspect.php','InstanceCompatibilityInspector.php','contract-1.1.6.json']as$file){$path=$bundleDirectory.'/'.$file;if(!is_file($path))throw new RuntimeException('BUNDLE_FILE_MISSING:'.$file);$bundleFiles[$file]=['sha256'=>CanonicalFileHasher::hashFile($path,$file),'hash_mode'=>CanonicalFileHasher::mode($file)];}
$bundle=['schema_version'=>1,'package_id'=>'ikontrol-fiscal-116-instance-compatibility','target_version'=>'1.1.6','source_commit'=>$commit,'entrypoint'=>'inspect.php','files'=>$bundleFiles];
$manifest=$bundleDirectory.'/bundle-manifest.json';
file_put_contents($manifest,json_encode($bundle,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
echo $output.PHP_EOL.$manifest.PHP_EOL;
