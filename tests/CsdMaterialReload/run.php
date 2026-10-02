<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Services\Fiscal\CsdCertificateService;
use App\Services\Fiscal\Signing\CsdCertificateSecretService;
use App\Services\Fiscal\Signing\CsdOperationalStatusService;
use App\Services\Fiscal\Signing\CsdSecretVault;
use Config\Database;

helper('date_time');
$local=config(Database::class)->default;
if(!in_array((string)$local['hostname'],['localhost','127.0.0.1','::1'],true))throw new RuntimeException('Local fixture server required.');
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);$admin=new mysqli($local['hostname'],$local['username'],$local['password'],'',(int)$local['port']);$owned='ikontrol_test_csd_reload_'.bin2hex(random_bytes(5));$admin->query('CREATE DATABASE `'.$owned.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$db=Database::connect(array_replace($local,['DSN'=>'','database'=>$owned,'DBPrefix'=>'','pConnect'=>false,'DBDebug'=>true,'failover'=>[]]),false);$root=sys_get_temp_dir().DIRECTORY_SEPARATOR.'ikontrol_csd_reload_'.bin2hex(random_bytes(5));mkdir($root,0700,true);
register_shutdown_function(static function()use($admin,$owned,$root):void{$admin->query('DROP DATABASE IF EXISTS `'.str_replace('`','``',$owned).'`');$admin->close();$rm=static function(string$p)use(&$rm):void{if(!is_dir($p))return;foreach(scandir($p)?:[]as$e){if($e==='.'||$e==='..')continue;$t=$p.DIRECTORY_SEPARATOR.$e;is_dir($t)?$rm($t):@unlink($t);}@rmdir($p);};$rm($root);});
$db->query("CREATE TABLE fiscal_profiles (id INT PRIMARY KEY,profile_type VARCHAR(20),status VARCHAR(20),rfc VARCHAR(20))");
$db->query("CREATE TABLE fiscal_issuer_certificates (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,issuer_profile_id INT,certificate_number VARCHAR(40),certificate_serial_hex VARCHAR(128) NULL,certificate_subject VARCHAR(500),certificate_rfc VARCHAR(20),valid_from DATETIME,valid_to DATETIME,certificate_sha256 CHAR(64),public_certificate_path VARCHAR(255),encrypted_private_key_path VARCHAR(255),private_key_sha256 CHAR(64),encryption_key_version VARCHAR(20),status VARCHAR(30),is_default TINYINT,created_by INT NULL,created_at DATETIME NULL,updated_at DATETIME NULL,revoked_at DATETIME NULL,deleted TINYINT,UNIQUE KEY uq_hash(issuer_profile_id,certificate_sha256))");
$db->query("CREATE TABLE fiscal_issuer_certificate_secrets (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,fiscal_issuer_certificate_id BIGINT,secret_type VARCHAR(40),encrypted_payload LONGTEXT,encryption_version VARCHAR(30),status VARCHAR(20),validated_at DATETIME,created_at DATETIME,updated_at DATETIME,rotated_at DATETIME NULL,UNIQUE KEY uq_secret(fiscal_issuer_certificate_id,secret_type))");
$db->query("CREATE TABLE fiscal_issuer_certificate_secret_audit (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,fiscal_issuer_certificate_id BIGINT,user_id INT NULL,action VARCHAR(50),result VARCHAR(20),error_code VARCHAR(60) NULL,created_at DATETIME)");
$db->table('fiscal_profiles')->insert(['id'=>1,'profile_type'=>'issuer','status'=>'ready','rfc'=>'AAA010101AAA']);
$openssl='C:\xampp\apache\bin\openssl.exe';if(!is_file($openssl))throw new RuntimeException('OpenSSL fixture executable unavailable.');$keyPath=$root.DIRECTORY_SEPARATOR.'source.key.pem';$certPath=$root.DIRECTORY_SEPARATOR.'source.cer.pem';$password=bin2hex(random_bytes(12));$run=static function(array$args)use($openssl):void{$cmd=escapeshellarg($openssl);foreach($args as$a)$cmd.=' '.escapeshellarg($a);exec($cmd.' 2>&1',$output,$code);if($code!==0)throw new RuntimeException('OpenSSL fixture generation failed.');};
$run(['genpkey','-algorithm','RSA','-aes-256-cbc','-pass','pass:'.$password,'-pkeyopt','rsa_keygen_bits:2048','-out',$keyPath]);$run(['req','-new','-x509','-config','C:\xampp\apache\conf\openssl.cnf','-key',$keyPath,'-passin','pass:'.$password,'-subj','/C=MX/O=IKONTROL TEST/serialNumber=AAA010101AAA/CN=TEST CSD','-set_serial','0x3330303031303030303030353030303033343136','-days','2','-sha256','-out',$certPath]);
$fiscal=(new ReflectionClass(Config\Fiscal::class))->newInstanceWithoutConstructor();$fiscal->csdEncryptionKey=str_repeat('a',64);$fiscal->pacEncryptionKey=str_repeat('b',64);$fiscal->csdEncryptionVersion='csd-secret-v1';$vault=new CsdSecretVault($fiscal);$secrets=new CsdCertificateSecretService($db,$vault,$root);$service=new CsdCertificateService($db,$root,$secrets);$cer=file_get_contents($certPath);$key=file_get_contents($keyPath);
$first=$service->import(1,$cer,'source.cer.pem',$key,'source.key.pem',$password,true,1,true);$id=(int)$first['certificate']->id;@unlink($root.DIRECTORY_SEPARATOR.'1'.DIRECTORY_SEPARATOR.basename($first['certificate']->public_certificate_path));@unlink($root.DIRECTORY_SEPARATOR.'1'.DIRECTORY_SEPARATOR.basename($first['certificate']->encrypted_private_key_path));$blocked=(new CsdOperationalStatusService($db,$secrets,$root))->forCertificate($first['certificate']);if($blocked['code']!=='private_files_unavailable')throw new RuntimeException('[FAIL] missing material was not detected.');
$second=$service->import(1,$cer,'source.cer.pem',$key,'source.key.pem',$password,true,1,true);$ready=(new CsdOperationalStatusService($db,$secrets,$root))->forCertificate($second['certificate']);$audited=$db->table('fiscal_issuer_certificate_secret_audit')->where(['fiscal_issuer_certificate_id'=>$id,'action'=>'csd_private_material_reloaded','result'=>'success'])->countAllResults()===1;if(($second['action']??'')!=='reconfigured'||(int)$second['certificate']->id!==$id||$db->table('fiscal_issuer_certificates')->countAllResults()!==1||!$ready['ready']||!$audited)throw new RuntimeException('[FAIL] safe same-ID reload contract failed.');
echo "[PASS] missing CSD material reload preserves ID, avoids duplicates, revalidates crypto, and restores readiness.\npassed=1\n";
