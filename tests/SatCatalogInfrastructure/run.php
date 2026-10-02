<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Services\Fiscal\SatCatalogImporterService;
use CodeIgniter\Database\Config as DbConfig;

$db = DbConfig::connect(['DBDriver'=>'SQLite3','database'=>':memory:','DBPrefix'=>'','DBDebug'=>true], false);
$db->query('CREATE TABLE sat_product_service_keys (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT UNIQUE, description TEXT, normalized_description TEXT, valid_from TEXT NULL, valid_to TEXT NULL, is_active INTEGER, created_at TEXT, updated_at TEXT)');
$db->query('CREATE TABLE sat_unit_keys (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT UNIQUE, name TEXT, description TEXT, normalized_description TEXT, is_active INTEGER)');
$db->query('CREATE TABLE sat_catalog_installations (id INTEGER PRIMARY KEY AUTOINCREMENT, catalog_name TEXT UNIQUE, source TEXT, source_version TEXT, source_checksum TEXT, source_generated_at TEXT NULL, installed_at TEXT, row_count INTEGER, active_row_count INTEGER, metadata_json TEXT NULL, created_at TEXT, updated_at TEXT)');
$db->query('CREATE TABLE item_fiscal_settings (id INTEGER PRIMARY KEY AUTOINCREMENT, sat_product_service_key_id INTEGER)');
$db->query("INSERT INTO sat_product_service_keys (id,code,description,normalized_description,is_active) VALUES (35,'43211503','Descripcion vieja','descripcion vieja',1)");
$db->query('INSERT INTO item_fiscal_settings (sat_product_service_key_id) VALUES (35)');
$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sat-catalog-fixture-' . bin2hex(random_bytes(4)); mkdir($root, 0700, true);
copy(dirname(__DIR__) . '/Fixtures/SatCatalogs/product_service.csv', $root . '/product_service.csv');
$manifest = ['schema_version'=>1,'generated_at'=>'2026-10-01T00:00:00Z','catalogs'=>[['catalog_name'=>'product-service','source'=>'test fixture, not official','source_version'=>'fixture-1','generated_at'=>'2026-10-01T00:00:00Z','file'=>'product_service.csv','checksum'=>'sha256:'.hash_file('sha256',$root.'/product_service.csv'),'row_count'=>2,'complete_authoritative'=>true]]];
file_put_contents($root . '/manifest.json', json_encode($manifest, JSON_UNESCAPED_SLASHES));
$assertions=0;$assert=static function(bool $ok,string $message)use(&$assertions):void{if(!$ok)throw new RuntimeException('[FAIL] '.$message);$assertions++;echo '[PASS] '.$message.PHP_EOL;};
$writeManifest=static function(array $value)use($root):void{file_put_contents($root.'/manifest.json',json_encode($value,JSON_UNESCAPED_SLASHES));};
$restoreFixture=static function()use($root,$manifest,$writeManifest):array{copy(dirname(__DIR__).'/Fixtures/SatCatalogs/product_service.csv',$root.'/product_service.csv');$manifest['catalogs'][0]['checksum']='sha256:'.hash_file('sha256',$root.'/product_service.csv');$manifest['catalogs'][0]['row_count']=2;$manifest['catalogs'][0]['complete_authoritative']=true;$writeManifest($manifest);return $manifest;};
try {
    $service = new SatCatalogImporterService($db, $root);
    $dry = $service->update(null, true, false)['catalogs'][0];
    $assert($dry['updated']===1&&$dry['inserted']===1&&$db->table('sat_product_service_keys')->countAllResults()===1,'dry-run calculates changes without writes');
    $first = $service->update()['catalogs'][0];
    $row=$db->table('sat_product_service_keys')->where('code','43211503')->get()->getRowArray();
    $assert((int)$row['id']===35&&$row['description']==='Descripcion nueva','existing code preserves id while updating description');
    $assert((int)$db->table('item_fiscal_settings')->get()->getRow()->sat_product_service_key_id===35,'existing fiscal setting still references the preserved id');
    $assert($db->table('sat_product_service_keys')->where('code','84111506')->countAllResults()===1&&$first['inserted']===1,'new code inserts once');
    $again=$service->update()['catalogs'][0];$assert($again['unchanged']===2,'same manifest is idempotent');
    $db->table('sat_product_service_keys')->insert(['code'=>'99999999','description'=>'legacy','normalized_description'=>'legacy','is_active'=>1]);
    $legacyId=(int)$db->table('sat_product_service_keys')->select('id')->where('code','99999999')->get()->getRow()->id;
    $db->query('INSERT INTO item_fiscal_settings (sat_product_service_key_id) VALUES ('.$legacyId.')');
    $missing=$service->update()['catalogs'][0];$assert($missing['missing_from_source']===1&&$missing['missing_references']===1&&$db->table('sat_product_service_keys')->where('code','99999999')->get()->getRow()->is_active==1,'missing code remains active and reports references without force');
    $forceDry=$service->update(null,true,true)['catalogs'][0];$assert($forceDry['potential_deactivations']===1&&$forceDry['deactivated']===0&&$db->table('sat_product_service_keys')->where('code','99999999')->get()->getRow()->is_active==1,'force dry-run reports potential deactivation without writing');
    $forced=$service->update(null,false,true)['catalogs'][0];$assert($forced['deactivated']===1&&$db->table('sat_product_service_keys')->where('code','99999999')->get()->getRow()->is_active==0,'force plus authoritative deactivates without deleting');
    $manifest['catalogs'][0]['checksum']='sha256:'.str_repeat('0',64);file_put_contents($root.'/manifest.json',json_encode($manifest));
    try{$service->update();throw new RuntimeException('checksum unexpectedly accepted');}catch(RuntimeException $e){$assert(str_contains($e->getMessage(),'Checksum'),'invalid checksum fails before writes');}
    $manifest=$restoreFixture();$manifest['catalogs'][0]['row_count']=3;$writeManifest($manifest);$before=$db->table('sat_product_service_keys')->countAllResults();
    try{$service->update();throw new RuntimeException('row count unexpectedly accepted');}catch(RuntimeException $e){$assert(str_contains($e->getMessage(),'Row count')&&$db->table('sat_product_service_keys')->countAllResults()===$before,'invalid row count rolls back all writes');}
    $manifest=$restoreFixture();file_put_contents($root.'/product_service.csv',"invalid,header\n1,x\n");$manifest['catalogs'][0]['checksum']='sha256:'.hash_file('sha256',$root.'/product_service.csv');$manifest['catalogs'][0]['row_count']=1;$writeManifest($manifest);
    try{$service->update();throw new RuntimeException('header unexpectedly accepted');}catch(RuntimeException $e){$assert(str_contains($e->getMessage(),'headers'),'invalid header fails before writes');}
    $manifest=$restoreFixture();file_put_contents($root.'/product_service.csv',file_get_contents($root.'/product_service.csv')."43211503,Duplicada,,\n");$manifest['catalogs'][0]['checksum']='sha256:'.hash_file('sha256',$root.'/product_service.csv');$manifest['catalogs'][0]['row_count']=3;$writeManifest($manifest);
    try{$service->update();throw new RuntimeException('duplicate unexpectedly accepted');}catch(RuntimeException $e){$assert(str_contains($e->getMessage(),'duplicate'),'duplicate code fails before writes');}
    $manifest=$restoreFixture();$manifest['catalogs'][0]['complete_authoritative']=false;$writeManifest($manifest);
    try{$service->update(null,false,true);throw new RuntimeException('non-authoritative force unexpectedly accepted');}catch(RuntimeException $e){$assert(str_contains($e->getMessage(),'complete_authoritative'),'force without authoritative source is rejected');}
    $manifest=$restoreFixture();$json=json_encode($service->update(null,true),JSON_UNESCAPED_SLASHES);$assert(is_array(json_decode($json,true)),'dry-run result is valid JSON');
    $rows=$db->query("SELECT code FROM sat_product_service_keys WHERE is_active=1 AND (code LIKE '4321%' OR normalized_description LIKE 'descripcion%') ORDER BY CASE WHEN code='43211503' THEN 0 WHEN code LIKE '4321%' THEN 1 ELSE 2 END, code")->getResultArray();
    $assert($rows[0]['code']==='43211503'&&$db->table('sat_product_service_keys')->where('code','99999999')->get()->getRow()->is_active==0,'exact and prefix search ordering excludes inactive rows');
    echo "passed={$assertions}\n";
} finally { foreach (glob($root.'/*')?:[] as $file) unlink($file); rmdir($root); }
