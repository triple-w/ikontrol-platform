<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/ThirdParty/PHPOffice-PhpSpreadsheet/vendor/autoload.php';

$path = $argv[1] ?? '';
if ($path === '' || ! is_file($path)) {
    fwrite(STDERR, "Usage: php inspect-workbook.php <official-catCFDI.xls>\n");
    exit(2);
}

$reader = PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
$sheet = $argv[2] ?? null;
if ($sheet !== null) {
    $reader->setLoadSheetsOnly([$sheet]);
    $book = $reader->load($path);
    $rows = $book->getSheet(0)->rangeToArray('A1:N12', null, true, false);
    foreach ($rows as $number => $row) {
        echo ($number + 1), '|', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
    }
    exit(0);
}
foreach ($reader->listWorksheetInfo($path) as $info) {
    echo $info['worksheetName'], '|', $info['totalRows'], '|', $info['totalColumns'], PHP_EOL;
}
