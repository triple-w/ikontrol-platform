<?php

declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

require dirname(__DIR__, 2) . '/app/ThirdParty/PHPOffice-PhpSpreadsheet/vendor/autoload.php';

$source = $argv[1] ?? '';
$output = $argv[2] ?? dirname(__DIR__, 2) . '/resources/fiscal/catalogs/sat';
$expected = strtolower($argv[3] ?? '');
if (! is_file($source) || ! preg_match('/^[a-f0-9]{64}$/', $expected)) {
    fwrite(STDERR, "Usage: php build-official-csv.php <catCFDI.xls> <output-dir> <expected-sha256>\n");
    exit(2);
}
$actual = hash_file('sha256', $source);
if (! hash_equals($expected, $actual)) throw new RuntimeException('Official workbook checksum mismatch.');
if (! is_dir($output) && ! mkdir($output, 0775, true)) throw new RuntimeException('Cannot create output directory.');

$specs = [
    'product-service' => ['c_ClaveProdServ', 6, 'product_service.csv', ['code','description','valid_from','valid_to'], fn($r)=>[code($r[0],8), text($r[1]), excelDate($r[5]), excelDate($r[6])]],
    'units' => ['c_ClaveUnidad', 7, 'unit_keys.csv', ['code','name','description','symbol','valid_from','valid_to'], fn($r)=>[code($r[0]),text($r[1]),text($r[2]),text($r[6]),excelDate($r[4]),excelDate($r[5])]],
    'tax-codes' => ['c_Impuesto', 6, 'tax_codes.csv', ['code','name','description'], fn($r)=>[code($r[0],3),text($r[1]),text($r[1])]],
    'tax-factor-types' => ['c_TipoFactor', 6, 'tax_factor_types.csv', ['code','name'], fn($r)=>[text($r[0]),text($r[0])]],
    'cfdi-uses' => ['c_UsoCFDI', 7, 'cfdi_uses.csv', ['code','description','applies_to_individual','applies_to_company','valid_from','valid_to'], fn($r)=>[text($r[0]),text($r[1]),yes($r[2]),yes($r[3]),excelDate($r[4]),excelDate($r[5])]],
    'tax-regimes' => ['c_RegimenFiscal', 7, 'tax_regimes.csv', ['code','description','applies_to_individual','applies_to_company','valid_from','valid_to'], fn($r)=>[code($r[0],3),text($r[1]),yes($r[2]),yes($r[3]),excelDate($r[4]),excelDate($r[5])]],
    'tax-object-codes' => ['c_ObjetoImp', 6, 'tax_object_codes.csv', ['code','description','valid_from','valid_to'], fn($r)=>[code($r[0],2),text($r[1]),excelDate($r[2]),excelDate($r[3])]],
    'payment-forms' => ['c_FormaPago', 7, 'payment_forms.csv', ['code','name'], fn($r)=>[code($r[0],2),text($r[1])]],
    'payment-methods' => ['c_MetodoPago', 7, 'payment_methods.csv', ['code','name'], fn($r)=>[text($r[0]),text($r[1])]],
    'currencies' => ['c_Moneda', 6, 'currencies.csv', ['code','name','requires_exchange_rate'], fn($r)=>[text($r[0]),text($r[1]),in_array(text($r[0]),['MXN','XXX'],true)?0:1]],
];

$catalogs = [];
foreach ($specs as $name => [$sheetName,$firstRow,$file,$headers,$convert]) {
    $reader = IOFactory::createReaderForFile($source);
    $reader->setReadDataOnly(true);
    $reader->setLoadSheetsOnly([$sheetName]);
    $sheet = $reader->load($source)->getSheet(0);
    $meta = $sheet->rangeToArray('A1:F4', null, true, false);
    $handle = fopen($output . '/' . $file, 'wb');
    if ($handle === false) throw new RuntimeException("Cannot write {$file}.");
    fputcsv($handle, $headers);
    $count = 0;
    $lastRow = $sheet->getHighestDataRow();
    foreach ($sheet->rangeToArray("A{$firstRow}:N{$lastRow}", null, true, false) as $values) {
        $converted = $convert($values);
        if (($converted[0] ?? '') === '') continue;
        fputcsv($handle, $converted);
        $count++;
    }
    fclose($handle);
    $catalogs[] = [
        'catalog_name'=>$name,
        'source'=>'Servicio de Administración Tributaria (SAT), catálogos CFDI 4.0',
        'source_url'=>'http://omawww.sat.gob.mx/tramitesyservicios/Paginas/documentos/catCFDI_V_4_20261015.xls',
        'source_workbook_sha256'=>$actual,
        'source_version'=>'catCFDI_V_4_20261015',
        'generated_at'=>'2026-10-15T00:00:00-06:00',
        'file'=>$file,
        'checksum'=>'sha256:' . hash_file('sha256', $output . '/' . $file),
        'row_count'=>$count,
        'complete_authoritative'=>true,
    ];
    $sheet->disconnectCells();
    unset($sheet, $reader);
}
$manifest = ['schema_version'=>1,'generated_at'=>'2026-10-15T00:00:00-06:00','source_workbook_sha256'=>$actual,'catalogs'=>$catalogs];
file_put_contents($output . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . PHP_EOL);
echo json_encode(['source_sha256'=>$actual,'catalogs'=>array_column($catalogs,'row_count','catalog_name')], JSON_UNESCAPED_SLASHES), PHP_EOL;

function text(mixed $value): string { return trim((string) $value); }
function code(mixed $value, ?int $width=null): string { $v=text($value); if ($v!=='' && preg_match('/^\d+(?:\.0+)?$/',$v)) $v=(string)(int)$v; return $width===null?$v:str_pad($v,$width,'0',STR_PAD_LEFT); }
function yes(mixed $value): int { return in_array(mb_strtolower(text($value)), ['sí','si','1','true'], true) ? 1 : 0; }
function excelDate(mixed $value): string { if ($value===null || $value==='') return ''; if (is_numeric($value)) return Date::excelToDateTimeObject((float)$value)->format('Y-m-d'); $time=strtotime((string)$value); return $time===false?'':date('Y-m-d',$time); }
