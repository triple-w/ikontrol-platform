<?php

declare(strict_types=1);

foreach (['fiscal.enabled' => 'false', 'fiscal.stampingEnabled' => 'false', 'fiscal.allowRealPac' => 'false',
    'fiscal.runtimeMode' => 'automated_test', 'fiscal.pacAdapter' => 'fake'] as $key => $value) {
    putenv($key . '=' . $value); $_ENV[$key] = $_SERVER[$key] = $value;
}
require dirname(__DIR__) . '/bootstrap.php';
require_once APPPATH . 'Database/Migrations/2026-09-29-120000_CreateCanonicalPaymentComplementExternalDocuments.php';

use App\Database\Migrations\CreateCanonicalPaymentComplementExternalDocuments;
use App\Services\PaymentComplementExternalDocumentService;
use Config\Database;

$mysql = in_array('--mysql', $argv, true);
$server = null; $owned = null; $db = null; $passed = 0; $exit = 0;
$assert = static function (bool $value, string $label) use (&$passed): void {
    if (! $value) throw new RuntimeException($label); $passed++; echo '[PASS] ' . $label . PHP_EOL;
};
$reject = static function (callable $action, string $label) use ($assert): void {
    try { $action(); $assert(false, $label); } catch (InvalidArgumentException | RuntimeException $e) { $assert(true, $label); }
};

try {
    $config = ['DSN'=>'','hostname'=>'','username'=>'','password'=>'','database'=>':memory:','DBDriver'=>'SQLite3','DBPrefix'=>'p04_',
        'pConnect'=>false,'DBDebug'=>true,'foreignKeys'=>true];
    if ($mysql) {
        $local = config(Database::class)->default;
        if (! in_array($local['hostname'], ['localhost', '127.0.0.1', '::1'], true)) throw new RuntimeException('Local fixture server required.');
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        // This connection has no selected source DB. It creates only an owned fixture.
        $server = new mysqli($local['hostname'], $local['username'], $local['password'], '', (int) $local['port']);
        $owned = 'ikontrol_test_p04_' . bin2hex(random_bytes(6));
        $server->query('CREATE DATABASE `' . $owned . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
        $config = array_replace($local, ['DSN'=>'','database'=>$owned,'DBDriver'=>'MySQLi','DBPrefix'=>'p04_','pConnect'=>false,'DBDebug'=>true,'failover'=>[]]);
    }
    $db = Database::connect($config, false);
    $assert($mysql ? $db->query('SELECT DATABASE() n')->getRow()->n === $owned : $db->getDatabase() === ':memory:', 'owned fixture target verified');
    $forge = Database::forge($db);
    $make = static function (string $table, array $fields, array $unique = []) use ($forge): void {
        $forge->addField($fields); $forge->addKey('id', true); foreach ($unique as $columns => $name) $forge->addUniqueKey(explode(',', $columns), $name); $forge->createTable($table);
    };
    $id = ['type'=>'INT','unsigned'=>true,'auto_increment'=>true]; $int = ['type'=>'INT','unsigned'=>true]; $nullableInt = ['type'=>'INT','unsigned'=>true,'null'=>true];
    $make('invoices', ['id'=>$id,'sentinel'=>['type'=>'VARCHAR','constraint'=>20]]);
    $make('invoice_payments', ['id'=>$id,'amount'=>['type'=>'DECIMAL','constraint'=>'18,6'],'status'=>['type'=>'VARCHAR','constraint'=>20],'deleted'=>['type'=>'INT','default'=>0],'sentinel'=>['type'=>'VARCHAR','constraint'=>20]]);
    $make('payment_allocations', ['id'=>$id,'sentinel'=>['type'=>'VARCHAR','constraint'=>20]]);
    $make('financial_account_movements', ['id'=>$id,'sentinel'=>['type'=>'VARCHAR','constraint'=>20]]);
    $make('sat_currencies', ['id'=>$id,'code'=>['type'=>'CHAR','constraint'=>3],'is_active'=>['type'=>'INT','default'=>1]], ['code'=>'uq_currency']);
    $make('payment_complements', ['id'=>$id,'status'=>['type'=>'VARCHAR','constraint'=>20],'fiscal_document_id'=>$nullableInt,'updated_at'=>['type'=>'DATETIME','null'=>true],'deleted'=>['type'=>'INT','default'=>0]]);
    $make('payment_complement_payments', ['id'=>$id,'payment_complement_id'=>$int,'source_invoice_payment_id'=>$int,'currency_code'=>['type'=>'CHAR','constraint'=>3],'amount'=>['type'=>'DECIMAL','constraint'=>'18,6'],'updated_at'=>['type'=>'DATETIME','null'=>true],'deleted'=>['type'=>'INT','default'=>0]]);
    $make('payment_complement_documents', ['id'=>$id,'payment_complement_payment_id'=>$int,'document_uuid'=>['type'=>'CHAR','constraint'=>36],'amount_paid'=>['type'=>'DECIMAL','constraint'=>'18,6','default'=>'0.000000'],'deleted'=>['type'=>'INT','default'=>0]]);
    $make('fiscal_documents', ['id'=>$id,'invoice_id'=>['type'=>'INT','unsigned'=>true],'sentinel'=>['type'=>'VARCHAR','constraint'=>20]]);
    if (! $mysql) {
        $make('payment_complement_external_documents', ['id'=>$id,'payment_complement_id'=>$int,'payment_complement_payment_id'=>$int,
            'uuid'=>['type'=>'CHAR','constraint'=>36],'active_uuid'=>['type'=>'CHAR','constraint'=>36,'null'=>true],
            'series'=>['type'=>'VARCHAR','constraint'=>25,'default'=>''],'folio'=>['type'=>'VARCHAR','constraint'=>40,'default'=>''],
            'currency_code'=>['type'=>'CHAR','constraint'=>3],'equivalence_dr'=>['type'=>'DECIMAL','constraint'=>'28,10'],
            'payment_method_code'=>['type'=>'VARCHAR','constraint'=>3,'default'=>'PPD'],'tax_object_code'=>['type'=>'CHAR','constraint'=>2],
            'installment_number'=>$int,'previous_balance'=>['type'=>'DECIMAL','constraint'=>'18,6'],'paid_amount'=>['type'=>'DECIMAL','constraint'=>'18,6'],
            'remaining_balance'=>['type'=>'DECIMAL','constraint'=>'18,6'],'created_by'=>$nullableInt,'created_at'=>['type'=>'DATETIME'],'updated_at'=>['type'=>'DATETIME'],'deleted'=>['type'=>'INT','default'=>0]],
            ['payment_complement_id,active_uuid'=>'uq_pc_external_active_uuid']);
        $make('payment_complement_external_taxes', ['id'=>$id,'external_document_id'=>$int,'tax_type'=>['type'=>'VARCHAR','constraint'=>15],
            'base'=>['type'=>'DECIMAL','constraint'=>'18,6'],'tax_code'=>['type'=>'CHAR','constraint'=>3],'factor_type'=>['type'=>'VARCHAR','constraint'=>10],
            'rate_or_quota'=>['type'=>'DECIMAL','constraint'=>'18,6','null'=>true],'amount'=>['type'=>'DECIMAL','constraint'=>'18,6','null'=>true]]);
    }
    foreach (['MXN', 'USD', 'EUR'] as $currency) $db->table('sat_currencies')->insert(['code'=>$currency,'is_active'=>1]);
    foreach (['invoices','payment_allocations','financial_account_movements'] as $table) $db->table($table)->insert(['id'=>1,'sentinel'=>'preserve']);
    $db->table('invoice_payments')->insert(['id'=>1,'amount'=>'1000','status'=>'active','deleted'=>0,'sentinel'=>'preserve']);
    $db->table('fiscal_documents')->insert(['id'=>1,'invoice_id'=>1,'sentinel'=>'historical']);
    $db->table('payment_complements')->insert(['id'=>1,'status'=>'draft','deleted'=>0]);
    $db->table('payment_complement_payments')->insert(['id'=>1,'payment_complement_id'=>1,'source_invoice_payment_id'=>1,'currency_code'=>'MXN','amount'=>'0','deleted'=>0]);
    $db->table('payment_complement_documents')->insert(['id'=>1,'payment_complement_payment_id'=>1,'document_uuid'=>'11111111-1111-1111-1111-111111111111','amount_paid'=>'0','deleted'=>0]);
    $ledger = static function () use ($db): array { $out=[]; foreach (['invoices','invoice_payments','payment_allocations','financial_account_movements','fiscal_documents','payment_complement_documents'] as $table) $out[$table]=$db->table($table)->orderBy('id')->get()->getResultArray(); return $out; };
    $before = $ledger();
    if ($mysql) {
        $migration = new CreateCanonicalPaymentComplementExternalDocuments($forge);
        $migration->up(); $migration->up(); $migration->down();
        $fields = array_column($db->getFieldData('fiscal_documents'), null, 'name');
        $assert($fields['invoice_id']->nullable, 'fiscal_documents.invoice_id becomes nullable');
        $assert((int)$db->table('fiscal_documents')->where('id',1)->get()->getRow()->invoice_id === 1, 'existing invoice reference preserved');
        $assert($db->table('payment_complement_external_documents')->countAllResults() === 0 && $db->table('payment_complement_external_taxes')->countAllResults() === 0, 'migration creates empty external schema only');
        $index=$db->getIndexData('payment_complement_external_documents')['uq_pc_external_active_uuid']??null;
        $assert($index && $index->type==='UNIQUE' && $index->fields===['payment_complement_id','active_uuid'], 'active UUID unique identity installed');
        $assert($before === $ledger(), 'migration preserves internal documents and administrative ledgers');
    } else {
        $reject(fn () => (new CreateCanonicalPaymentComplementExternalDocuments($forge))->up(), 'MariaDB-only migration refuses SQLite');
    }
    $service = new PaymentComplementExternalDocumentService($db);
    $input = ['uuid'=>'ABCDEF12-1234-1234-1234-ABCDEF123456','series'=>'EXT','folio'=>'10','currency_code'=>'MXN','equivalence_dr'=>'1',
        'payment_method_code'=>'PPD','tax_object_code'=>'02','installment_number'=>1,'previous_balance'=>'116','paid_amount'=>'100',
        'remaining_balance'=>'16','taxes'=>[['tax_type'=>'transfer','base'=>'100','tax_code'=>'002','factor_type'=>'Tasa','rate_or_quota'=>'0.160000','amount'=>'16']]];
    $external=$service->save(1,0,$input,99); $row=$service->get(1,$external);
    $assert($row->payment_complement_payment_id==1 && (float)$row->paid_amount===100.0 && count($row->taxes)===1, 'valid external MXN document is stored');
    $assert($row->payment_complement_id==1 && $row->uuid===$input['uuid'], 'external remains related only to the complement/payment');
    $assert($before === $ledger(), 'external document creates no invoice payment allocation or financial movement');
    $reject(fn ()=>$service->save(1,0,$input,99), 'duplicate external UUID in a complement is rejected');
    $duplicateInternal=$input; $duplicateInternal['uuid']='11111111-1111-1111-1111-111111111111';
    $reject(fn ()=>$service->save(1,0,$duplicateInternal,99), 'external cannot duplicate existing internal UUID');
    foreach ([['installment_number'=>0],['previous_balance'=>'0'],['paid_amount'=>'0'],['paid_amount'=>'117'],['remaining_balance'=>'15'],['uuid'=>'bad'],['equivalence_dr'=>'1e0']] as $bad) {
        $reject(fn ()=>$service->save(1,0,array_replace($input,$bad),99), 'invalid related-document invariant: '.json_encode($bad));
    }
    $usd=array_replace($input,['uuid'=>'ABCDEF12-1234-1234-1234-ABCDEF123457','currency_code'=>'USD','equivalence_dr'=>'0.0500000000','tax_object_code'=>'01','installment_number'=>2,'previous_balance'=>'50','paid_amount'=>'25','remaining_balance'=>'25','taxes'=>[]]);
    $usdId=$service->save(1,0,$usd,99); $assert((float) $service->get(1,$usdId)->equivalence_dr === 0.05, 'foreign currency preserves required equivalence');
    $reject(fn ()=>$service->save(1,0,array_replace($usd,['uuid'=>'ABCDEF12-1234-1234-1234-ABCDEF123458','equivalence_dr'=>'']),99), 'foreign currency requires equivalence');
    $multi=array_replace($input,['paid_amount'=>'116','remaining_balance'=>'0','taxes'=>[
        ['tax_type'=>'transfer','base'=>'100','tax_code'=>'002','factor_type'=>'Tasa','rate_or_quota'=>'0.160000','amount'=>'16'],
        ['tax_type'=>'withholding','base'=>'100','tax_code'=>'001','factor_type'=>'Tasa','rate_or_quota'=>'0.100000','amount'=>'10'],
    ]]);
    $service->save(1,$external,$multi,99); $assert(count($service->get(1,$external)->taxes)===2, 'multiple transfer and withholding taxes replace draft taxes atomically');
    $exempt=array_replace($input,['uuid'=>'ABCDEF12-1234-1234-1234-ABCDEF123459','tax_object_code'=>'02','taxes'=>[['tax_type'=>'transfer','base'=>'100','tax_code'=>'002','factor_type'=>'Exento','rate_or_quota'=>null,'amount'=>null]]]);
    $exemptId=$service->save(1,0,$exempt,99); $assert($service->get(1,$exemptId)->taxes[0]['amount']===null, 'exempt DR tax omits rate and amount');
    $reject(fn ()=>$service->save(1,0,array_replace($input,['uuid'=>'ABCDEF12-1234-1234-1234-ABCDEF123460','tax_object_code'=>'01']),99), 'ObjetoImpDR 01 rejects tax rows');
    $reject(fn ()=>$service->save(1,0,array_replace($input,['uuid'=>'ABCDEF12-1234-1234-1234-ABCDEF123461','taxes'=>[]]),99), 'ObjetoImpDR 02 requires tax rows');
    $service->remove(1,$usdId); $recreated=$service->save(1,0,$usd,99);
    $assert($recreated !== $usdId && count($service->list(1))===3, 'logical deletion permits same UUID as a later distinct draft row');
    $db->table('payment_complements')->where('id',1)->update(['status'=>'stamped']);
    $reject(fn ()=>$service->save(1,$external,$multi,99), 'stamped complement cannot update external document');
    $reject(fn ()=>$service->remove(1,$external), 'stamped complement cannot remove external document');
    $assert($before === $ledger(), 'all external operations preserve existing internal and administrative structures');
    echo $passed . " passed, 0 failed. No source data or PAC accessed.\n";
} catch (Throwable $e) { fwrite(STDERR,'[FAIL] '.$e->getMessage().PHP_EOL.$e->getTraceAsString().PHP_EOL); $exit=1; }
finally {
    if ($db) $db->close();
    if ($server && $owned) { if (!preg_match('/^ikontrol_test_p04_[a-f0-9]{12}$/D',$owned)) throw new RuntimeException('Unsafe cleanup target.'); $server->query('DROP DATABASE `'.$owned.'`'); $server->close(); echo "Owned synthetic MySQL schema removed.\n"; }
}
exit($exit);
