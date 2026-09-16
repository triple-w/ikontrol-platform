<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
helper(['general', 'date_time', 'plugin']);
require_once APPPATH . 'ThirdParty/PHP-Hooks/php-hooks.php';
$db = require __DIR__ . '/isolated_database.php';
$settings=[];foreach($db->table('settings')->get()->getResult() as $setting)$settings[$setting->setting_name]=$setting->setting_value;if(empty($settings['timezone']))$settings['timezone']='UTC';
config('Rise')->app_settings_array=$settings;
session();

$fail = 0;
$assert = static function (bool $ok, string $message) use (&$fail): void {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    if (! $ok) $fail++;
};

$sourceClient = $db->table('clients')->where('deleted', 0)->orderBy('id')->get(1)->getRowArray();
$fixtureClientId = 0;
$client = $sourceClient ? (object) $sourceClient : null;
$company = $db->table('company')->where('deleted', 0)->orderBy('id')->get(1)->getRow();
$tax = $db->table('taxes')->where('deleted', 0)->orderBy('percentage', 'DESC')->get(1)->getRow();
$item = $db->table('items')->where('deleted', 0)->orderBy('id')->get(1)->getRow();
if (! $client || ! $company || ! $item) {
    echo "[SKIP] local reference client/company/item is unavailable\n";
    exit(0);
}

// Characterize a genuinely profile-free client regardless of data already present in the source installation.
unset($sourceClient['id']);
$sourceClient['company_name'] = 'Increment02 profile-free isolated fixture';
$db->table('clients')->insert($sourceClient);
$fixtureClientId = (int) $db->insertID();
$client = (object) ($sourceClient + ['id' => $fixtureClientId]);

$today = get_my_local_time('Y-m-d');
$due = date('Y-m-d', strtotime('+' . (int) get_setting('default_due_date_after_billing_date') . ' days', strtotime($today)));
$items = [
    ['item_id'=>$item->id,'title'=>'Equivalence item A','description'=>'controlled row one','quantity'=>'2','unit_type'=>$item->unit_type ?: 'pieza','rate'=>'100.00','total'=>'200.00','taxable'=>1,'sort'=>0],
    ['item_id'=>$item->id,'title'=>'Equivalence item B','description'=>'controlled row two','quantity'=>'1.5','unit_type'=>$item->unit_type ?: 'pieza','rate'=>'80.00','total'=>'120.00','taxable'=>1,'sort'=>1],
];
$common = [
    'client_id'=>$client->id,'project_id'=>0,'bill_date'=>$today,'due_date'=>$due,'status'=>'not_paid',
    'tax_id'=>$tax->id ?? 0,'tax_id2'=>0,'tax_id3'=>0,'company_id'=>$company->id,'note'=>'Increment02 equivalence fixture',
    'labels'=>'','discount_amount'=>'5','discount_amount_type'=>'percentage','discount_type'=>'before_tax','created_by'=>1,
    'files'=>serialize([]),'recurring'=>0,'repeat_every'=>1,'repeat_type'=>'months','no_of_cycles'=>0,
];

$invoiceIds = [];
$estimateIds = [];
try {
    // Case A: the complete invoice authority with the same payload prepared by the manual RISE form.
    $invoiceIds['manual'] = (new App\Services\InvoiceCreationService())->create($common, $items);

    $createEstimate = static function (string $key, ?array $parts = null) use ($db, $client, $company, $tax, $today, $items, &$estimateIds): int {
        $row = ['client_id'=>$client->id,'estimate_request_id'=>0,'estimate_date'=>$today,'valid_until'=>$today,'note'=>'Increment02 equivalence fixture','status'=>'sent','tax_id'=>$tax->id ?? 0,'tax_id2'=>0,'discount_type'=>'before_tax','discount_amount'=>'5','discount_amount_type'=>'percentage','project_id'=>0,'accepted_by'=>0,'meta_data'=>serialize([]),'created_by'=>1,'signature'=>'','public_key'=>'equivalence-'.$key.'-'.bin2hex(random_bytes(4)),'company_id'=>$company->id,'deleted'=>0];
        $db->table('estimates')->insert($row); $id=(int)$db->insertID(); $estimateIds[]=$id;
        foreach($parts ?? $items as $part){$copy=$part;unset($copy['taxable']);$copy['estimate_id']=$id;$copy['deleted']=0;$db->table('estimate_items')->insert($copy);}
        return $id;
    };

    $estimateB = $createEstimate('manual-conversion');
    $estimateC = $createEstimate('automatic');
    $estimateN = $createEstimate('numbering');

    // Case B: conversion route creates a draft exactly as the original modal, then normal RISE issuance makes it unpaid.
    $estimateBRow = (new App\Models\Estimates_model())->get_one($estimateB);
    $invoiceIds['conversion'] = (new App\Services\EstimateToInvoiceService())->createFromEstimate($estimateBRow, 1, 'draft', ['bill_date'=>$today,'due_date'=>$due]);
    $status = ['status'=>'not_paid']; (new App\Models\Invoices_model())->ci_save($status, $invoiceIds['conversion']);

    // Case C: acceptance uses the same creator but requests the final unpaid status atomically.
    $result = (new App\Services\EstimateAcceptanceService())->acceptAndFulfill($estimateC, [], true, 1);
    $invoiceIds['automatic'] = $result['invoice_id'];

    $rows=[];$parts=[];
    foreach($invoiceIds as $case=>$id){$rows[$case]=$db->table('invoices')->where('id',$id)->get()->getRowArray();$parts[$case]=$db->table('invoice_items')->where(['invoice_id'=>$id,'deleted'=>0])->orderBy('sort')->get()->getResultArray();}
    $ignore=['id','display_id','number_sequence','estimate_id'];
    $normalized=[];
    foreach($rows as $case=>$row){foreach($ignore as $field)unset($row[$field]);$normalized[$case]=$row;}
    $normalizeParts=static function(array $set):array{return array_map(static function(array $row){unset($row['id'],$row['invoice_id']);return $row;},$set);};

    $assert($normalized['manual']===$normalized['conversion'] && $normalized['manual']===$normalized['automatic'],'all non-identity invoice columns are equivalent');
    $assert($normalizeParts($parts['manual'])===$normalizeParts($parts['conversion']) && $normalizeParts($parts['manual'])===$normalizeParts($parts['automatic']),'all non-identity invoice item columns are equivalent');
    $assert(count($parts['manual'])===2 && count($parts['conversion'])===2 && count($parts['automatic'])===2,'all three cases contain two items');
    $assert($rows['manual']['invoice_total']===$rows['conversion']['invoice_total'] && $rows['manual']['invoice_total']===$rows['automatic']['invoice_total'],'all three cases have identical official totals');
    $assert($rows['automatic']['status']==='not_paid' && (int)$rows['automatic']['project_id']===0,'automatic sale is unpaid and has no project');
    $assert((int)$rows['automatic']['estimate_id']===$estimateC,'automatic sale keeps estimate_id');
    $assert((new App\Models\Invoices_model())->get_details(['id'=>$invoiceIds['automatic'],'start_date'=>date('Y-m-01'),'end_date'=>date('Y-m-t'),'date_filter_field'=>'bill_date'])->getRow() !== null,'automatic sale is visible in general monthly list');
    $assert((new App\Models\Invoices_model())->get_details(['id'=>$invoiceIds['automatic'],'client_id'=>$client->id])->getRow() !== null,'automatic sale is visible in client list');
    $assert($db->table('projects')->whereIn('estimate_id',[$estimateB,$estimateC,$estimateN])->countAllResults()===0,'no project was created');
    $assert($estimateB + 1 === $estimateC && $estimateC + 1 === $estimateN,'three controlled estimates receive consecutive internal/visible numbers');
    $before=(int)(new App\Models\Estimates_model())->get_one($estimateC)->id;
    (new App\Services\EstimateAcceptanceService())->acceptAndFulfill($estimateC,[],true,1);
    $assert((int)(new App\Models\Estimates_model())->get_one($estimateC)->id===$before,'acceptance/reprocessing does not change estimate number');
    $assert($db->table('invoices')->where(['estimate_id'=>$estimateC,'deleted'=>0])->countAllResults()===1,'second automatic execution creates no duplicate');
    $onePartEstimate=$createEstimate('one-part',[$items[0]]);
    $onePartResult=(new App\Services\EstimateAcceptanceService())->acceptAndFulfill($onePartEstimate,[],true,1);
    $invoiceIds['one_part']=$onePartResult['invoice_id'];
    $assert($db->table('invoice_items')->where(['invoice_id'=>$onePartResult['invoice_id'],'deleted'=>0])->countAllResults()===1,'one-item estimate creates one complete sale item');
    $emptyEstimate=$createEstimate('empty',[]);
    try{(new App\Services\EstimateAcceptanceService())->acceptAndFulfill($emptyEstimate,[],true,1);$emptyRejected=false;}catch(RuntimeException $e){$emptyRejected=str_contains($e->getMessage(),'no contiene partidas');}
    $assert($emptyRejected && (new App\Models\Estimates_model())->get_one($emptyEstimate)->status==='sent' && $db->table('invoices')->where(['estimate_id'=>$emptyEstimate,'deleted'=>0])->countAllResults()===0,'empty estimate is rejected and fully rolled back');
    $assert($db->table('fiscal_profiles')->where(['client_id'=>$client->id,'status'=>'ready'])->countAllResults()===0,'client without a ready fiscal/RFC profile remains operable');
    $badHeader=$common;$badHeader['note']='Increment02 forced item rollback';$badItems=[$items[0],array_replace($items[1],['quantity'=>new stdClass()])];
    try{(new App\Services\InvoiceCreationService())->create($badHeader,$badItems);$itemRollback=false;}catch(Throwable $e){$itemRollback=true;}
    $assert($itemRollback && $db->table('invoices')->where('note','Increment02 forced item rollback')->countAllResults()===0,'failure after header/first item rolls back the complete invoice');

    echo 'CONTROL_IDS=' . json_encode(['invoices'=>$invoiceIds,'estimates'=>['conversion'=>$estimateB,'automatic'=>$estimateC,'numbering'=>$estimateN]], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    echo 'INVOICE_COMPARISON=' . json_encode($rows, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    echo 'ITEM_COMPARISON=' . json_encode($parts, JSON_UNESCAPED_UNICODE) . PHP_EOL;
} finally {
    if ($invoiceIds) {
        $ids=array_values($invoiceIds);
        $db->table('invoice_payments')->whereIn('invoice_id',$ids)->delete();
        $db->table('invoice_items')->whereIn('invoice_id',$ids)->delete();
        $db->table('custom_field_values')->where('related_to_type','invoices')->whereIn('related_to_id',$ids)->delete();
        $db->table('invoices')->whereIn('id',$ids)->delete();
    }
    if ($estimateIds) {
        $db->table('estimate_items')->whereIn('estimate_id',$estimateIds)->delete();
        $db->table('estimates')->whereIn('id',$estimateIds)->delete();
    }
    if ($fixtureClientId) $db->table('clients')->where('id',$fixtureClientId)->delete();
}

echo "Equivalence failures: {$fail}\n";
exit($fail ? 1 : 0);
