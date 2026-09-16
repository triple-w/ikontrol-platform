<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
helper(['general', 'date_time', 'plugin']);
require_once APPPATH . 'ThirdParty/PHP-Hooks/php-hooks.php';

use App\Controllers\Estimate;
use App\Models\Invoice_payments_model;
use App\Models\Invoices_model;
use Config\Database;
use Config\Services;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$passes): void {
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    $condition ? $passes++ : $failures++;
};

$databaseConfig = config('Database');
$source = Database::connect($databaseConfig->default, false);
$sourceDatabase = (string) $source->query('SELECT DATABASE() AS database_name')->getRow()->database_name;
$testDatabase = preg_replace('/[^a-zA-Z0-9_]/', '_', $sourceDatabase) . '_acceptance_e2e_' . bin2hex(random_bytes(4));
$testDatabaseQuoted = '`' . $testDatabase . '`';
$sourceDatabaseQuoted = '`' . str_replace('`', '``', $sourceDatabase) . '`';
$testDb = null;
$stage = 'initialization';

try {
    if ($sourceDatabase === '' || str_contains(strtolower($sourceDatabase), 'production')) {
        throw new RuntimeException('The source connection is not a verified local development database.');
    }

    $stage = 'create isolated database';
    $source->query("CREATE DATABASE $testDatabaseQuoted CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $stage = 'clone source tables';
    foreach ($source->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'')->getResultArray() as $row) {
        $table = (string) reset($row);
        $tableQuoted = '`' . str_replace('`', '``', $table) . '`';
        $source->query("CREATE TABLE $testDatabaseQuoted.$tableQuoted LIKE $sourceDatabaseQuoted.$tableQuoted");
        $source->query("INSERT INTO $testDatabaseQuoted.$tableQuoted SELECT * FROM $sourceDatabaseQuoted.$tableQuoted");
    }

    $stage = 'connect isolated database';
    $databaseConfig->default['database'] = $testDatabase;
    $databaseConfig->tests = $databaseConfig->default;
    $databaseConfig->defaultGroup = 'default';
    $testDb = db_connect('default');

    $reloadSettings = static function () use ($testDb): void {
        $settings = [];
        foreach ($testDb->table('settings')->where('deleted', 0)->get()->getResult() as $setting) {
            $settings[$setting->setting_name] = $setting->setting_value;
        }
        $settings['timezone'] = $settings['timezone'] ?? 'UTC';
        config('Rise')->app_settings_array = $settings;
    };
    $reloadSettings();

    $client = $testDb->table('clients')->where('deleted', 0)->orderBy('id')->get(1)->getRow();
    $user = $testDb->table('users')->where(['deleted' => 0, 'user_type' => 'staff'])->orderBy('is_admin', 'DESC')->get(1)->getRow();
    $tax = $testDb->table('taxes')->where('deleted', 0)->orderBy('id')->get(1)->getRow();
    $sourceItem = $testDb->table('estimate_items')->where('deleted', 0)->orderBy('id')->get(1)->getRowArray();
    if (!$client || !$user || !$sourceItem) {
        throw new RuntimeException('The isolated database needs a client, staff user, and estimate item fixture source.');
    }

    $saveSettingThroughController = static function (?string $value) use ($user, $testDb): array {
        session()->set('user_id', (int) $user->id);
        $settingNames = [
            'estimate_prefix',
            'estimate_color',
            'estimate_footer',
            'send_estimate_bcc_to',
            'initial_number_of_the_estimate',
            'enable_comments_on_estimates',
            'show_most_recent_estimate_comments_at_the_top',
            'add_signature_option_on_accepting_estimate',
            'enable_estimate_lock_state',
        ];
        $post = [];
        foreach ($testDb->table('settings')->whereIn('setting_name', $settingNames)->get()->getResult() as $setting) {
            $post[$setting->setting_name] = $setting->setting_value;
        }
        if ($value !== null) {
            $post['create_new_invoices_automatically_when_estimates_gets_accepted'] = $value;
        }
        $request = Services::incomingrequest(null, false);
        $request->setMethod('POST');
        $request->setGlobal('post', $post);
        $request->setGlobal('request', $post);
        $controller = new App\Controllers\Settings();
        $controller->initController($request, service('response'), service('logger'));
        ob_start();
        $controller->save_estimate_settings();
        return json_decode((string) ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
    };

    $createEstimate = static function (string $suffix) use ($testDb, $client, $user, $tax, $sourceItem): int {
        $publicKey = 'acceptance-e2e-' . $suffix . '-' . bin2hex(random_bytes(6));
        $testDb->table('estimates')->insert([
            'client_id' => (int) $client->id,
            'estimate_date' => date('Y-m-d'),
            'valid_until' => date('Y-m-d', strtotime('+7 days')),
            'note' => 'Isolated acceptance E2E ' . $suffix,
            'status' => 'sent',
            'tax_id' => (int) ($tax->id ?? 0),
            'tax_id2' => 0,
            'discount_type' => 'before_tax',
            'discount_amount' => '5',
            'discount_amount_type' => 'percentage',
            'project_id' => 0,
            'accepted_by' => 0,
            'meta_data' => '',
            'created_by' => (int) $user->id,
            'signature' => '',
            'public_key' => $publicKey,
            'company_id' => (int) ($client->company_id ?? 0),
            'deleted' => 0,
        ]);
        $estimateId = (int) $testDb->insertID();

        foreach ([1, 2] as $sort) {
            $item = $sourceItem;
            unset($item['id']);
            $item['estimate_id'] = $estimateId;
            $item['title'] = 'Acceptance E2E item ' . $sort;
            $item['quantity'] = (string) $sort;
            $item['rate'] = '100.00';
            $item['total'] = number_format($sort * 100, 2, '.', '');
            $item['sort'] = $sort - 1;
            $item['deleted'] = 0;
            $testDb->table('estimate_items')->insert($item);
        }
        return $estimateId;
    };

    $acceptThroughPublicController = static function (int $estimateId) use ($testDb): array {
        $estimate = $testDb->table('estimates')->where('id', $estimateId)->get()->getRow();
        $post = [
            'id' => $estimateId,
            'public_key' => $estimate->public_key,
            'name' => 'Acceptance E2E',
            'email' => 'acceptance-e2e@example.test',
        ];
        $request = Services::incomingrequest(null, false);
        $request->setMethod('POST');
        $request->setGlobal('post', $post);
        $request->setGlobal('request', $post);
        service('validation')->reset();
        $controller = new Estimate();
        $controller->initController($request, service('response'), service('logger'));
        ob_start();
        $controller->accept_estimate();
        return json_decode((string) ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
    };

    $stage = 'save enabled setting through controller';
    $enabledSave = $saveSettingThroughController('1');
    $reloadSettings();
    $enabledStored = $testDb->table('settings')->where('setting_name', 'create_new_invoices_automatically_when_estimates_gets_accepted')->get()->getRow();
    $assert(($enabledSave['success'] ?? false) && $enabledStored->setting_value === '1', 'settings controller persists the enabled value exactly as "1"');
    $enabledSettingsHtml = view('settings/estimates', ['last_id' => 0, 'login_user' => $user]);
    $assert((bool) preg_match('/<input(?=[^>]*name="create_new_invoices_automatically_when_estimates_gets_accepted")(?=[^>]*checked)[^>]*>/i', $enabledSettingsHtml), 'settings view reloads with the automatic-sale checkbox checked');

    $stage = 'create enabled estimate fixture';
    $enabledEstimateId = $createEstimate('enabled');
    $projectsBefore = $testDb->table('projects')->countAllResults();
    $stage = 'accept enabled estimate through public controller';
    $enabledResult = $acceptThroughPublicController($enabledEstimateId);
    $invoices = $testDb->table('invoices')->where(['estimate_id' => $enabledEstimateId, 'deleted' => 0])->get()->getResult();
    $invoice = $invoices[0] ?? null;
    $assert(($enabledResult['success'] ?? false) && ($enabledResult['invoice_action'] ?? '') === 'created', 'public HTTP controller reports created when the persisted setting is "1"');
    $assert(count($invoices) === 1 && $invoice !== null, 'enabled acceptance creates exactly one sale');
    $converted=$testDb->table('estimates')->where(['id'=>$enabledEstimateId,'status'=>'converted'])->get(1)->getRow();
    $assert($converted&&(int)$converted->converted_sale_id===(int)$invoice->id&&$converted->converted_at&&$converted->converted_by,'enabled acceptance records the completed sale conversion');
    $assert((int) $invoice->client_id === (int) $client->id && (int) $invoice->estimate_id === $enabledEstimateId, 'sale keeps client_id and estimate_id');
    $assert((int) $invoice->project_id === 0 && $testDb->table('projects')->countAllResults() === $projectsBefore, 'acceptance creates zero projects');
    $invoiceItems = $testDb->table('invoice_items')->where(['invoice_id' => $invoice->id, 'deleted' => 0])->orderBy('sort')->get()->getResult();
    $assert(count($invoiceItems) === 2 && $invoiceItems[0]->title === 'Acceptance E2E item 1' && $invoiceItems[1]->title === 'Acceptance E2E item 2', 'sale contains the two source items');
    $assert((int) $invoice->tax_id === (int) ($tax->id ?? 0) && (string) $invoice->discount_amount === '5', 'sale copies administrative tax and discount');
    $estimateSummary = (new App\Models\Estimates_model())->get_estimate_total_summary($enabledEstimateId);
    $assert(abs((float) $invoice->invoice_total - (float) $estimateSummary->estimate_total) < 0.001, 'sale total matches the estimate total');
    $assert($invoice->status === 'not_paid', 'sale is pending payment');
    $generalRow = (new Invoices_model())->get_details(['id' => $invoice->id])->getRow();
    $clientRow = (new Invoices_model())->get_details(['id' => $invoice->id, 'client_id' => $client->id])->getRow();
    $assert((bool) $generalRow, 'sale is visible through the general invoice query');
    $assert((bool) $clientRow, 'sale is visible through the client invoice query');

    $paymentMethod = $testDb->table('payment_methods')->where('deleted', 0)->orderBy('id')->get(1)->getRow();
    if ($paymentMethod) {
        $stage = 'register payment on generated sale';
        $paymentData = [
            'invoice_id' => (int) $invoice->id,
            'payment_date' => date('Y-m-d'),
            'payment_method_id' => (int) $paymentMethod->id,
            'note' => 'Isolated acceptance E2E payment',
            'amount' => '1.00',
            'created_at' => get_current_utc_time(),
            'created_by' => (int) $user->id,
        ];
        $paymentId = (new Invoice_payments_model())->ci_save($paymentData);
        (new Invoices_model())->update_invoice_status((int) $invoice->id);
        $assert((bool) (new Invoice_payments_model())->get_details(['id' => $paymentId, 'invoice_id' => $invoice->id])->getRow(), 'generated sale accepts a payment');
    } else {
        echo "[SKIP] no active payment method available in the isolated clone\n";
    }

    $stage = 'repeat enabled acceptance through public controller';
    $secondResult = $acceptThroughPublicController($enabledEstimateId);
    $assert(($secondResult['invoice_action'] ?? '') === 'existing' && $testDb->table('invoices')->where(['estimate_id' => $enabledEstimateId, 'deleted' => 0])->countAllResults() === 1, 'repeated public acceptance reports existing and creates no duplicate');

    $stage = 'save disabled setting through controller';
    $disabledSave = $saveSettingThroughController(null);
    $reloadSettings();
    $disabledStored = $testDb->table('settings')->where('setting_name', 'create_new_invoices_automatically_when_estimates_gets_accepted')->get()->getRow();
    $assert(($disabledSave['success'] ?? false) && $disabledStored->setting_value === '0', 'missing checkbox POST persists the disabled value exactly as "0"');
    $disabledSettingsHtml = view('settings/estimates', ['last_id' => 0, 'login_user' => $user]);
    $assert(!(bool) preg_match('/<input(?=[^>]*name="create_new_invoices_automatically_when_estimates_gets_accepted")(?=[^>]*checked)[^>]*>/i', $disabledSettingsHtml), 'settings view reloads with the automatic-sale checkbox unchecked');

    $stage = 'create disabled estimate fixture';
    $disabledEstimateId = $createEstimate('disabled');
    $disabledProjectsBefore = $testDb->table('projects')->countAllResults();
    $stage = 'accept disabled estimate through public controller';
    $disabledResult = $acceptThroughPublicController($disabledEstimateId);
    $assert(($disabledResult['success'] ?? false) && ($disabledResult['invoice_action'] ?? '') === 'disabled', 'public HTTP controller reports disabled when the persisted setting is "0"');
    $assert($testDb->table('estimates')->where(['id' => $disabledEstimateId, 'status' => 'accepted'])->countAllResults() === 1, 'disabled acceptance still marks the estimate accepted');
    $assert($testDb->table('invoices')->where('estimate_id', $disabledEstimateId)->countAllResults() === 0, 'disabled acceptance creates no sale');
    $assert($testDb->table('projects')->countAllResults() === $disabledProjectsBefore, 'disabled acceptance creates zero projects');
} catch (Throwable $e) {
    echo '[FAIL] Stage "' . $stage . '": ' . get_class($e) . ': ' . $e->getMessage() . PHP_EOL;
    $failures++;
} finally {
    try {
        $source->query("DROP DATABASE IF EXISTS $testDatabaseQuoted");
    } catch (Throwable $cleanupError) {
        echo '[FAIL] Could not remove isolated test database: ' . $cleanupError->getMessage() . PHP_EOL;
        $failures++;
    }
}

echo "\n$passes passed, $failures failed.\n";
exit($failures === 0 ? 0 : 1);
