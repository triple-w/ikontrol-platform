<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Services\Legacy\Fc2\Fc2ClientSource;
use App\Services\Legacy\Fc2\Fc2ConnectionGuard;
use App\Services\Legacy\Fc2\Fc2DataNormalizer;
use App\Services\Legacy\Fc2\Fc2IssuerSource;
use App\Services\Legacy\Fc2\Fc2MasterDataAuditService;
use App\Services\Legacy\Fc2\Fc2ProductSource;
use App\Services\Legacy\Fc2\Fc2SeriesSource;
use Config\Database;

$passed = $failed = 0;
$assert = static function (bool $condition, string $message) use (&$passed, &$failed): void {
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    $condition ? $passed++ : $failed++;
};

$config = config('Database');
$adminConfig = $config->default;
$admin = Database::connect($adminConfig, false);
$current = (string) $admin->query('SELECT DATABASE() name')->getRow()->name;
if ($current === '' || str_contains(strtolower($current), 'production')) {
    throw new RuntimeException('Las pruebas FC2 requieren una conexión local verificada.');
}

$suffix = bin2hex(random_bytes(4));
$sourceName = preg_replace('/\W/', '_', $current) . '_fc2_source_' . $suffix;
$destinationName = preg_replace('/\W/', '_', $current) . '_fc2_destination_' . $suffix;
$quote = static fn (string $name): string => '`' . str_replace('`', '``', $name) . '`';
$admin->query('CREATE DATABASE ' . $quote($sourceName) . ' CHARACTER SET utf8mb4');
$admin->query('CREATE DATABASE ' . $quote($destinationName) . ' CHARACTER SET utf8mb4');

try {
    $sourceConfig = $adminConfig;
    $sourceConfig['database'] = $sourceName;
    $sourceConfig['DBPrefix'] = '';
    $source = Database::connect($sourceConfig, false);
    $destinationConfig = $adminConfig;
    $destinationConfig['database'] = $destinationName;
    $destinationConfig['DBPrefix'] = 'ikontrol_';
    $destination = Database::connect($destinationConfig, false);

    $ddl = [
        'users' => 'CREATE TABLE users (id INT PRIMARY KEY, username VARCHAR(30), email VARCHAR(100), password VARCHAR(255))',
        'users_perfil' => 'CREATE TABLE users_perfil (id INT PRIMARY KEY, users_id INT, rfc VARCHAR(20), razon_social VARCHAR(255), calle VARCHAR(255), no_ext VARCHAR(30), no_int VARCHAR(30), colonia VARCHAR(255), municipio VARCHAR(255), localidad VARCHAR(255), estado VARCHAR(100), codigo_postal VARCHAR(10), pais VARCHAR(100), telefono VARCHAR(50), nombre_contacto VARCHAR(255), numero_regimen VARCHAR(10), nombre_regimen VARCHAR(255), numero_regimen33 VARCHAR(10), nombre_regimen33 VARCHAR(255))',
        'users_info_factura' => 'CREATE TABLE users_info_factura (id INT PRIMARY KEY, users_id INT, password VARCHAR(255))',
        'users_info_factura_documentos' => 'CREATE TABLE users_info_factura_documentos (id INT PRIMARY KEY, users_factura_info_id INT, _name VARCHAR(255), _mime_type VARCHAR(100), _size INT, tipo VARCHAR(20), validado TINYINT, _path VARCHAR(255), revisado TINYINT, numero_certificado VARCHAR(30), vigencia VARCHAR(50))',
        'users_logo' => 'CREATE TABLE users_logo (id INT PRIMARY KEY, users_id INT, _name VARCHAR(255), _mime_type VARCHAR(100), _size INT, _path VARCHAR(255))',
        'clientes' => 'CREATE TABLE clientes (id INT PRIMARY KEY, rfc VARCHAR(20), razon_social VARCHAR(255), calle VARCHAR(255), no_ext VARCHAR(30), no_int VARCHAR(30), colonia VARCHAR(255), municipio VARCHAR(255), localidad VARCHAR(255), estado VARCHAR(100), codigo_postal VARCHAR(10), pais VARCHAR(100), telefono VARCHAR(50), nombre_contacto VARCHAR(255), email VARCHAR(255), users_id INT, regimen_fiscal VARCHAR(10))',
        'clave_prod_serv' => 'CREATE TABLE clave_prod_serv (id INT PRIMARY KEY, clave VARCHAR(20), descripcion VARCHAR(255))',
        'clave_unidad' => 'CREATE TABLE clave_unidad (id INT PRIMARY KEY, clave VARCHAR(20), descripcion VARCHAR(255))',
        'productos' => 'CREATE TABLE productos (id INT PRIMARY KEY, users_id INT, clave VARCHAR(100), unidad VARCHAR(50), precio DECIMAL(18,4), descripcion TEXT, observaciones TEXT, clave_prod_serv_id INT, clave_unidad_id INT)',
        'folios' => 'CREATE TABLE folios (id INT PRIMARY KEY, users_id INT, tipo VARCHAR(30), serie VARCHAR(30), folio BIGINT)',
    ];
    foreach ($ddl as $sql) {
        $source->query($sql);
    }
    $destination->query('CREATE TABLE ikontrol_marker (id INT PRIMARY KEY, value VARCHAR(20))');
    $destination->query("INSERT INTO ikontrol_marker VALUES (1, 'unchanged')");

    $source->table('users')->insertBatch([
        ['id' => 15, 'username' => 'DOLD860620EW7', 'email' => 'owner@example.test', 'password' => 'fixture-secret'],
        ['id' => 16, 'username' => 'OTHER010101AAA', 'email' => 'other@example.test', 'password' => 'other-secret'],
    ]);
    $source->table('users_perfil')->insert(['id' => 12, 'users_id' => 15, 'rfc' => 'DOLD860620EW7', 'razon_social' => 'EMISOR PRUEBA', 'codigo_postal' => '06000', 'pais' => 'MEX', 'numero_regimen33' => '612']);
    $source->table('users_info_factura')->insert(['id' => 4, 'users_id' => 15, 'password' => 'never-report-this']);
    $source->table('users_info_factura_documentos')->insert(['id' => 8, 'users_factura_info_id' => 4, '_name' => 'private.key', '_mime_type' => 'application/octet-stream', '_size' => 123, 'tipo' => 'KEY', 'validado' => 1, '_path' => 'C:/legacy/private.key', 'revisado' => 1, 'numero_certificado' => '00000000000000000000', 'vigencia' => 'fixture']);
    $source->table('users_logo')->insert(['id' => 9, 'users_id' => 15, '_name' => 'logo.png', '_mime_type' => 'image/png', '_size' => 40, '_path' => 'C:/legacy/logo.png']);
    $source->table('clientes')->insertBatch([
        ['id' => 1, 'rfc' => ' ABC010101ABC ', 'razon_social' => 'Cliente Uno', 'codigo_postal' => '06000', 'pais' => 'MEX', 'email' => 'ONE@EXAMPLE.TEST', 'users_id' => 15, 'regimen_fiscal' => null],
        ['id' => 2, 'rfc' => 'ABC010101ABC', 'razon_social' => 'Cliente Dos', 'codigo_postal' => '06001', 'pais' => 'MEX', 'email' => 'two@example.test', 'users_id' => 15, 'regimen_fiscal' => '601'],
        ['id' => 3, 'rfc' => 'DOLD860620EW7', 'razon_social' => 'Receptor con RFC emisor', 'codigo_postal' => '06002', 'pais' => 'MEX', 'email' => 'receiver@example.test', 'users_id' => 16, 'regimen_fiscal' => '612'],
    ]);
    $source->table('clave_prod_serv')->insert(['id' => 10, 'clave' => '01010101', 'descripcion' => 'No existe en catálogo']);
    $source->table('clave_unidad')->insert(['id' => 20, 'clave' => 'H87', 'descripcion' => 'Pieza']);
    $source->table('productos')->insertBatch([
        ['id' => 11, 'users_id' => 15, 'clave' => 'REP', 'unidad' => 'PZA', 'precio' => '12.3456', 'descripcion' => 'Producto A', 'observaciones' => null, 'clave_prod_serv_id' => 10, 'clave_unidad_id' => 20],
        ['id' => 12, 'users_id' => 15, 'clave' => 'REP', 'unidad' => 'PZA', 'precio' => '0.0000', 'descripcion' => '', 'observaciones' => 'Revisar', 'clave_prod_serv_id' => 999, 'clave_unidad_id' => 20],
        ['id' => 13, 'users_id' => 16, 'clave' => 'OTHER', 'unidad' => 'PZA', 'precio' => '1.0000', 'descripcion' => 'Otro', 'observaciones' => null, 'clave_prod_serv_id' => 10, 'clave_unidad_id' => 20],
    ]);
    $source->table('folios')->insertBatch([
        ['id' => 21, 'users_id' => 15, 'tipo' => 'INGRESO', 'serie' => 'I', 'folio' => 1],
        ['id' => 22, 'users_id' => 15, 'tipo' => 'INGRESO', 'serie' => 'I', 'folio' => 1],
        ['id' => 23, 'users_id' => 16, 'tipo' => 'INGRESO', 'serie' => 'I', 'folio' => 99],
    ]);

    $beforeSource = $source->query('SELECT COUNT(*) n, SUM(id) checksum FROM clientes')->getRowArray();
    $beforeDestination = $destination->query('SELECT COUNT(*) n, SUM(id) checksum FROM ikontrol_marker')->getRowArray();
    $normalizer = new Fc2DataNormalizer();
    $guard = new Fc2ConnectionGuard($source, $destination);
    $clients = new Fc2ClientSource($source, $normalizer);
    $products = new Fc2ProductSource($source, $normalizer);
    $series = new Fc2SeriesSource($source, $normalizer);
    $issuer = new Fc2IssuerSource($source, $normalizer);
    $service = new Fc2MasterDataAuditService($guard, $issuer, $clients, $products, $series);

    $verified = $guard->verify('dold860620ew7', 15);
    $assert($verified['owner_id'] === 15 && $verified['profile_id'] === 12, 'Identifica exactamente al emisor esperado.');
    $notFound = false;
    try { $issuer->findByRfc('NOEX010101AAA', 15); } catch (RuntimeException) { $notFound = true; }
    $assert($notFound, 'Falla si el emisor no existe.');
    $assert($issuer->findByRfc('DOLD860620EW7', 15)->sourceId === '12', 'No confunde un receptor con el emisor.');
    $clientRows = iterator_to_array($clients->iterateByOwner(15, 1), false);
    $assert(array_map(fn ($r) => $r->sourceId, $clientRows) === ['1', '2'], 'Filtra clientes por users_id y pagina en orden estable.');
    $assert($clientRows[0]->data['rfc'] === 'ABC010101ABC' && $clientRows[0]->data['rfc_original'] === ' ABC010101ABC ', 'Normaliza RFC conservando el valor original.');
    $productRows = iterator_to_array($products->iterateByOwner(15, 1), false);
    $assert(count($productRows) === 2, 'Filtra productos estrictamente por propietario.');
    $assert($productRows[0]->data['precio'] === '12.3456', 'Conserva precio exacto con cuatro decimales sin float.');
    $assert($productRows[0]->data['clave_prod_serv'] === '01010101' && $productRows[0]->data['clave_unidad'] === 'H87', 'Conserva ID y clave SAT resuelta.');
    $assert($productRows[1]->data['clave_prod_serv_id'] === '999' && $productRows[1]->data['clave_prod_serv'] === null, 'Detecta referencia SAT faltante.');
    $seriesRows = $series->allByOwner(15);
    $assert(array_map(fn ($r) => $r->sourceId, $seriesRows) === ['21', '22'], 'Filtra y ordena series por propietario.');
    $report = $service->audit('DOLD860620EW7', 15);
    $codes = array_column($report['findings'], 'code');
    $assert(in_array('RFC_DUPLICATE', $codes, true), 'Reporta RFC duplicados.');
    $assert(in_array('CLIENT_TAX_REGIME_MISSING', $codes, true), 'Reporta régimen fiscal faltante.');
    $assert(in_array('PRODUCT_INTERNAL_KEY_DUPLICATE', $codes, true), 'Reporta claves internas duplicadas.');
    $assert(in_array('PRODUCT_DESCRIPTION_MISSING', $codes, true), 'Reporta descripción de producto faltante.');
    $assert(in_array('PRODUCT_PRICE_ZERO', $codes, true), 'Reporta precio cero.');
    $assert(in_array('PRODUCT_SAT_KEY_MISSING', $codes, true), 'Reporta referencia SAT inexistente.');
    $assert(in_array('SERIES_TYPE_SERIES_DUPLICATE', $codes, true), 'Reporta series lógicamente duplicadas.');
    $assert(strlen($report['source_hash_summary']['combined_sha256']) === 64, 'Genera hash determinista de origen.');
    $json = json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    $assert(is_array(json_decode($json, true, 512, JSON_THROW_ON_ERROR)), 'Produce JSON válido.');
    $assert(!str_contains($json, 'never-report-this') && !str_contains($json, 'private.key') && !str_contains($json, 'C:/legacy'), 'El reporte excluye secretos, nombres y rutas sensibles.');
    $assert($normalizer->hash(['b' => 2, 'a' => 1]) === $normalizer->hash(['a' => 1, 'b' => 2]), 'El hash canónico no depende del orden de claves.');
    $sameDatabaseRejected = false;
    try { (new Fc2ConnectionGuard($source, $source))->verify('DOLD860620EW7', 15); } catch (RuntimeException) { $sameDatabaseRejected = true; }
    $assert($sameDatabaseRejected, 'Impide auditar si origen y destino son la misma base.');
    $afterSource = $source->query('SELECT COUNT(*) n, SUM(id) checksum FROM clientes')->getRowArray();
    $afterDestination = $destination->query('SELECT COUNT(*) n, SUM(id) checksum FROM ikontrol_marker')->getRowArray();
    $assert($beforeSource === $afterSource, 'El dry-run no modifica FC2.');
    $assert($beforeDestination === $afterDestination, 'El dry-run no modifica iKontrol.');
} finally {
    $admin->query('DROP DATABASE ' . $quote($sourceName));
    $admin->query('DROP DATABASE ' . $quote($destinationName));
}

echo "Passed: {$passed}; Failed: {$failed}" . PHP_EOL;
exit($failed === 0 ? 0 : 1);
