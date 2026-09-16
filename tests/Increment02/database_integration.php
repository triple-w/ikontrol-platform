<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
helper(['general', 'date_time', 'plugin']);
require_once APPPATH . 'ThirdParty/PHP-Hooks/php-hooks.php';

$db = require __DIR__ . '/isolated_database.php';
$settings = [];
foreach ($db->table('settings')->get()->getResult() as $setting) $settings[$setting->setting_name] = $setting->setting_value;
if (empty($settings['timezone'])) $settings['timezone'] = 'UTC';
config('Rise')->app_settings_array = $settings;
session();
$pass = 0; $fail = 0;
$assert = static function (bool $condition, string $message) use (&$pass, &$fail): void {
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    $condition ? $pass++ : $fail++;
};

foreach (['sat_tax_codes', 'sat_tax_factor_types', 'sat_tax_regimes', 'sat_cfdi_uses', 'fiscal_profiles'] as $table) {
    $assert($db->tableExists($table), "$table exists");
}
foreach (['sat_tax_codes', 'sat_tax_factor_types', 'sat_tax_regimes', 'sat_cfdi_uses'] as $table) {
    $assert($db->table($table)->countAllResults() > 0, "$table contains seeded rows");
}
foreach (['sat_tax_code_id','fiscal_tax_type','factor_type_id','xml_rate','xml_quota','is_fiscal_ready','use_for_administrative','use_for_fiscal','fiscal_notes','updated_at'] as $field) {
    $assert($db->fieldExists($field, 'taxes'), "taxes.$field exists");
}

$rolesWithFiscalKeys = 0; $roleCount = 0;
foreach ($db->table('roles')->select('permissions')->where('deleted', 0)->get()->getResult() as $role) {
    $roleCount++;
    $permissions = @unserialize((string) $role->permissions) ?: [];
    foreach (['can_view_fiscal_profiles','can_manage_fiscal_profiles','can_view_fiscal_tax_settings','can_manage_fiscal_tax_settings'] as $key) {
        if (array_key_exists($key, $permissions)) { $rolesWithFiscalKeys++; break; }
    }
}
$assert($rolesWithFiscalKeys === 0, "all $roleCount existing roles still omit fiscal permission keys");
$db->transBegin();
try {
    $legacyPermissions=['invoice'=>'all'];
    $db->table('roles')->insert(['title'=>'Increment02 legacy fixture','permissions'=>serialize($legacyPermissions),'deleted'=>0]);
    $fixtureRoleId=(int)$db->insertID();
    $legacyPermissions['invoice']='own';
    $db->table('roles')->where('id',$fixtureRoleId)->update(['permissions'=>serialize($legacyPermissions)]);
    $stored=@unserialize((string)$db->table('roles')->select('permissions')->where('id',$fixtureRoleId)->get()->getRow()->permissions)?:[];
    $assert(!array_key_exists('can_view_fiscal_profiles',$stored),'editing a non-fiscal permission keeps fiscal keys absent');
    $stored['can_view_fiscal_profiles']='1'; $db->table('roles')->where('id',$fixtureRoleId)->update(['permissions'=>serialize($stored)]);
    $stored=@unserialize((string)$db->table('roles')->select('permissions')->where('id',$fixtureRoleId)->get()->getRow()->permissions)?:[];
    $assert(isset($stored['can_view_fiscal_profiles']) && !isset($stored['can_manage_fiscal_profiles']),'only the explicitly selected fiscal permission is stored');
    unset($stored['can_view_fiscal_profiles']); $db->table('roles')->where('id',$fixtureRoleId)->update(['permissions'=>serialize($stored)]);
    $stored=@unserialize((string)$db->table('roles')->select('permissions')->where('id',$fixtureRoleId)->get()->getRow()->permissions)?:[];
    $assert(!array_key_exists('can_view_fiscal_profiles',$stored),'unchecking the fiscal permission removes its key');
} finally { $db->transRollback(); }

$taxes = new App\Models\Taxes_model();
$taxService = new App\Services\Fiscal\TaxFiscalConfigurationService();
$createdTaxIds = [];
$db->transBegin();
try {
    $administrative = $taxService->prepare(['use_for_administrative'=>1,'use_for_fiscal'=>0], null, null);
    $assert($administrative['errors'] === [] && $administrative['data']['xml_rate'] === null, 'administrative tax accepts NULL fiscal fields');
    $taxData = ['title'=>'Prueba administrativa 10%','percentage'=>'10'] + $administrative['data'];
    $taxId = (int) $taxes->ci_save($taxData);
    $createdTaxIds[] = $taxId;
    $assert($taxId > 0, 'administrative tax can be created');
    $editData=['title'=>'Prueba administrativa editada']; $assert((bool) $taxes->ci_save($editData, $taxId), 'administrative tax can be edited');
    $assert((bool) $taxes->get_dropdown_list(['title'])[$taxId], 'administrative tax is available to estimate/invoice dropdowns');

    $code = model(App\Models\Fiscal\Sat_tax_codes_model::class)->get_one_where(['code'=>'002']);
    $factor = model(App\Models\Fiscal\Sat_tax_factor_types_model::class)->get_one_where(['code'=>'Tasa']);
    $iva = $taxService->prepare(['use_for_administrative'=>1,'use_for_fiscal'=>1,'sat_tax_code_id'=>$code->id,'fiscal_tax_type'=>'transfer','factor_type_id'=>$factor->id,'xml_rate'=>'0.160000','xml_quota'=>''], $code, $factor);
    $assert($iva['errors'] === [] && $iva['data']['xml_rate'] === '0.160000', 'IVA 16% preserves exact XML decimal string');
    $ivaData=['title'=>'IVA 16% prueba','percentage'=>'16'] + $iva['data']; $ivaId = (int) $taxes->ci_save($ivaData);
    $createdTaxIds[] = $ivaId;
    $stored = $taxes->get_one($ivaId);
    $assert($ivaId > 0 && $stored->xml_rate === '0.160000' && (string)$stored->percentage === '16', 'fiscal IVA can be created with administrative percentage 16');
    $ivaEdit=['fiscal_notes'=>'edición de prueba']; $assert((bool)$taxes->ci_save($ivaEdit, $ivaId), 'fiscal IVA can be edited');

    $missingRate = $taxService->prepare(['use_for_fiscal'=>1,'sat_tax_code_id'=>$code->id,'fiscal_tax_type'=>'transfer','factor_type_id'=>$factor->id], $code, $factor);
    $assert($missingRate['errors'] !== [], 'Tasa without XML rate is rejected');
    $quotaFactor = model(App\Models\Fiscal\Sat_tax_factor_types_model::class)->get_one_where(['code'=>'Cuota']);
    $missingQuota = $taxService->prepare(['use_for_fiscal'=>1,'sat_tax_code_id'=>$code->id,'fiscal_tax_type'=>'transfer','factor_type_id'=>$quotaFactor->id], $code, $quotaFactor);
    $assert($missingQuota['errors'] !== [], 'Cuota without XML quota is rejected');
    $exempt = model(App\Models\Fiscal\Sat_tax_factor_types_model::class)->get_one_where(['code'=>'Exento']);
    $exemptResult = $taxService->prepare(['use_for_fiscal'=>1,'sat_tax_code_id'=>$code->id,'fiscal_tax_type'=>'transfer','factor_type_id'=>$exempt->id,'xml_rate'=>'0.1'], $code, $exempt);
    $assert($exemptResult['errors'] === [] && $exemptResult['data']['xml_rate'] === null, 'Exento clears incompatible rates');

    $legacy = $taxes->get_details(['id'=>$taxId])->getRow();
    $assert($legacy && $legacy->sat_tax_code === null, 'legacy/administrative NULL fiscal relations remain listable');
} finally {
    $db->transRollback();
    if ($createdTaxIds) $db->table('taxes')->whereIn('id', $createdTaxIds)->delete();
}

$source = $db->table('estimates')->where('deleted',0)->whereIn('status',['draft','sent','accepted'])->orderBy('id','DESC')->get(1)->getRowArray();
if ($source && $db->table('estimate_items')->where(['estimate_id'=>$source['id'],'deleted'=>0])->countAllResults() > 0) {
    $originalId = $source['id']; unset($source['id']);
    $source['status']='sent'; $source['project_id']=0; $source['public_key']='increment02-test-'.bin2hex(random_bytes(6));
    $db->table('estimates')->insert($source); $fixtureEstimateId=(int)$db->insertID();
    $items=$db->table('estimate_items')->where(['estimate_id'=>$originalId,'deleted'=>0])->get()->getResultArray();
    foreach($items as $item){unset($item['id']);$item['estimate_id']=$fixtureEstimateId;$db->table('estimate_items')->insert($item);}
    $projectBefore=$db->table('projects')->where('estimate_id',$fixtureEstimateId)->countAllResults();
    try {
        $service=new App\Services\EstimateAcceptanceService();
        $disabled=$service->acceptAndFulfill($fixtureEstimateId,[],false,(int)$source['created_by']);
        $assert($disabled['invoice_action']==='disabled' && $disabled['invoice_id']===null && (new App\Models\Estimates_model())->get_one($fixtureEstimateId)->status==='accepted','disabled acceptance accepts estimate and creates no sale');
        $reset=['status'=>'sent']; (new App\Models\Estimates_model())->ci_save($reset,$fixtureEstimateId);
        $failingCreator=new class extends App\Services\InvoiceCreationService { public function create(array $header,array $items): int { throw new RuntimeException('Injected item failure'); } };
        $failingConverter=new App\Services\EstimateToInvoiceService($failingCreator);
        try{(new App\Services\EstimateAcceptanceService($failingConverter))->acceptAndFulfill($fixtureEstimateId,[],true,(int)$source['created_by']);$rolledBack=false;}catch(RuntimeException $e){$rolledBack=$e->getMessage()==='Injected item failure';}
        $afterFailureInvoices=$db->table('invoices')->where(['estimate_id'=>$fixtureEstimateId,'deleted'=>0])->countAllResults();$afterFailureStatus=(new App\Models\Estimates_model())->get_one($fixtureEstimateId)->status;
        $assert($rolledBack && $afterFailureInvoices===0 && $afterFailureStatus==='sent',"item failure rolls back acceptance and invoice header [caught=".(int)$rolledBack." invoices=$afterFailureInvoices status=$afterFailureStatus]");
        $first=$service->acceptAndFulfill($fixtureEstimateId,['accepted_by'=>(int)$source['created_by']],true,(int)$source['created_by']);
        $invoice=$db->table('invoices')->where('id',$first['invoice_id'])->get()->getRow();
        $convertedEstimate=$db->table('estimates')->where('id',$fixtureEstimateId)->get()->getRow();
        $assert($first['created_invoice'] && $invoice->status==='not_paid','accepted estimate creates an unpaid sale');
        $assert((int)$convertedEstimate->converted_sale_id===(int)$invoice->id && $convertedEstimate->converted_at && (int)$convertedEstimate->converted_by===(int)$source['created_by'],'conversion stores sale, timestamp and actor');
        $assert((int)$convertedEstimate->accepted_by===(int)$source['created_by'],'conversion preserves accepted_by');
        $assert((int)$invoice->estimate_id===$fixtureEstimateId && (int)$invoice->client_id===(int)$source['client_id'],'sale keeps estimate and client references');
        $assert((int)$invoice->company_id===(int)$source['company_id'] && (int)$invoice->tax_id===(int)$source['tax_id'] && (int)$invoice->tax_id2===(int)$source['tax_id2'],'sale keeps company and administrative taxes');
        $assert((string)$invoice->discount_amount===(string)$source['discount_amount'] && $invoice->discount_type===$source['discount_type'] && $invoice->discount_amount_type===$source['discount_amount_type'],'sale keeps discount configuration');
        $expectedDue=date('Y-m-d',strtotime('+'.(int)get_setting('default_due_date_after_billing_date').' days',strtotime(get_my_local_time('Y-m-d'))));
        $assert($invoice->bill_date===get_my_local_time('Y-m-d') && $invoice->due_date===$expectedDue,'sale dates match the existing manual-conversion default');
        $assert($db->table('projects')->where('estimate_id',$fixtureEstimateId)->countAllResults()===$projectBefore,'acceptance creates no project');
        $assert($db->table('invoice_items')->where(['invoice_id'=>$invoice->id,'deleted'=>0])->countAllResults()===count($items),'all estimate items are copied');
        $estimateSummary=(new App\Models\Estimates_model())->get_estimate_total_summary($fixtureEstimateId);
        $invoice=(new App\Models\Invoices_model())->get_one($invoice->id);
        $assert(abs((float)$invoice->invoice_subtotal-(float)$estimateSummary->estimate_subtotal)<0.001 && abs((float)$invoice->invoice_total-(float)$estimateSummary->estimate_total)<0.001,'sale subtotal and total match the estimate');
        $listed=(new App\Models\Invoices_model())->get_details(['id'=>$invoice->id])->getRow();
        $assert($listed && $listed->status==='not_paid','generated sale appears in the normal invoice query');
        $paymentMethod=$db->table('payment_methods')->where('deleted',0)->orderBy('id')->get(1)->getRow();
        if($paymentMethod){
            $paymentData=['invoice_id'=>$invoice->id,'payment_date'=>get_my_local_time('Y-m-d'),'payment_method_id'=>$paymentMethod->id,'note'=>'Increment02 payment fixture','amount'=>'1.00','created_at'=>get_current_utc_time(),'created_by'=>(int)$source['created_by']];
            $paymentId=(new App\Models\Invoice_payments_model())->ci_save($paymentData);
            (new App\Models\Invoices_model())->update_invoice_status($invoice->id);
            $assert((bool)(new App\Models\Invoice_payments_model())->get_details(['id'=>$paymentId,'invoice_id'=>$invoice->id])->getRow(),'generated sale accepts and exposes a real payment fixture');
        }else{echo "[SKIP] no active payment method was available for payment regression\n";}
        $draftData=['status'=>'draft']; (new App\Models\Invoices_model())->ci_save($draftData,$invoice->id);
        $acceptedAgain=['status'=>'accepted']; (new App\Models\Estimates_model())->ci_save($acceptedAgain,$fixtureEstimateId);
        $promoted=$service->fulfill($fixtureEstimateId,true,(int)$source['created_by']);
        $assert($promoted['repaired_invoice'] && (new App\Models\Invoices_model())->get_one($invoice->id)->status==='not_paid','an exact automatic draft is safely promoted to unpaid');
        $second=$service->acceptAndFulfill($fixtureEstimateId,['accepted_by'=>(int)$source['created_by']],true,(int)$source['created_by']);
        $assert(!$second['created_invoice'] && $db->table('invoices')->where(['estimate_id'=>$fixtureEstimateId,'deleted'=>0])->countAllResults()===1,'reprocessing is idempotent');
    } finally {
        $invoiceIds=array_column($db->table('invoices')->select('id')->where('estimate_id',$fixtureEstimateId)->get()->getResultArray(),'id');
        if($invoiceIds){$db->table('invoice_payments')->whereIn('invoice_id',$invoiceIds)->delete();$db->table('invoice_items')->whereIn('invoice_id',$invoiceIds)->delete();}
        $db->table('invoices')->where('estimate_id',$fixtureEstimateId)->delete();
        $db->table('estimate_items')->where('estimate_id',$fixtureEstimateId)->delete();
        $db->table('estimates')->where('id',$fixtureEstimateId)->delete();
    }
} else {
    echo "[SKIP] no existing estimate with items was available as a local fixture\n";
}

echo "\n$pass passed, $fail failed.\n";
exit($fail === 0 ? 0 : 1);
