<?php
declare(strict_types=1);

// This runner creates one synthetic, owned MySQL schema. It never selects,
// copies, or writes Base/Navika commercial data and does not load PAC code.
require dirname(__DIR__) . '/bootstrap.php';
require_once APPPATH . 'ThirdParty/PHP-Hooks/php-hooks.php';
helper(['plugin', 'general', 'date_time']);
config('Rise')->app_settings_array['timezone'] = 'America/Mexico_City';
config('Rise')->app_settings_array['default_due_date_after_billing_date'] = '0';

use App\Services\InvoiceCreationService;
use App\Services\ProposalAcceptanceService;
use App\Services\ProposalToInvoiceService;
use App\Services\ProposalTotalsService;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

final class ProposalFiscalFixtureInvoiceCreator extends InvoiceCreationService
{
    public bool $failAfterInsert = false;
    public array $headers = [];
    public array $rows = [];

    public function __construct(private BaseConnection $fixtureDb)
    {
        parent::__construct($fixtureDb);
    }

    public function create(array $header, array $items, bool $manageTransaction = true): int
    {
        $this->headers[] = $header;
        $this->rows[] = $items;
        $subtotal = '0.000000';
        foreach ($items as $item) {
            $subtotal = App\Services\Fiscal\FiscalDecimal::add($subtotal, (string) $item['total']);
        }
        $tax = fn (int $id): string => $id
            ? (string) ($this->fixtureDb->table('taxes')->select('percentage')->where('id', $id)->get()->getRow()->percentage ?? '0')
            : '0';
        $totals = (new ProposalTotalsService())->calculate(
            $subtotal, $header['discount_amount'], $header['discount_amount_type'], $header['discount_type'],
            $tax((int) $header['tax_id']), $tax((int) $header['tax_id2'])
        );
        $this->fixtureDb->table('invoices')->insert([
            'proposal_id' => $header['proposal_id'], 'deleted' => 0,
            'invoice_subtotal' => $totals['subtotal'], 'discount_total' => $totals['discount'],
            'tax' => $totals['tax'], 'tax2' => $totals['tax2'], 'invoice_total' => $totals['grand_total'],
        ]);
        $id = (int) $this->fixtureDb->insertID();
        if ($this->failAfterInsert) {
            throw new RuntimeException('Synthetic failure after invoice insert.');
        }
        return $id;
    }
}

$server = $db = null; $owned = null; $passed = 0; $exit = 0;
$assert = static function (bool $value, string $label) use (&$passed): void {
    if (! $value) throw new RuntimeException($label);
    $passed++; echo "[PASS] {$label}\n";
};

try {
    $local = config(Database::class)->default;
    if (! in_array($local['hostname'], ['localhost', '127.0.0.1', '::1'], true)) {
        throw new RuntimeException('A local MySQL server is required for the owned fixture.');
    }
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $server = new mysqli($local['hostname'], $local['username'], $local['password'], '', (int) $local['port']);
    $owned = 'ikontrol_test_p06_' . bin2hex(random_bytes(6));
    $server->query("CREATE DATABASE `{$owned}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $config = array_replace($local, ['DSN'=>'', 'database'=>$owned, 'DBDriver'=>'MySQLi', 'DBPrefix'=>'p06_', 'pConnect'=>false, 'DBDebug'=>true, 'failover'=>[]]);
    $db = Database::connect($config, false);
    $assert($db->query('SELECT DATABASE() AS name')->getRow()->name === $owned, 'owned synthetic fixture target verified');
    $forge = Database::forge($db);
    $make = static function (string $table, array $fields) use ($forge): void {
        $forge->addField($fields); $forge->addKey('id', true); $forge->createTable($table);
    };
    $id = ['type'=>'INT', 'unsigned'=>true, 'auto_increment'=>true];
    $int = ['type'=>'INT', 'unsigned'=>true, 'default'=>0];
    $nullable = ['type'=>'INT', 'unsigned'=>true, 'null'=>true];
    $decimal = ['type'=>'DECIMAL', 'constraint'=>'18,6', 'default'=>'0.000000'];
    $make('clients', ['id'=>$id, 'deleted'=>$int, 'is_lead'=>$int, 'currency_symbol'=>['type'=>'VARCHAR','constraint'=>8,'default'=>'$']]);
    $make('company', ['id'=>$id, 'deleted'=>$int]);
    $make('projects', ['id'=>$id, 'client_id'=>$int, 'deleted'=>$int]);
    $make('items', ['id'=>$id, 'deleted'=>$int]);
    $make('taxes', ['id'=>$id, 'percentage'=>$decimal, 'deleted'=>$int]);
    $make('roles', ['id'=>$id, 'title'=>['type'=>'VARCHAR','constraint'=>80,'default'=>''], 'permissions'=>['type'=>'TEXT','null'=>true], 'deleted'=>$int]);
    $make('team', ['id'=>$id, 'members'=>['type'=>'TEXT','null'=>true]]);
    $make('users', ['id'=>$id, 'user_type'=>['type'=>'VARCHAR','constraint'=>20], 'is_admin'=>$int, 'role_id'=>$int, 'email'=>['type'=>'VARCHAR','constraint'=>120,'default'=>''], 'first_name'=>['type'=>'VARCHAR','constraint'=>80,'default'=>''], 'last_name'=>['type'=>'VARCHAR','constraint'=>80,'default'=>''], 'image'=>['type'=>'VARCHAR','constraint'=>255,'default'=>''], 'message_checked_at'=>['type'=>'DATETIME','null'=>true], 'notification_checked_at'=>['type'=>'DATETIME','null'=>true], 'client_id'=>$int, 'enable_web_notification'=>$int, 'is_primary_contact'=>$int, 'sticky_note'=>['type'=>'TEXT','null'=>true], 'language'=>['type'=>'VARCHAR','constraint'=>20,'default'=>''], 'client_permissions'=>['type'=>'TEXT','null'=>true], 'deleted'=>$int]);
    $make('proposals', ['id'=>$id, 'client_id'=>$int, 'company_id'=>$int, 'project_id'=>$int, 'status'=>['type'=>'VARCHAR','constraint'=>20], 'deleted'=>$int, 'public_key'=>['type'=>'VARCHAR','constraint'=>80,'default'=>''], 'created_by'=>$int, 'converted_sale_id'=>$nullable, 'accepted_by'=>$nullable, 'accepted_at'=>['type'=>'DATETIME','null'=>true], 'converted_at'=>['type'=>'DATETIME','null'=>true], 'converted_by'=>$nullable, 'tax_id'=>$int, 'tax_id2'=>$int, 'discount_amount'=>$decimal, 'discount_amount_type'=>['type'=>'VARCHAR','constraint'=>20], 'discount_type'=>['type'=>'VARCHAR','constraint'=>20], 'note'=>['type'=>'TEXT','null'=>true], 'meta_data'=>['type'=>'TEXT','null'=>true]]);
    $make('proposal_items', ['id'=>$id, 'proposal_id'=>$int, 'item_id'=>$int, 'title'=>['type'=>'VARCHAR','constraint'=>160], 'description'=>['type'=>'TEXT','null'=>true], 'quantity'=>$decimal, 'unit_type'=>['type'=>'VARCHAR','constraint'=>20], 'cost'=>['type'=>'DECIMAL','constraint'=>'18,6','null'=>true], 'profit_percentage'=>['type'=>'DECIMAL','constraint'=>'18,6','null'=>true], 'price_origin'=>['type'=>'VARCHAR','constraint'=>20,'default'=>'manual'], 'supplier_id'=>$nullable, 'rate'=>$decimal, 'total'=>$decimal, 'fiscal_override_json'=>['type'=>'TEXT','null'=>true], 'sort'=>$int, 'deleted'=>$int]);
    $make('invoices', ['id'=>$id, 'proposal_id'=>$int, 'deleted'=>$int, 'invoice_subtotal'=>$decimal, 'discount_total'=>$decimal, 'tax'=>$decimal, 'tax2'=>$decimal, 'invoice_total'=>$decimal]);

    $db->table('clients')->insert(['id'=>1, 'deleted'=>0, 'is_lead'=>0, 'currency_symbol'=>'$']);
    $db->table('company')->insert(['id'=>1, 'deleted'=>0]);
    $db->table('items')->insert(['id'=>1, 'deleted'=>0]);
    $db->table('taxes')->insertBatch([['id'=>1, 'percentage'=>'16.000000', 'deleted'=>0], ['id'=>2, 'percentage'=>'3.000000', 'deleted'=>0]]);
    $db->table('users')->insert(['id'=>1, 'user_type'=>'staff', 'is_admin'=>1, 'role_id'=>0, 'deleted'=>0]);
    $override = json_encode(['product_service_code'=>'01010101', 'unit_code'=>'H87', 'commercial_unit'=>'Pieza', 'tax_object_code'=>'02', 'fiscal_description'=>'Producto fiscal', 'pricing_mode'=>'tax_exclusive', 'taxes'=>[['tax_code'=>'002','tax_type'=>'transfer','factor_type'=>'Tasa','rate_or_quota'=>'0.160000']]]);
    foreach ([
        [1, 'before_tax', '10.000000', 'percentage'],
        [2, 'after_tax', '10.000000', 'percentage'],
    ] as [$proposalId, $discountType, $discount, $amountType]) {
        $db->table('proposals')->insert(['id'=>$proposalId, 'client_id'=>1, 'company_id'=>1, 'project_id'=>0, 'status'=>'sent', 'deleted'=>0, 'created_by'=>1, 'tax_id'=>1, 'tax_id2'=>2, 'discount_amount'=>$discount, 'discount_amount_type'=>$amountType, 'discount_type'=>$discountType]);
        $db->table('proposal_items')->insert(['proposal_id'=>$proposalId, 'item_id'=>1, 'title'=>'Producto fiscal', 'quantity'=>'2.000000', 'unit_type'=>'Pieza', 'rate'=>'100.000000', 'total'=>'200.000000', 'fiscal_override_json'=>$override, 'sort'=>0, 'deleted'=>0]);
    }
    $creator = new ProposalFiscalFixtureInvoiceCreator($db);
    $service = new ProposalAcceptanceService(new ProposalToInvoiceService($creator, $db), $db);
    $calculator = new ProposalTotalsService();
    $expected = [1=>$calculator->calculate('200','10','percentage','before_tax','16','3'), 2=>$calculator->calculate('200','10','percentage','after_tax','16','3')];
    foreach ([1, 2] as $proposalId) {
        $result = $service->acceptAndConvert($proposalId, 1);
        $invoice = $db->table('invoices')->where('id', $result['invoice_id'])->get()->getRow();
        $proposal = $db->table('proposals')->where('id', $proposalId)->get()->getRow();
        $assert($result['invoice_action']==='created' && $proposal->converted_sale_id==$invoice->id && $proposal->status==='accepted', "proposal {$proposalId} creates one linked sale");
        $assert($invoice->invoice_subtotal===$expected[$proposalId]['subtotal'] && $invoice->discount_total===$expected[$proposalId]['discount'] && $invoice->tax===$expected[$proposalId]['tax'] && $invoice->tax2===$expected[$proposalId]['tax2'] && $invoice->invoice_total===$expected[$proposalId]['grand_total'], "proposal {$proposalId} preserves subtotal, discount, two taxes and grand total");
    }
    $assert($creator->headers[0]['tax_id']===1 && $creator->headers[0]['tax_id2']===2 && $creator->rows[0][0]['fiscal_override_json']!==null, 'fiscal tax ids and item override reach the sale fixture');
    $repeat = $service->acceptAndConvert(1, 1);
    $assert($repeat['invoice_action']==='existing' && $db->table('invoices')->where('proposal_id',1)->countAllResults()===1, 'repeated conversion is idempotent');
    $db->table('proposals')->insert(['id'=>3, 'client_id'=>1, 'company_id'=>1, 'project_id'=>0, 'status'=>'sent', 'deleted'=>0, 'created_by'=>1, 'tax_id'=>1, 'tax_id2'=>2, 'discount_amount'=>'0', 'discount_amount_type'=>'fixed_amount', 'discount_type'=>'before_tax']);
    $db->table('proposal_items')->insert(['proposal_id'=>3, 'item_id'=>1, 'title'=>'Rollback', 'quantity'=>'1.000000', 'unit_type'=>'Pieza', 'rate'=>'100.000000', 'total'=>'100.000000', 'sort'=>0, 'deleted'=>0]);
    $creator->failAfterInsert = true;
    try { $service->acceptAndConvert(3, 1); throw new RuntimeException('rollback was not triggered'); } catch (RuntimeException $e) { $assert($e->getMessage()==='Synthetic failure after invoice insert.', 'synthetic post-insert failure reaches acceptance rollback'); }
    $failedProposal = $db->table('proposals')->where('id',3)->get()->getRow();
    $assert($failedProposal->status==='sent' && $failedProposal->converted_sale_id===null && $db->table('invoices')->where('proposal_id',3)->countAllResults()===0, 'failure rolls back invoice and proposal conversion state');
    echo "{$passed} passed, 0 failed. No source data or PAC accessed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, '[FAIL] ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL); $exit = 1;
} finally {
    if ($db) $db->close();
    if ($server && $owned) {
        if (!preg_match('/^ikontrol_test_p06_[a-f0-9]{12}$/D', $owned)) throw new RuntimeException('Unsafe cleanup target.');
        $server->query("DROP DATABASE `{$owned}`"); $server->close(); echo "Owned synthetic MySQL schema removed.\n";
    }
}
exit($exit);
