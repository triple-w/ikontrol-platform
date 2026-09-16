<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
helper(['general', 'date_time', 'plugin', 'currency']);
require_once APPPATH . 'ThirdParty/PHP-Hooks/php-hooks.php';
$db = require dirname(__DIR__) . '/Increment02/isolated_database.php';
service('migrations')->setNamespace('App')->latest();
$settings = [];
foreach ($db->table('settings')->get()->getResult() as $setting) {
    $settings[$setting->setting_name] = $setting->setting_value;
}
config('Rise')->app_settings_array = $settings + ['timezone' => 'UTC'];
session();

$pass = 0;
$fail = 0;
$assert = static function (bool $ok, string $message) use (&$pass, &$fail): void {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

$temp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ikontrol_increment08_' . bin2hex(random_bytes(6));
$preXmlRoot = $temp . DIRECTORY_SEPARATOR . 'prexml';
$certificateRoot = $temp . DIRECTORY_SEPARATOR . 'certificates';
$artifactRoot = $temp . DIRECTORY_SEPARATOR . 'artifacts';
mkdir($temp, 0700, true);
$cleanup = static function (string $path) use (&$cleanup): void {
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $target = $path . DIRECTORY_SEPARATOR . $entry;
        is_dir($target) ? $cleanup($target) : @unlink($target);
    }
    @rmdir($path);
};
register_shutdown_function(static function () use ($cleanup, $temp): void { $cleanup($temp); });

try {
    foreach (['fiscal_issuer_certificates', 'fiscal_payment_method_mappings', 'fiscal_document_signatures'] as $table) {
        $assert($db->tableExists($table), "$table exists in the isolated database.");
    }
    $rules = new App\Services\Fiscal\CfdiPaymentRuleService($db);
    $rules->validate('PPD', '99');
    $assert(true, 'PPD with FormaPago 99 is accepted.');
    try {$rules->validate('PPD', '01');$blocked=false;}catch(Throwable $e){$blocked=true;}
    $assert($blocked, 'PPD with FormaPago other than 99 is rejected.');
    try {$rules->validate('PUE', '99');$blocked=false;}catch(Throwable $e){$blocked=true;}
    $assert($blocked, 'PUE with FormaPago 99 is rejected.');
    $rules->validate('PUE', '01');
    $assert(true, 'PUE with a real payment form is accepted.');

    $invoice = $db->table('invoices')->where('deleted', 0)->get(1)->getRow();
    $user = $db->table('users')->where('deleted', 0)->get(1)->getRow();
    $regime = $db->table('sat_tax_regimes')->where('is_active', 1)->get(1)->getRow();
    $now = gmdate('Y-m-d H:i:s');
    $db->table('fiscal_profiles')->insert([
        'profile_type' => 'issuer', 'client_id' => null, 'company_id' => $invoice->company_id ?: null,
        'rfc' => 'AAA010101AAA', 'legal_name' => 'EMISOR CSD AISLADO', 'tax_regime_id' => $regime->id,
        'fiscal_postal_code' => '06000', 'expedition_postal_code' => '06000', 'fiscal_country_code' => 'MEX',
        'status' => 'ready', 'is_default' => 0, 'created_by' => $user->id, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $issuerId = (int) $db->insertID();
    $db->table('fiscal_documents')->insert([
        'invoice_id' => $invoice->id, 'issuer_profile_id' => $issuerId, 'receiver_profile_id' => $issuerId,
        'fiscal_series_id' => 1, 'pricing_preparation_id' => null, 'document_type' => 'income', 'status' => 'locked',
        'version' => 1, 'series' => 'S', 'folio' => 8001, 'issue_date' => $now,
        'expedition_postal_code' => '06000', 'currency_code' => 'MXN', 'exchange_rate' => null,
        'payment_form_code' => '01', 'payment_method_code' => 'PUE', 'cfdi_use_code' => 'S01', 'export_code' => '01',
        'subtotal' => '100.00', 'discount' => '0.00', 'transferred_tax_total' => '16.00',
        'withheld_tax_total' => '0.00', 'total' => '116.00', 'administrative_total_reference' => '116.00',
        'pricing_mode' => 'tax_exclusive', 'source_snapshot_hash' => str_repeat('8', 64),
        'created_by' => $user->id, 'created_at' => $now, 'updated_at' => $now, 'locked_at' => $now, 'deleted' => 0,
    ]);
    $documentId = (int) $db->insertID();
    $db->table('fiscal_document_issuers')->insert([
        'fiscal_document_id' => $documentId, 'rfc' => 'AAA010101AAA', 'legal_name' => 'EMISOR CSD AISLADO',
        'tax_regime_code' => '601', 'fiscal_postal_code' => '06000', 'expedition_postal_code' => '06000',
        'country_code' => 'MEX', 'created_at' => $now,
    ]);
    $db->table('fiscal_document_receivers')->insert([
        'fiscal_document_id' => $documentId, 'rfc' => 'XAXX010101000', 'legal_name' => 'PUBLICO GENERAL',
        'tax_regime_code' => '616', 'fiscal_postal_code' => '06000', 'cfdi_use_code' => 'S01', 'created_at' => $now,
    ]);
    $db->table('fiscal_document_items')->insert([
        'fiscal_document_id' => $documentId, 'invoice_item_id' => null, 'item_id' => null, 'line_number' => 1,
        'product_service_code' => '01010101', 'quantity' => '1.000000', 'unit_code' => 'H87', 'unit_name' => 'Pieza',
        'description' => 'Fixture CSD aislado', 'unit_value' => '100.000000', 'gross_amount' => '100.00',
        'discount' => '0.00', 'tax_object_code' => '02', 'taxable_base' => '100.00',
        'transferred_tax_total' => '16.00', 'withheld_tax_total' => '0.00', 'line_total' => '116.00', 'created_at' => $now,
    ]);
    $itemId = (int) $db->insertID();
    $db->table('fiscal_document_item_taxes')->insert([
        'fiscal_document_item_id' => $itemId, 'administrative_tax_id' => null, 'tax_code' => '002',
        'tax_type' => 'transferred', 'factor_type' => 'Tasa', 'rate_or_quota' => '0.160000',
        'taxable_base' => '100.00', 'amount' => '16.00', 'sort_order' => 1, 'created_at' => $now,
    ]);
    $db->table('fiscal_document_tax_totals')->insert([
        'fiscal_document_id' => $documentId, 'tax_code' => '002', 'tax_type' => 'transferred',
        'factor_type' => 'Tasa', 'rate_or_quota' => '0.160000', 'taxable_base' => '100.00',
        'amount' => '16.00', 'created_at' => $now,
    ]);
    $db->table('fiscal_document_metadata')->insert([
        'fiscal_document_id' => $documentId, 'metadata_json' => '{}',
        'rules_version' => 'ikontrol-fiscal-draft-v1', 'payment_total_snapshot' => '0.00', 'created_at' => $now,
    ]);
    $preXml = (new App\Services\Fiscal\Cfdi40\CfdiPreXmlArtifactService($db, $preXmlRoot))->generate($documentId, (int) $user->id, true);
    $assert($preXml['artifact']->validation_status === 'schema_pending_signature', 'Locked snapshot generates a private Pre-XML pending CSD attributes.');

    $openssl = 'C:\\xampp\\apache\\bin\\openssl.exe';
    if (!is_file($openssl)) throw new RuntimeException('OpenSSL test executable is unavailable.');
    $keyPath = $temp . DIRECTORY_SEPARATOR . 'fixture.key.pem';
    $certPath = $temp . DIRECTORY_SEPARATOR . 'fixture.cer.pem';
    $password = bin2hex(random_bytes(16));
    $serialHex = '3330303031303030303030353030303033343136';
    $run = static function (array $arguments) use ($openssl): void {
        $command = escapeshellarg($openssl);
        foreach ($arguments as $argument) $command .= ' ' . escapeshellarg($argument);
        exec($command . ' 2>&1', $output, $code);
        if ($code !== 0) throw new RuntimeException('Synthetic OpenSSL fixture generation failed.');
    };
    $run(['genpkey', '-algorithm', 'RSA', '-aes-256-cbc', '-pass', 'pass:' . $password, '-pkeyopt', 'rsa_keygen_bits:2048', '-out', $keyPath]);
    $run(['req', '-new', '-x509', '-config', 'C:\\xampp\\apache\\conf\\openssl.cnf', '-key', $keyPath, '-passin', 'pass:' . $password, '-subj', '/C=MX/O=IKONTROL TEST/serialNumber=AAA010101AAA/CN=TEST CSD', '-set_serial', '0x' . $serialHex, '-days', '2', '-sha256', '-out', $certPath]);
    $certificateBytes = file_get_contents($certPath);
    $keyBytes = file_get_contents($keyPath);
    $fiscalConfig=(new ReflectionClass(Config\Fiscal::class))->newInstanceWithoutConstructor();
    $fiscalConfig->csdEncryptionKey=str_repeat('a',64);
    $fiscalConfig->pacEncryptionKey=str_repeat('b',64);
    $fiscalConfig->csdEncryptionVersion='csd-secret-v1';
    $vault=new App\Services\Fiscal\Signing\CsdSecretVault($fiscalConfig);
    $secrets=new App\Services\Fiscal\Signing\CsdCertificateSecretService($db,$vault,$certificateRoot);
    $certificateService = new App\Services\Fiscal\CsdCertificateService($db, $certificateRoot,$secrets);
    $imported = $certificateService->import($issuerId, $certificateBytes, 'fixture.cer.pem', $keyBytes, 'fixture.key.pem', $password, true, (int) $user->id, true);
    $certificateId = (int) $imported['certificate']->id;
    $assert($imported['status'] === 'valid' && $imported['certificate']->certificate_number === '30001000000500003416', 'Synthetic CSD is parsed with its 20-digit certificate number.');
    $assert($imported['certificate']->certificate_rfc === 'AAA010101AAA', 'Certificate RFC matches the issuer profile.');
    $storedSecret=$db->table('fiscal_issuer_certificate_secrets')->where('fiscal_issuer_certificate_id',$certificateId)->get(1)->getRow();
    $assert($storedSecret&&!str_contains((string)$storedSecret->encrypted_payload,$password)&&!str_contains(json_encode($imported),$password),'Private-key password is stored only as authenticated ciphertext and is not returned.');
    $originalPayload=(string)$storedSecret->encrypted_payload;
    try{$secrets->configure($certificateId,'wrong-password',(int)$user->id,true);$wrongSecret=false;}catch(Throwable){$wrongSecret=true;}
    $payloadAfterWrong=(string)$db->table('fiscal_issuer_certificate_secrets')->where('fiscal_issuer_certificate_id',$certificateId)->get(1)->getRow()->encrypted_payload;
    $assert($wrongSecret&&hash_equals($originalPayload,$payloadAfterWrong),'An invalid password does not replace the active secret.');
    $secrets->configure($certificateId,$password,(int)$user->id,true);
    $rotatedSecret=$db->table('fiscal_issuer_certificate_secrets')->where('fiscal_issuer_certificate_id',$certificateId)->get(1)->getRow();
    $assert(!hash_equals($originalPayload,(string)$rotatedSecret->encrypted_payload)&&$rotatedSecret->rotated_at!==null,'Updating a valid password rotates authenticated ciphertext with a fresh nonce.');
    $status=(new App\Services\Fiscal\Signing\CsdOperationalStatusService($db,$secrets,$certificateRoot))->forCertificate((object)$imported['certificate']);
    $assert($status['ready']&&$status['code']==='ready','A certificate with an active decryptable secret is ready for automatic signing.');
    $validPayload=(string)$rotatedSecret->encrypted_payload;
    $db->table('fiscal_issuer_certificate_secrets')->where('id',$rotatedSecret->id)->update(['encrypted_payload'=>substr_replace($validPayload,'A',20,1)]);
    try{$secrets->passwordForSigning($certificateId,(int)$user->id);$corruptBlocked=false;}catch(Throwable){$corruptBlocked=true;}
    $assert($corruptBlocked,'A corrupted CSD secret blocks automatic signing.');
    $db->table('fiscal_issuer_certificate_secrets')->where('id',$rotatedSecret->id)->update(['encrypted_payload'=>$validPayload]);
    try {$certificateService->import($issuerId, $certificateBytes, 'fixture.cer.pem', $keyBytes, 'fixture.key.pem', 'wrong', false, (int)$user->id, true);$wrong=false;}catch(Throwable $e){$wrong=true;}
    $assert($wrong, 'Wrong private-key password is rejected.');

    $signing = new App\Services\Fiscal\Cfdi40\CfdiSigningService($db, $certificateRoot, $artifactRoot, $preXmlRoot,null,$secrets);
    $signed = $signing->sign($documentId, (int) $preXml['artifact']->id, $certificateId, (int) $user->id, true);
    $assert($signed['seal_verified'] === true && $signed['xsd']['status'] === 'valid', 'Local RSA-SHA256 seal verifies and complete XSD validation passes.');
    $assert(str_contains($signed['signed_xml'], 'NoCertificado="30001000000500003416"') && str_contains($signed['signed_xml'], 'Certificado="') && str_contains($signed['signed_xml'], 'Sello="'), 'Signed XML contains NoCertificado, Certificado, and Sello.');
    $assert(!str_contains($signed['signed_xml'], 'TimbreFiscalDigital') && !str_contains($signed['signed_xml'], 'UUID='), 'Locally signed XML contains no Timbre or UUID.');
    $independent=(new App\Services\Fiscal\Signing\SignedXmlVerifier())->verify($signed['signed_xml'],'AAA010101AAA',hash('sha256',$signed['signed_xml']));
    $assert($independent->valid&&$independent->signatureValid&&$independent->xsdValid,'Independent verifier recalculates chain, RSA-SHA256 signature, certificate and XSD.');
    $altered=preg_replace('/ Total="[^"]+"/',' Total="999.99"',$signed['signed_xml'],1);
    $alteredVerification=(new App\Services\Fiscal\Signing\SignedXmlVerifier())->verify($altered,'AAA010101AAA');
    $assert(!$alteredVerification->valid&&!$alteredVerification->signatureValid,'Independent verifier rejects XML modified after signing.');
    $assert($db->table('fiscal_document_artifacts')->where(['fiscal_document_id'=>$documentId,'artifact_type'=>'original_chain'])->countAllResults()===1&&$db->table('fiscal_document_artifacts')->where(['fiscal_document_id'=>$documentId,'artifact_type'=>'signed_xml'])->countAllResults()===1,'Original chain and signed XML are private tracked artifacts.');
    $again = $signing->sign($documentId, (int) $preXml['artifact']->id, $certificateId, (int) $user->id, true);
    $assert($again['action'] === 'existing' && $db->table('fiscal_document_signatures')->where('fiscal_document_id',$documentId)->countAllResults()===1, 'Repeated signing is idempotent and does not request the password again for an existing result.');
    $unchanged = $db->table('fiscal_documents')->where('id', $documentId)->get()->getRow();
    $assert($unchanged->status === 'ready_to_stamp' && (int)$unchanged->folio === 8001, 'Signing marks the immutable document ready to stamp without consuming another folio.');
    $assert((string)$db->table('invoices')->where('id',$invoice->id)->get()->getRow()->invoice_total === (string)$invoice->invoice_total, 'Signing does not modify the administrative sale.');
    $assert($db->table('fiscal_document_audit')->where(['fiscal_document_id'=>$documentId,'action'=>'locally_signed'])->countAllResults()===1, 'Local signing is audited without password, key, chain, or XML contents.');
    unset($password, $keyBytes, $certificateBytes);
} catch (Throwable $e) {
    echo '[FAIL] ' . get_class($e) . ': ' . $e->getMessage() . PHP_EOL;
    $fail++;
}

echo PHP_EOL . "$pass passed, $fail failed." . PHP_EOL;
exit($fail ? 1 : 0);
