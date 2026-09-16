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

use App\Services\Fiscal\FiscalArtifactStorageService;
use App\Services\Fiscal\Pac\PacPdfArtifactService;
use App\Services\Fiscal\Pac\PacPdfValidator;
use App\Services\Fiscal\Pdf\FiscalPacPdfGenerationService;
use App\Services\Fiscal\Pdf\FiscalPdfTemplateResolver;
use App\Services\Fiscal\Pdf\TimbradorXpressToolsPdfAdapter;
use Config\Fiscal;
use Config\FiscalPdfProvider;

$db = db_connect();
$documentId = 21;
$sourceId = 116610;
$sourceDatabase = 'tws001_factucare';
$correlationId = 'c1.2-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));

$sourceFingerprint = static function () use ($db, $sourceDatabase, $sourceId): string {
    $row = $db->query(
        "SELECT SHA2(CONCAT_WS('|',id,users_id,estatus,tipo_comprobante,uuid,
                    SHA2(xml,256),COALESCE(SHA2(pdf,256),''),COALESCE(fecha,'')),256) fingerprint
           FROM {$sourceDatabase}.facturas WHERE id=?",
        [$sourceId]
    )->getRow();
    return (string) ($row->fingerprint ?? '');
};
$sourceTimbres = static function () use ($db, $sourceDatabase): ?int {
    $column = $db->query(
        'SELECT COUNT(*) found FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?',
        [$sourceDatabase, 'users', 'timbres_disponibles']
    )->getRow();
    if ((int) ($column->found ?? 0) !== 1) {
        return null;
    }
    $row = $db->query(
        "SELECT timbres_disponibles FROM {$sourceDatabase}.users WHERE id=87"
    )->getRow();
    return $row ? (int) $row->timbres_disponibles : null;
};

$before = [
    'stamp_attempts' => $db->table('fiscal_stamp_attempts')->countAllResults(),
    'pdf_attempts' => $db->table('fiscal_pdf_generation_attempts')->countAllResults(),
    'source_fingerprint' => $sourceFingerprint(),
    'source_timbres' => $sourceTimbres(),
];

$document = $db->table('fiscal_documents')->where(['id' => $documentId, 'deleted' => 0])->get(1)->getRow();
$stamp = $db->table('fiscal_document_stamps')->where('fiscal_document_id', $documentId)->get(1)->getRow();
$xmlArtifact = $stamp
    ? $db->table('fiscal_document_artifacts')->where('id', (int) $stamp->stamped_xml_artifact_id)->get(1)->getRow()
    : null;
if (!$document || !$stamp || !$xmlArtifact) {
    throw new RuntimeException('C1_2_PREFLIGHT_FIXTURE_INCOMPLETE');
}
$xml = (new FiscalArtifactStorageService($db))->read($xmlArtifact);
$dom = new DOMDocument();
if (!$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS) || !$dom->documentElement) {
    throw new RuntimeException('C1_2_PREFLIGHT_XML_INVALID');
}
$xpath = new DOMXPath($dom);
$xpath->registerNamespace('tfd', 'http://www.sat.gob.mx/TimbreFiscalDigital');
$tfd = $xpath->query('//tfd:TimbreFiscalDigital')->item(0);
$xmlUuid = $tfd?->attributes?->getNamedItem('UUID')?->nodeValue;

$provider = new FiscalPdfProvider();
$provider->allowExternalPdf = true; // Process-local gate; not persisted.
$fiscal = new Fiscal();
$fiscal->environment = 'sandbox'; // Process-local HTTP sandbox gate.
$fiscal->allowExternalPdf = true; // Process-local gate; not persisted.

$selection = (new FiscalPdfTemplateResolver($db, $provider))->resolve(
    (int) $document->issuer_profile_id,
    $provider->provider,
    'I'
);
$preflight = [
    'uuid_present' => trim((string) $stamp->uuid) !== '',
    'tfd_present' => $tfd !== null,
    'uuid_matches' => is_string($xmlUuid) && strcasecmp($xmlUuid, (string) $stamp->uuid) === 0,
    'xml_hash_matches' => hash_equals((string) $stamp->stamped_xml_sha256, hash('sha256', $xml)),
    'template' => $selection->templateCode,
    'allow_real_pac' => $fiscal->allowRealPac,
    'stamp_adapter' => $fiscal->pacAdapter,
    'external_pdf_provider' => $provider->provider,
    'existing_pdf' => $stamp->pac_pdf_artifact_id !== null,
    'new_stamp_attempts' => $db->table('fiscal_stamp_attempts')->countAllResults() - $before['stamp_attempts'],
];
if (!$preflight['uuid_present'] || !$preflight['tfd_present'] || !$preflight['uuid_matches']
    || !$preflight['xml_hash_matches'] || $preflight['template'] !== 'factura'
    || $preflight['allow_real_pac'] || $preflight['stamp_adapter'] !== 'fake'
    || $preflight['external_pdf_provider'] !== 'timbradorxpress-tools'
    || $preflight['existing_pdf'] || $preflight['new_stamp_attempts'] !== 0
) {
    throw new RuntimeException('C1_2_PREFLIGHT_FAILED');
}
if (getenv('C1_2_PREFLIGHT_ONLY') === '1') {
    echo json_encode([
        'correlation_id' => $correlationId,
        'document_id' => $documentId,
        'preflight' => $preflight,
        'stamp_attempts_before' => $before['stamp_attempts'],
        'pdf_attempts_before' => $before['pdf_attempts'],
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(0);
}

$adapter = new TimbradorXpressToolsPdfAdapter($provider, $fiscal);
$service = new FiscalPacPdfGenerationService($db, $adapter, $provider);
$startedAt = gmdate('c');
$result = $service->generate($documentId, 1);
$finishedAt = gmdate('c');

$afterStamp = $db->table('fiscal_document_stamps')->where('fiscal_document_id', $documentId)->get(1)->getRow();
$attempt = $result->attemptId
    ? $db->table('fiscal_pdf_generation_attempts')->where('id', $result->attemptId)->get(1)->getRow()
    : null;
$validated = null;
if ($result->pdfArtifactId) {
    $stored = (new PacPdfArtifactService($db))->read($documentId);
    $validated = (new PacPdfValidator())->validate((string) $stored['artifact']->content_base64);
}
$after = [
    'stamp_attempts' => $db->table('fiscal_stamp_attempts')->countAllResults(),
    'pdf_attempts' => $db->table('fiscal_pdf_generation_attempts')->countAllResults(),
    'source_fingerprint' => $sourceFingerprint(),
    'source_timbres' => $sourceTimbres(),
];

echo json_encode([
    'correlation_id' => $correlationId,
    'document_id' => $documentId,
    'provider' => $provider->provider,
    'template' => $selection->templateCode,
    'started_at' => $startedAt,
    'finished_at' => $finishedAt,
    'preflight' => $preflight,
    'result' => [
        'success' => $result->success,
        'status' => $result->status,
        'attempt_id' => $result->attemptId,
        'pdf_artifact_id' => $result->pdfArtifactId,
        'provider_code' => $result->providerCode,
        'provider_message' => $result->providerMessage,
        'requires_reconciliation' => $result->requiresReconciliation,
        'request_sent' => $attempt ? (bool) $attempt->request_sent : null,
    ],
    'pdf' => $validated ? [
        'base64_valid' => true,
        'pdf_header' => true,
        'page_count' => $validated['page_count'],
        'size_bytes' => $validated['decoded_size_bytes'],
        'sha256' => $validated['decoded_sha256'],
        'persisted' => (int) $afterStamp->pac_pdf_artifact_id === $result->pdfArtifactId,
    ] : null,
    'integrity' => [
        'uuid_unchanged' => strcasecmp((string) $afterStamp->uuid, (string) $stamp->uuid) === 0,
        'xml_hash_unchanged' => hash_equals((string) $afterStamp->stamped_xml_sha256, (string) $stamp->stamped_xml_sha256),
        'stamp_attempts_before' => $before['stamp_attempts'],
        'stamp_attempts_after' => $after['stamp_attempts'],
        'pdf_attempts_before' => $before['pdf_attempts'],
        'pdf_attempts_after' => $after['pdf_attempts'],
        'source_unchanged' => hash_equals($before['source_fingerprint'], $after['source_fingerprint']),
        'source_timbres_before' => $before['source_timbres'],
        'source_timbres_after' => $after['source_timbres'],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
