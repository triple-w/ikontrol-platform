<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
require_once APPPATH . 'ThirdParty/PHP-Hooks/php-hooks.php';
helper(['plugin', 'general', 'date_time', 'currency']);
$sessionConfig = config('Session');
$sessionConfig->driver = CodeIgniter\Session\Handlers\FileHandler::class;
$sessionConfig->savePath = WRITEPATH . 'session';
service('session');
$riseConfig = config('Rise');
$riseConfig->app_settings_array['timezone'] = 'America/Mexico_City';
$riseConfig->app_settings_array['default_due_date_after_billing_date'] = '15';

$root = dirname(__DIR__, 2); $failures = []; $assertions = 0;
$assert = static function (bool $ok, string $message) use (&$failures, &$assertions): void {
    $assertions++; echo ($ok ? 'PASS' : 'FAIL') . " $message\n"; if (!$ok) $failures[] = $message;
};

$pricing = new App\Services\EstimateItemPricingService();
$assert($pricing->suggestedRate('100', '30') === '130.000000', 'Costo 100 + 30% produce 130.');
$assert($pricing->suggestedRate('250', '20') === '300.000000', 'Costo 250 + 20% produce 300.');
$assert($pricing->suggestedRate('100', '0') === '100.000000', 'Utilidad cero conserva el costo.');
$assert($pricing->suggestedRate(null, '20') === null, 'Sin costo no se calcula precio.');
foreach (['-1', 'NaN', 'INF', '1e3'] as $invalid) {
    try { $pricing->optionalNonNegativeDecimal($invalid, 'Costo'); $assert(false, "Se rechaza $invalid."); }
    catch (InvalidArgumentException) { $assert(true, "Se rechaza $invalid."); }
}
$assert($pricing->requiredNonNegativeDecimal('0', 'Precio') === '0', 'Precio cero es valido.');

$read = static fn(string $path): string => (string) file_get_contents($root . $path);
$publicController = $read('/app/Controllers/Estimate.php');
$publicView = $read('/app/Views/estimates/estimate_public_preview.php');
$estimateController = $read('/app/Controllers/Estimates.php');
$itemModal = $read('/app/Views/estimates/item_modal_form.php');
$proposalController = $read('/app/Controllers/Proposals.php');
$acceptance = $read('/app/Services/ProposalAcceptanceService.php');
$converter = $read('/app/Services/ProposalToInvoiceService.php');
$policy = $read('/app/Services/ProposalEditabilityPolicy.php');
$invoiceController = $read('/app/Controllers/Invoices.php');

$assert(str_contains($publicController, 'public_url') && str_contains($publicController, 'get_one($estimate_id)'), 'Endpoint publico devuelve URL y recarga estado.');
$assert(str_contains($publicView, 'estimate_accepted_and_converted') && str_contains($publicView, '"converted"'), 'Vista publica trata converted como final.');
$assert(!str_contains($publicView, 'converted_sale_id'), 'Vista publica no expone ID interno de venta.');
$assert(str_contains($estimateController, 'requiredNonNegativeDecimal') && str_contains($estimateController, '"total" => (float) $rate * (float) $quantity'), 'Servidor valida rate y recalcula total.');
$assert(str_contains($itemModal, 'estimate_item_cost') && str_contains($itemModal, 'profit_over_cost_percentage') && str_contains($itemModal, 'sale_price'), 'Modal usa campos requeridos.');
$assert(str_contains($itemModal, '1 + profit / 100') && str_contains($itemModal, 'on("change input"'), 'Frontend implementa utilidad sobre costo.');
$assert(!str_contains($publicView, 'estimate_item_cost') && !str_contains($publicView, 'profit_percentage'), 'Vista publica no expone costo/utilidad.');
$assert(str_contains($acceptance, 'FOR UPDATE') && str_contains($acceptance, 'converted_sale_id'), 'Aceptacion bloquea fila y valida backlink.');
$assert(str_contains($acceptance, "'invoice_action' => 'existing'") && str_contains($acceptance, "'invoice_action' => 'created'"), 'Aceptacion es idempotente.');
$assert(str_contains($converter, "'proposal_id' => (int) \$proposal->id") && str_contains($converter, "'estimate_id' => 0"), 'Venta conserva origen Proposal.');
$assert(str_contains($converter, "'total' => \$quantity * \$rate") && str_contains($converter, 'quantity <= 0'), 'Partidas se validan y recalculan.');
$assert(str_contains($policy, "status === 'accepted'") && str_contains($policy, 'converted_sale_id'), 'Policy cierra Proposal.');
$assert((new App\Services\ProposalEditabilityPolicy())->isEditable(0), 'Policy permite la creacion de una Proposal nueva con ID 0.');
$assert(!str_contains($proposalController, 'proposal.accept_and_convert') || str_contains($proposalController, 'ProposalAcceptanceService'), 'Modal no exige el permiso dedicado de conversion.');
$assert(substr_count($proposalController, '_is_proposal_editable') >= 8, 'Rutas comerciales aplican policy central.');
$assert(str_contains($invoiceController, 'Proposal conversions are atomic'), 'Ruta manual Proposal a venta esta bloqueada.');
$assert(!preg_match('/Fiscal|CFDI|stamp|timbre/i', $converter), 'Conversor no invoca flujo fiscal/timbres.');

require $root . '/tests/Increment02/isolated_database.php';
require_once $root . '/app/Database/Migrations/2026-08-06-180000_AddEstimateItemCostAndProfit.php';
require_once $root . '/app/Database/Migrations/2026-08-06-180100_CreateProposalSaleConversion.php';
$databaseConfig = config('Database');
$isolatedDb = Config\Database::connect($databaseConfig->default, false);
$isolatedForge = Config\Database::forge($isolatedDb);
$actualDatabase = (string) $isolatedDb->query('SELECT DATABASE() AS database_name')->getRow()->database_name;
$assert(str_contains($actualDatabase, '_increment02_'), 'La conexion fisica apunta a la base temporal.');
(new App\Database\Migrations\AddEstimateItemCostAndProfit($isolatedForge))->up();
(new App\Database\Migrations\CreateProposalSaleConversion($isolatedForge))->up();
$isolatedDb->close();
$isolatedDb = Config\Database::connect($databaseConfig->default, false);
$assert($isolatedDb->fieldExists('cost', 'estimate_items'), 'Migracion temporal crea cost.');
$assert($isolatedDb->fieldExists('profit_percentage', 'estimate_items'), 'Migracion temporal crea profit_percentage.');
$assert($isolatedDb->fieldExists('converted_sale_id', 'proposals') && $isolatedDb->fieldExists('accepted_at', 'proposals'), 'Migracion temporal crea cierre Proposal.');
$assert($isolatedDb->fieldExists('proposal_id', 'invoices'), 'Migracion temporal crea backlink Invoice.');
$assert(isset($isolatedDb->getIndexData('proposals')['uq_proposals_converted_sale']), 'converted_sale_id es UNIQUE.');
$assert(isset($isolatedDb->getIndexData('invoices')['uq_invoices_proposal']), 'proposal_id es UNIQUE.');

$actor = $isolatedDb->table('users')->where(['user_type' => 'staff', 'is_admin' => 1, 'deleted' => 0])->get(1)->getRow();
$client = $isolatedDb->table('clients')->where(['is_lead' => 0, 'deleted' => 0])->get(1)->getRow();
$company = $isolatedDb->table('company')->where('deleted', 0)->get(1)->getRow();
$assert((bool) ($actor && $client && $company), 'Fixtures aisladas tienen actor, cliente y empresa validos.');

$proposalHeader = [
    'client_id' => (int) $client->id, 'proposal_date' => date('Y-m-d'),
    'valid_until' => date('Y-m-d', strtotime('+7 days')), 'note' => 'Prueba aislada',
    'status' => 'sent', 'tax_id' => 0, 'tax_id2' => 0, 'discount_amount' => 0,
    'discount_amount_type' => 'percentage', 'discount_type' => 'before_tax',
    'company_id' => (int) $company->id, 'project_id' => 0, 'created_by' => (int) $actor->id,
    'public_key' => 'T' . bin2hex(random_bytes(4)), 'accepted_by' => 0, 'deleted' => 0,
];
$isolatedDb->table('proposals')->insert($proposalHeader);
$proposalId = (int) $isolatedDb->insertID();
$isolatedDb->table('proposal_items')->insert([
    'proposal_id' => $proposalId, 'title' => 'Partida de prueba', 'description' => 'Descripcion',
    'quantity' => 2, 'unit_type' => 'pieza', 'rate' => 125, 'total' => 250,
    'item_id' => 0, 'sort' => 1, 'deleted' => 0,
]);
$documentPolicy = new App\Services\ProposalEditabilityPolicy($isolatedDb);
$assert($documentPolicy->isEditable($proposalId), 'Proposal sent sin conversion permanece editable segun la politica vigente.');
$result = (new App\Services\ProposalAcceptanceService(null, $isolatedDb))->acceptAndConvert($proposalId, (int) $actor->id);
$savedProposal = $isolatedDb->table('proposals')->where('id', $proposalId)->get()->getRow();
$savedInvoice = $isolatedDb->table('invoices')->where('id', $result['invoice_id'])->get()->getRow();
$assert($result['invoice_action'] === 'created' && $savedProposal->status === 'accepted', 'Aceptacion interna crea venta y cierra Proposal.');
$assert((int) $savedProposal->converted_sale_id === (int) $savedInvoice->id && (int) $savedInvoice->proposal_id === $proposalId, 'Backlinks Proposal/Invoice son coherentes.');
$assert((int) $savedInvoice->estimate_id === 0, 'Venta de Proposal conserva estimate_id=0.');
$assert(!$documentPolicy->isEditable($proposalId), 'Proposal aceptada y convertida permanece bloqueada.');
$second = (new App\Services\ProposalAcceptanceService(null, $isolatedDb))->acceptAndConvert($proposalId, (int) $actor->id);
$assert($second['invoice_action'] === 'existing' && (int) $second['invoice_id'] === (int) $savedInvoice->id, 'Segunda aceptacion devuelve la misma venta.');
$assert($isolatedDb->table('invoices')->where('proposal_id', $proposalId)->countAllResults() === 1, 'Idempotencia impide una segunda venta.');

$proposalHeader['public_key'] = 'T' . bin2hex(random_bytes(4));
$isolatedDb->table('proposals')->insert($proposalHeader);
$invalidProposalId = (int) $isolatedDb->insertID();
$isolatedDb->table('proposal_items')->insert([
    'proposal_id' => $invalidProposalId, 'title' => 'Partida invalida', 'description' => '',
    'quantity' => 0, 'unit_type' => 'pieza', 'rate' => 10, 'total' => 0,
    'item_id' => 0, 'sort' => 1, 'deleted' => 0,
]);
try {
    (new App\Services\ProposalAcceptanceService(null, $isolatedDb))->acceptAndConvert($invalidProposalId, (int) $actor->id);
    $assert(false, 'Partida invalida revierte conversion.');
} catch (Throwable) {
    $invalidProposal = $isolatedDb->table('proposals')->where('id', $invalidProposalId)->get()->getRow();
    $assert($invalidProposal->status === 'sent' && empty($invalidProposal->converted_sale_id), 'Partida invalida conserva Proposal sin aceptar.');
    $assert($isolatedDb->table('invoices')->where('proposal_id', $invalidProposalId)->countAllResults() === 0, 'Rollback no deja venta parcial.');
}

echo "ASSERTIONS=$assertions FAILURES=" . count($failures) . "\n";
exit($failures ? 1 : 0);
