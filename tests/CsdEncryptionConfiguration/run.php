<?php
declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use App\Services\Fiscal\Signing\CsdEncryptionConfigurationService;
use Config\Fiscal;

$n=0;$ok=static function(bool$v,string$m)use(&$n):void{if(!$v)throw new RuntimeException('FAIL '.$m);$n++;echo "PASS {$m}\n";};
$config=(new ReflectionClass(Fiscal::class))->newInstanceWithoutConstructor();
$config->pacEncryptionKey=str_repeat('b',64);$config->csdEncryptionKey='';
$missing=(new CsdEncryptionConfigurationService(null,$config))->inspect();
$ok(!$missing['ready']&&$missing['code']==='CSD_ENCRYPTION_KEY_MISSING','missing key fails with a safe code');
$config->csdEncryptionKey='not-hex';
$invalid=(new CsdEncryptionConfigurationService(null,$config))->inspect();
$ok(!$invalid['ready']&&$invalid['code']==='CSD_ENCRYPTION_KEY_INVALID','malformed key fails with a safe code');
$config->csdEncryptionKey=str_repeat('b',64);
$reused=(new CsdEncryptionConfigurationService(null,$config))->inspect();
$ok(!$reused['ready']&&$reused['code']==='CSD_ENCRYPTION_KEYS_REUSED','PAC and CSD keys cannot be reused');
$config->csdEncryptionKey=str_repeat('a',64);
$ready=(new CsdEncryptionConfigurationService(null,$config))->inspect();
$ok($ready['ready']&&$ready['active_secrets_verified']===0,'valid unique key and AES-256-GCM pass');
$ok(!array_key_exists('key',$ready)&&!array_key_exists('value',$ready),'diagnostic never returns key material');
echo "passed={$n}\n";
