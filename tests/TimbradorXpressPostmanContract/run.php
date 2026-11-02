<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$matches=glob($root.'/docs/fiscal/referencias-locales/*TimbradorXpress*postman_collection*.json')?:[];
if(count($matches)!==1){fwrite(STDERR,'BLOCKED: exactly one local TimbradorXpress collection is required.'.PHP_EOL);exit(2);}
$collection=json_decode((string)file_get_contents($matches[0]),true,512,JSON_THROW_ON_ERROR);
$requests=[];$literalSensitive=0;$walk=function(array$items)use(&$walk,&$requests,&$literalSensitive):void{foreach($items as$item){if(isset($item['item'])){$walk($item['item']);continue;}$request=$item['request']??[];$name=(string)($item['name']??'');$fields=[];foreach(($request['body']['urlencoded']??[])as$field){$key=(string)($field['key']??'');$fields[]=$key;if(preg_match('/api.?key|keypem|cerpem|password|token|secret/i',$key)&&trim((string)($field['value']??''))!==''&&!preg_match('/^\{\{[^}]+\}\}$/',(string)$field['value']))$literalSensitive++;}$requests[$name]=['method'=>$request['method']??null,'url'=>$request['url']['raw']??null,'mode'=>$request['body']['mode']??null,'fields'=>$fields,'auth'=>$request['auth']['type']??'none','responses'=>count($item['response']??[])];}};$walk($collection['item']??[]);
$pass=0;$ok=static function(bool$value,string$label)use(&$pass):void{if(!$value)throw new RuntimeException('[FAIL] '.$label);$pass++;echo'[PASS] '.$label.PHP_EOL;};
$expected=['timbrar','timbrarTFD','timbrarConSello','timbrarJSON','timbrarJSON2','timbrarJSON3','timbrarTXT','timbrarTXT2','consultarCFDI'];
$ok(array_keys($requests)===$expected,'collection documents the expected nine REST operations');
$stamp=$requests['timbrarConSello'];
$ok($stamp['method']==='POST'&&$stamp['url']==='https://dev.timbradorxpress.mx/api/rest/servicio/timbrarConSello','timbrarConSello uses the exact sandbox POST URL');
$ok($stamp['mode']==='urlencoded'&&$stamp['fields']===['apikey','xmlCFDI','keyPEM']&&$stamp['auth']==='none','authentication and payload match form-urlencoded body contract');
$adapter=(string)file_get_contents($root.'/app/Services/Fiscal/Pac/TimbradorXpressRestAdapter.php');
$ok(str_contains($adapter,"baseUrl . 'timbrarConSello'")&&str_contains($adapter,"'apikey'=>\$config->apiKey")&&str_contains($adapter,"'xmlCFDI'=>\$request->signedXml")&&str_contains($adapter,"'keyPEM'=>\$request->keyPem"),'adapter matches documented operation and three fields');
$ok(array_sum(array_column($requests,'responses'))===0,'collection has no success or error response examples');
$ok($literalSensitive>0,'collection is detected as secret-bearing and must remain ignored');
$ok(!isset($requests['cancelarPEM'])&&!isset($requests['consultarEstadoSAT']),'collection does not validate cancellation or SAT-status REST contracts');
echo'TOTAL PASS='.$pass.' FAIL=0; no sensitive values printed, no network.'.PHP_EOL;
