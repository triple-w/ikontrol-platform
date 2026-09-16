<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require_once APPPATH . 'ThirdParty/PHP-Hooks/php-hooks.php';
helper(['plugin', 'general', 'date_time', 'currency']);
$sessionConfig=config('Session');$sessionConfig->driver=CodeIgniter\Session\Handlers\FileHandler::class;$sessionConfig->savePath=WRITEPATH.'session';service('session');
$rise=config('Rise');$rise->app_settings_array['timezone']='America/Mexico_City';$rise->app_settings_array['default_due_date_after_billing_date']='15';

$fail=[];$pass=0;$ok=static function(bool $condition,string $message)use(&$fail,&$pass):void{echo($condition?'PASS ':'FAIL ').$message.PHP_EOL;if($condition)$pass++;else$fail[]=$message;};

// Regression 1: fiscal persistence/reservation must precede commercial closure.
$flow=(string)file_get_contents(APPPATH.'Services/Fiscal/FiscalInvoiceFlowService.php');
$newDraftBranch=strpos($flow,'else{$data=$this->workflow->formData');
$savePosition=strpos($flow,'$saved=$this->workflow->save($input,$userId);',$newDraftBranch?:0);
$closePosition=strpos($flow,"(new SaleLifecycleService(\$this->db))->close",$newDraftBranch?:0);
$ok($newDraftBranch!==false&&$savePosition!==false&&$closePosition!==false&&$savePosition<$closePosition,'Fiscal save/reservation occurs before commercial closure.');
$proposalController=(string)file_get_contents(APPPATH.'Controllers/Proposals.php');
$offerController=(string)file_get_contents(APPPATH.'Controllers/Offer.php');
$ok(substr_count($proposalController,'acceptAndConvert')>=2&&str_contains($offerController,'acceptAndConvert'),'Staff, client and public acceptance routes share the atomic conversion service.');

$db=require dirname(__DIR__).'/Increment02/isolated_database.php';
$actor=$db->table('users')->where(['user_type'=>'staff','is_admin'=>1,'deleted'=>0])->get(1)->getRow();
$client=$db->table('clients')->where(['is_lead'=>0,'deleted'=>0])->get(1)->getRow();
$company=$db->table('company')->where('deleted',0)->get(1)->getRow();
$ok((bool)($actor&&$client&&$company),'Isolated fixtures provide an internal owner, client and company.');

$makeProposal=static function(string $status,string $key)use($db,$actor,$client,$company):int{
    $db->table('proposals')->insert([
        'client_id'=>(int)$client->id,'proposal_date'=>date('Y-m-d'),'valid_until'=>date('Y-m-d',strtotime('+7 days')),
        'note'=>'Commercial flow regression','status'=>$status,'tax_id'=>0,'tax_id2'=>0,'discount_amount'=>0,
        'discount_amount_type'=>'percentage','discount_type'=>'before_tax','company_id'=>(int)$company->id,'project_id'=>0,
        'created_by'=>(int)$actor->id,'public_key'=>$key,'accepted_by'=>0,'deleted'=>0,
    ]);
    return(int)$db->insertID();
};
$addItem=static function(int $proposalId,string $title,int $sort,int $deleted=0)use($db):void{
    $db->table('proposal_items')->insert([
        'proposal_id'=>$proposalId,'title'=>$title,'description'=>'Visible description','quantity'=>'2.000000',
        'unit_type'=>'pieza','rate'=>'125.000000','total'=>'250.000000','item_id'=>0,'sort'=>$sort,'deleted'=>$deleted,
        'cost'=>'80.000000','profit_percentage'=>'56.250000',
    ]);
};

$draftId=$makeProposal('draft','DRFTKEY001');$addItem($draftId,'Draft line',1);
$sentId=$makeProposal('sent','SENTKEY001');$addItem($sentId,'Sent line',1);
$ok($db->table('invoices')->whereIn('proposal_id',[$draftId,$sentId])->countAllResults()===0,'Draft and sent proposals do not create sales without acceptance.');

$proposalId=$makeProposal('sent','PUBLIC0001');
$addItem($proposalId,'Active one',1);$addItem($proposalId,'Deleted line',2,1);$addItem($proposalId,'Active two',3);
$service=new App\Services\ProposalAcceptanceService(null,$db);
$wrongKeyRejected=false;try{$service->acceptAndConvert($proposalId,0,'WRONGKEY00');}catch(Throwable){$wrongKeyRejected=true;}
$ok($wrongKeyRejected&&$db->table('invoices')->where('proposal_id',$proposalId)->countAllResults()===0,'Public acceptance rejects an invalid key without creating a sale.');
$result=$service->acceptAndConvert($proposalId,0,'PUBLIC0001');
$invoiceId=(int)$result['invoice_id'];
$proposal=$db->table('proposals')->where('id',$proposalId)->get(1)->getRow();
$invoice=$db->table('invoices')->where('id',$invoiceId)->get(1)->getRow();
$lines=$db->table('invoice_items')->where(['invoice_id'=>$invoiceId,'deleted'=>0])->orderBy('sort')->get()->getResult();
$ok($result['invoice_action']==='created'&&$proposal->status==='accepted','Accepted proposal creates its administrative sale atomically.');
$ok((int)$proposal->converted_sale_id===$invoiceId&&(int)$invoice->proposal_id===$proposalId,'Proposal and sale retain both backlinks.');
$ok(count($lines)===2&&$lines[0]->title==='Active one'&&$lines[1]->title==='Active two','All active lines and no soft-deleted line are copied.');
$ok((int)$invoice->client_id===(int)$client->id&&(int)$invoice->company_id===(int)$company->id,'Client and company are copied.');
$ok(!property_exists($lines[0],'supplier_id')||empty($lines[0]->supplier_id),'Supplier selection is not exposed on the sale line.');
$again=$service->acceptAndConvert($proposalId,0,'PUBLIC0001');
$ok($again['invoice_action']==='existing'&&$db->table('invoices')->where(['proposal_id'=>$proposalId,'deleted'=>0])->countAllResults()===1,'Repeated acceptance remains idempotent.');

$invalidId=$makeProposal('sent','PUBLIC0002');$db->table('proposal_items')->insert([
    'proposal_id'=>$invalidId,'title'=>'Invalid line','description'=>'','quantity'=>0,'unit_type'=>'pieza',
    'rate'=>10,'total'=>0,'item_id'=>0,'sort'=>1,'deleted'=>0,
]);
$rolledBack=false;try{$service->acceptAndConvert($invalidId,0,'PUBLIC0002');}catch(Throwable){$rolledBack=true;}
$invalid=$db->table('proposals')->where('id',$invalidId)->get(1)->getRow();
$ok($rolledBack&&$invalid->status==='sent'&&empty($invalid->converted_sale_id)&&$db->table('invoices')->where('proposal_id',$invalidId)->countAllResults()===0,'Item creation failure rolls back acceptance and sale header.');

echo "{$pass} passed, ".count($fail)." failed.".PHP_EOL;
exit($fail?1:0);
