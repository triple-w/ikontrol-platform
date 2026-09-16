<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
require $root.'/tests/bootstrap.php';
helper(['general','date_time']);
$db = require $root.'/tests/Increment02/isolated_database.php';

use App\Services\AdministrativePaymentService;
use App\Services\ExpenseFinancialTotalService;
use App\Services\FinancialAccountBalanceService;
use App\Services\FinancialAccountMovementService;
use App\Services\FinancialAccountService;
use App\Services\FinancialTransferService;

$pass=$fail=0;
$ok=function(bool $condition,string $label)use(&$pass,&$fail){echo($condition?'[PASS] ':'[FAIL] ').$label.PHP_EOL;$condition?$pass++:$fail++;};
$throws=function(callable $call,string $contains=''):bool{try{$call();return false;}catch(Throwable $e){return $contains===''||str_contains($e->getMessage(),$contains);}};
$actor=(int)$db->table('users')->select('id')->get(1)->getRow()->id;
$invoice=(int)$db->table('invoices')->select('id')->get(1)->getRow()->id;
$client=(int)$db->table('invoices')->select('client_id')->where('id',$invoice)->get(1)->getRow()->client_id;
$method=(int)$db->table('payment_methods')->select('id')->where('deleted',0)->get(1)->getRow()->id;
$accounts=new FinancialAccountService($db);
$a=$accounts->save(['name'=>'Test A','type'=>'bank','opening_balance'=>'1000','is_active'=>1],0,$actor);
$b=$accounts->save(['name'=>'Test B','type'=>'cash','opening_balance'=>'500','is_active'=>1],0,$actor);
$row=$db->table('financial_accounts')->where('id',$a)->get(1)->getRow();
$ok($row->currency==='MXN'&&(string)$row->opening_balance==='1000.000000','crear cuenta MXN con saldo inicial DECIMAL(18,6)');

$paymentService=new AdministrativePaymentService($db);
$payment=$paymentService->save(['invoice_id'=>null,'client_id'=>$client,'payment_date'=>'2026-08-26','payment_method_id'=>$method,'destination_financial_account_id'=>$a,'amount'=>'500','note'=>'Ingreso test','created_by'=>$actor,'created_at'=>get_current_utc_time()]);
$movement=$db->table('financial_account_movements')->where(['reference_type'=>'invoice_payment','reference_id'=>$payment,'movement_role'=>'original'])->get()->getResult();
$ok(count($movement)===1&&$movement[0]->direction==='in'&&(string)$movement[0]->amount==='500.000000','pago con cuenta crea exactamente un movimiento IN');
$before=(int)$db->table('invoice_payments')->countAllResults();
$ok($throws(fn()=>$paymentService->save(['invoice_id'=>null,'client_id'=>$client,'payment_date'=>'2026-08-26','payment_method_id'=>$method,'amount'=>'1','created_by'=>$actor]),'cuenta financiera')&&(int)$db->table('invoice_payments')->countAllResults()===$before,'pago sin cuenta falla sin persistir pago');
$nextPayment=(int)$db->table('invoice_payments')->selectMax('id')->get()->getRow()->id+1;
$db->query('ALTER TABLE '.$db->prefixTable('invoice_payments').' AUTO_INCREMENT='.$nextPayment);
$movementService=new FinancialAccountMovementService($db);$movementService->sync('invoice_payment',$nextPayment,$b,'in','1','2026-08-26',$actor,'fixture de colisión');
$collision=$db->table('financial_account_movements')->where(['reference_type'=>'invoice_payment','reference_id'=>$nextPayment])->get(1)->getRow();$movementService->reverse((int)$collision->id,$actor,'fixture reversado');
$before=(int)$db->table('invoice_payments')->countAllResults();
$ok($throws(fn()=>$paymentService->save(['invoice_id'=>null,'client_id'=>$client,'payment_date'=>'2026-08-26','payment_method_id'=>$method,'destination_financial_account_id'=>$b,'amount'=>'1','created_by'=>$actor]),'reversado')&&(int)$db->table('invoice_payments')->countAllResults()===$before,'fallo al crear movimiento revierte el pago completo');
$ok($throws(fn()=>$accounts->save(['name'=>'Test A','type'=>'bank','opening_balance'=>'999'], $a,$actor),'saldo inicial'),'saldo inicial bloqueado después del primer movimiento');

$expenseTotal=(new ExpenseFinancialTotalService($db))->total('1000',0,0);
$tax16=$db->table('taxes')->select('id')->where('percentage',16)->get(1)->getRow();
$ok(!$tax16||(new ExpenseFinancialTotalService($db))->total('1000',(int)$tax16->id,0)==='1160.000000','egreso usa subtotal + impuestos como dinero realmente desembolsado');
$db->transBegin();
$db->table('expenses')->insert(['expense_date'=>'2026-08-26','category_id'=>2,'description'=>'','amount'=>'1000','financial_total'=>$expenseTotal,'source_financial_account_id'=>$a,'files'=>'a:0:{}','title'=>'Egreso test','project_id'=>0,'user_id'=>0,'tax_id'=>0,'tax_id2'=>0,'client_id'=>0,'recurring'=>0,'recurring_expense_id'=>0,'repeat_every'=>0,'no_of_cycles'=>0,'no_of_cycles_completed'=>0,'deleted'=>0,'created_by'=>$actor,'status'=>'active']);
$expense=(int)$db->insertID();(new FinancialAccountMovementService($db))->sync('expense',$expense,$a,'out',$expenseTotal,'2026-08-26',$actor,'Egreso test');$db->transCommit();
$em=$db->table('financial_account_movements')->where(['reference_type'=>'expense','reference_id'=>$expense])->get(1)->getRow();
$ok($em&&$em->direction==='out'&&(string)$em->amount==='1000.000000','egreso con cuenta crea OUT por el total desembolsado');
$ok($throws(fn()=>(new FinancialAccountMovementService($db))->sync('expense',999999,null,'out','1','2026-08-26',$actor),'cuenta financiera'),'egreso/movimiento sin cuenta falla');
$balance=(new FinancialAccountBalanceService($db))->balance($a);
$ok($balance['current']==='500.000000','saldo derivado: 1000 + 500 - 1000 = 500');

$transfer=(new FinancialTransferService($db))->create($a,$b,'800','2026-08-26','Transfer test',$actor);
$ba=(new FinancialAccountBalanceService($db))->balance($a);$bb=(new FinancialAccountBalanceService($db))->balance($b);
$ok($ba['current']==='-300.000000'&&$bb['current']==='1300.000000','transferencia atómica permite saldo negativo');
(new FinancialTransferService($db))->cancel($transfer,$actor,'Corrección test');
$ok($db->table('financial_account_movements')->whereIn('reference_type',['transfer_out','transfer_in','movement_reversal'])->where('reference_id',$transfer)->countAllResults()>=2,'transferencia conserva originales y crea reversas');
$transferRows=$db->query("SELECT * FROM {$db->prefixTable('financial_account_movements')} WHERE (reference_type IN ('transfer_out','transfer_in') AND reference_id=?) OR reversal_of_movement_id IN (SELECT id FROM {$db->prefixTable('financial_account_movements')} WHERE reference_type IN ('transfer_out','transfer_in') AND reference_id=?)",[$transfer,$transfer])->getResult();
$ok(count($transferRows)===4,'reversa de transferencia produce cuatro movimientos visibles');

$paymentService->cancel($payment,$actor,'Reversa ingreso test');
$pm=$db->table('financial_account_movements')->where('reversal_of_movement_id',(int)$movement[0]->id)->get()->getResult();
$ok(count($pm)===1&&$pm[0]->direction==='out','reversa de ingreso crea OUT sin eliminar original');
$ok($throws(fn()=>$paymentService->cancel($payment,$actor,'Duplicada')),'cancelación/reversa de pago es idempotente');
$movements=new FinancialAccountMovementService($db);$movements->sync('idempotency_test',77,$b,'in','10','2026-08-26',$actor,'uno');$movements->sync('idempotency_test',77,$b,'in','10','2026-08-26',$actor,'dos');
$ok($db->table('financial_account_movements')->where(['reference_type'=>'idempotency_test','reference_id'=>77,'movement_role'=>'original'])->countAllResults()===1,'mismo evento no duplica movimiento');

$originalExpense=$db->table('financial_account_movements')->where(['reference_type'=>'expense','reference_id'=>$expense])->get(1)->getRow();$movements->reverse((int)$originalExpense->id,$actor,'Reversa egreso test');
$er=$db->table('financial_account_movements')->where('reversal_of_movement_id',(int)$originalExpense->id)->get(1)->getRow();
$ok($er&&$er->direction==='in'&&(string)$er->amount==='1000.000000','reversa de egreso crea IN sin eliminar original');

echo "TOTAL PASS=$pass FAIL=$fail".PHP_EOL;exit($fail?1:0);
