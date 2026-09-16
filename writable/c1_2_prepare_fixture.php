<?php
declare(strict_types=1);

define('ROOTPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('FCPATH', ROOTPATH);
require ROOTPATH . 'app/Config/Paths.php';
$paths = new Config\Paths();
define('APPPATH', realpath($paths->appDirectory) . DIRECTORY_SEPARATOR);
define('SYSTEMPATH', realpath($paths->systemDirectory) . DIRECTORY_SEPARATOR);
define('WRITEPATH', realpath($paths->writableDirectory) . DIRECTORY_SEPARATOR);
define('ENVIRONMENT', 'development');
require $paths->systemDirectory . '/Boot.php';
CodeIgniter\Boot::bootTest($paths);
helper(['general', 'date_time', 'plugin', 'currency']);
require_once APPPATH . 'ThirdParty/PHP-Hooks/php-hooks.php';

$db = db_connect();
$sourceDatabase = 'tws001_factucare';
$sourceId = 116610;
$issuerProfileId = 2;
$receiverProfileId = 1;
$createdBy = 1;

$existing = $db->table('fiscal_document_metadata')
    ->like('metadata_json', '"fixture_label":"C1.2 PDF WSTools33 Test"')
    ->get(1)->getRow();
if ($existing) {
    fwrite(STDERR, "C1_2_FIXTURE_ALREADY_EXISTS\n");
    exit(2);
}

$source = $db->query(
    "SELECT id, users_id, estatus, tipo_comprobante, xml, uuid
       FROM {$sourceDatabase}.facturas WHERE id = ?",
    [$sourceId]
)->getRow();
if (!$source || trim((string) $source->xml) === '') {
    throw new RuntimeException('C1_2_SOURCE_NOT_FOUND');
}
if (stripos((string) $source->estatus, 'cancel') !== false) {
    throw new RuntimeException('C1_2_SOURCE_CANCELLED');
}

$xml = (string) $source->xml;
$dom = new DOMDocument();
$old = libxml_use_internal_errors(true);
try {
    if (!$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
        throw new RuntimeException('C1_2_SOURCE_XML_MALFORMED');
    }
} finally {
    libxml_clear_errors();
    libxml_use_internal_errors($old);
}
$root = $dom->documentElement;
$xpath = new DOMXPath($dom);
$xpath->registerNamespace('cfdi', 'http://www.sat.gob.mx/cfd/4');
$xpath->registerNamespace('tfd', 'http://www.sat.gob.mx/TimbreFiscalDigital');
$tfd = $xpath->query('//tfd:TimbreFiscalDigital')->item(0);
$issuer = $xpath->query('/cfdi:Comprobante/cfdi:Emisor')->item(0);
$receiver = $xpath->query('/cfdi:Comprobante/cfdi:Receptor')->item(0);
if (!$root || !$tfd || !$issuer || !$receiver || $root->getAttribute('TipoDeComprobante') !== 'I') {
    throw new RuntimeException('C1_2_SOURCE_NOT_STAMPED_INCOME');
}
$uuid = strtoupper($tfd->getAttribute('UUID'));
if (!hash_equals($uuid, strtoupper((string) $source->uuid))) {
    throw new RuntimeException('C1_2_SOURCE_UUID_MISMATCH');
}

$now = get_current_utc_time();
$series = $root->getAttribute('Serie');
$fixtureSeries = 'FC2-' . $series;
$folio = $root->getAttribute('Folio');
$xmlHash = hash('sha256', $xml);
$artifactPath = null;
$db->transBegin();
try {
    $db->table('fiscal_documents')->insert([
        'invoice_id' => 0,
        'issuer_profile_id' => $issuerProfileId,
        'receiver_profile_id' => $receiverProfileId,
        'fiscal_series_id' => 1,
        'document_type' => 'income',
        'status' => 'stamped_pdf_pending',
        'version' => 1,
        'series' => $fixtureSeries,
        'folio' => (int) $folio,
        'issue_date' => str_replace('T', ' ', $root->getAttribute('Fecha')),
        'expedition_postal_code' => $root->getAttribute('LugarExpedicion'),
        'currency_code' => $root->getAttribute('Moneda') ?: 'MXN',
        'exchange_rate' => null,
        'payment_form_code' => $root->getAttribute('FormaPago') ?: '99',
        'payment_method_code' => $root->getAttribute('MetodoPago') ?: 'PUE',
        'cfdi_use_code' => $receiver->getAttribute('UsoCFDI') ?: 'S01',
        'export_code' => $root->getAttribute('Exportacion') ?: '01',
        'subtotal' => '0.00',
        'discount' => '0.00',
        'transferred_tax_total' => '0.00',
        'withheld_tax_total' => '0.00',
        'total' => '0.00',
        'administrative_total_reference' => '0.00',
        'pricing_mode' => 'imported_test_fixture',
        'source_snapshot_hash' => $xmlHash,
        'created_by' => $createdBy,
        'created_at' => $now,
        'updated_at' => $now,
        'stamp_updated_at' => $now,
        'locked_at' => $now,
        'deleted' => 0,
    ]);
    $documentId = (int) $db->insertID();

    $db->table('fiscal_document_issuers')->insert([
        'fiscal_document_id' => $documentId,
        'rfc' => $issuer->getAttribute('Rfc'),
        'legal_name' => $issuer->getAttribute('Nombre'),
        'tax_regime_code' => $issuer->getAttribute('RegimenFiscal'),
        'fiscal_postal_code' => $root->getAttribute('LugarExpedicion'),
        'expedition_postal_code' => $root->getAttribute('LugarExpedicion'),
        'country_code' => 'MEX',
        'created_at' => $now,
    ]);
    $db->table('fiscal_document_receivers')->insert([
        'fiscal_document_id' => $documentId,
        'rfc' => $receiver->getAttribute('Rfc'),
        'legal_name' => $receiver->getAttribute('Nombre'),
        'tax_regime_code' => $receiver->getAttribute('RegimenFiscalReceptor'),
        'fiscal_postal_code' => $receiver->getAttribute('DomicilioFiscalReceptor'),
        'cfdi_use_code' => $receiver->getAttribute('UsoCFDI'),
        'created_at' => $now,
    ]);
    $db->table('fiscal_document_metadata')->insert([
        'fiscal_document_id' => $documentId,
        'metadata_json' => json_encode([
            'fixture_label' => 'C1.2 PDF WSTools33 Test',
            'source' => 'imported_test_fixture',
            'source_database' => $sourceDatabase,
            'source_id' => $sourceId,
            'source_series' => $series,
            'source_folio' => $folio,
            'stamped_pdf_pending' => true,
            'accountable' => false,
            'cancelable' => false,
            'stamp_resend_allowed' => false,
            'normal_sales_flow' => false,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        'warnings_json' => json_encode(['isolated_representation_test_only'], JSON_THROW_ON_ERROR),
        'rules_version' => 'c1.2-imported-test-fixture-v1',
        'payment_total_snapshot' => '0.00',
        'created_at' => $now,
    ]);

    $artifactDir = WRITEPATH . 'fiscal-private/artifacts';
    if (!is_dir($artifactDir) && !mkdir($artifactDir, 0700, true) && !is_dir($artifactDir)) {
        throw new RuntimeException('C1_2_ARTIFACT_DIRECTORY_FAILED');
    }
    $artifactName = bin2hex(random_bytes(24)) . '.xml';
    $artifactPath = $artifactDir . DIRECTORY_SEPARATOR . $artifactName;
    if (file_put_contents($artifactPath, $xml, LOCK_EX) === false) {
        throw new RuntimeException('C1_2_ARTIFACT_WRITE_FAILED');
    }
    @chmod($artifactPath, 0600);
    $db->table('fiscal_document_artifacts')->insert([
        'fiscal_document_id' => $documentId,
        'artifact_type' => 'stamped_xml',
        'storage_path' => 'fiscal-private/artifacts/' . $artifactName,
        'sha256' => $xmlHash,
        'byte_size' => strlen($xml),
        'builder_version' => 'c1.2-import',
        'schema_version' => 'CFDI 4.0 + TFD 1.1',
        'schema_sha256' => null,
        'validation_status' => 'valid_imported',
        'validation_payload' => json_encode([
            'well_formed' => true,
            'timbre_fiscal_digital' => true,
            'uuid_matches' => true,
            'source_sha256' => $xmlHash,
        ], JSON_THROW_ON_ERROR),
        'created_by' => $createdBy,
        'created_at' => $now,
    ]);
    $artifactId = (int) $db->insertID();
    $db->table('fiscal_document_stamps')->insert([
        'fiscal_document_id' => $documentId,
        'stamp_attempt_id' => 0,
        'stamped_xml_artifact_id' => $artifactId,
        'pac_pdf_artifact_id' => null,
        'pdf_status' => 'pending',
        'pdf_template' => null,
        'uuid' => $uuid,
        'stamp_date' => str_replace('T', ' ', $tfd->getAttribute('FechaTimbrado')),
        'pac_rfc' => $tfd->getAttribute('RfcProvCertif'),
        'sat_certificate_number' => $tfd->getAttribute('NoCertificadoSAT'),
        'cfd_seal' => $tfd->getAttribute('SelloCFD'),
        'sat_seal' => $tfd->getAttribute('SelloSAT'),
        'tfd_version' => $tfd->getAttribute('Version'),
        'provider' => 'imported_test_fixture',
        'environment' => 'sandbox',
        'stamped_xml_sha256' => $xmlHash,
        'created_at' => $now,
    ]);
    $db->table('fiscal_document_audit')->insert([
        'fiscal_document_id' => $documentId,
        'invoice_id' => null,
        'user_id' => $createdBy,
        'action' => 'c1_2_fixture_imported',
        'reason' => 'Representación PDF aislada; no contabilizable, no cancelable y no reenviable a timbrado.',
        'previous_hash' => null,
        'new_hash' => $xmlHash,
        'created_at' => $now,
    ]);
    $db->table('fiscal_issuer_pdf_templates')->where([
        'issuer_id' => $issuerProfileId,
        'provider' => 'timbradorxpress-tools',
        'document_type' => 'I',
    ])->update(['template_code' => 'factura', 'is_active' => 1, 'updated_at' => $now]);
    $db->transCommit();
} catch (Throwable $exception) {
    $db->transRollback();
    if ($artifactPath && is_file($artifactPath)) {
        @unlink($artifactPath);
    }
    throw $exception;
}

echo json_encode([
    'document_id' => $documentId,
    'source_id' => $sourceId,
    'series' => $fixtureSeries,
    'source_series' => $series,
    'folio' => $folio,
    'type' => 'I',
    'uuid_masked' => substr($uuid, 0, 8) . '-****-****-****-********' . substr($uuid, -4),
    'xml_sha256' => $xmlHash,
    'artifact_id' => $artifactId,
    'status' => 'stamped_pdf_pending',
    'template_code' => 'factura',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
