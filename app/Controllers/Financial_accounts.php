<?php
namespace App\Controllers;

use App\Services\FinancialAccountBalanceService;
use App\Services\FinancialAccountService;
use App\Services\FinancialMoney;
use App\Services\FinancialTransferService;
use Throwable;

class Financial_accounts extends Security_Controller
{
    private const TYPES = ['cash'=>'Caja / efectivo','bank'=>'Banco','card'=>'Tarjeta / terminal','wallet'=>'Wallet / plataforma','other'=>'Otra'];

    public function index(){$db=db_connect();$accounts=$db->table('financial_accounts')->where(['deleted'=>0,'is_active'=>1,'currency'=>'MXN'])->orderBy('name')->get()->getResult();$transfers=$db->table('financial_account_transfers t')->select('t.*,s.name source_name,d.name destination_name')->join('financial_accounts s','s.id=t.source_account_id')->join('financial_accounts d','d.id=t.destination_account_id')->where('t.deleted',0)->orderBy('t.id','DESC')->limit(25)->get()->getResult();return $this->template->rander('financial_accounts/index',['account_types'=>self::TYPES,'accounts'=>$accounts,'transfers'=>$transfers]);}
    public function list_data(){
        $rows=model('App\\Models\\Financial_accounts_model')->get_all_where(['deleted'=>0],0,0,'name')->getResult();$out=[];
        foreach($rows as $a){$b=(new FinancialAccountBalanceService(db_connect()))->balance((int)$a->id);$actions=modal_anchor(get_uri('financial_accounts/modal_form'),'<i data-feather="edit" class="icon-16"></i>',['class'=>'btn btn-xs btn-default','data-post-id'=>$a->id,'title'=>'Editar cuenta']).' <a class="btn btn-xs btn-default" href="'.get_uri('financial_accounts/movements/'.$a->id).'">Movimientos</a>';$out[]=[esc($a->name),esc(self::TYPES[$a->type]??self::TYPES['other']),esc($a->currency),to_currency($b['opening_balance']),to_currency($b['in']),to_currency($b['out']),to_currency($b['current']),($a->is_active?'Activa':'Inactiva').' '.$actions];}
        return $this->response->setJSON(['data'=>$out]);
    }
    public function modal_form(){
        $id=(int)($this->request->getPost('id')?:$this->request->getGet('id'));$account=$id?db_connect()->table('financial_accounts')->where(['id'=>$id,'deleted'=>0])->get(1)->getRow():null;
        return $this->template->view('financial_accounts/modal_form',['account'=>$account,'account_types'=>self::TYPES]);
    }
    public function movements(int $id){
        $db=db_connect();$account=$db->table('financial_accounts')->where(['id'=>$id,'deleted'=>0])->get(1)->getRow();if(!$account)throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        $rows=$db->table('financial_account_movements')->where(['financial_account_id'=>$id,'is_active'=>1])->orderBy('movement_date','ASC')->orderBy('id','ASC')->get()->getResult();$balance=(new FinancialAccountBalanceService($db))->balance($id);$running=$balance['opening_balance'];
        foreach($rows as$row){$running=$row->direction==='in'?FinancialMoney::add($running,(string)$row->amount):FinancialMoney::subtract($running,(string)$row->amount);$row->running_balance=$running;}
        return $this->template->rander('financial_accounts/movements',['account'=>$account,'balance'=>$balance,'movements'=>$rows]);
    }
    public function save(){
        try{$this->validate_submitted_data(['name'=>'required','type'=>'required']);$id=(int)$this->request->getPost('id');$saved=(new FinancialAccountService(db_connect()))->save($this->request->getPost(),$id,(int)$this->login_user->id);return $this->response->setJSON(['success'=>true,'id'=>$saved,'message'=>app_lang('record_saved')]);}
        catch(Throwable$e){return $this->response->setStatusCode(422)->setJSON(['success'=>false,'message'=>$e->getMessage()]);}
    }
    public function deactivate(){$id=(int)$this->request->getPost('id');$ok=model('App\\Models\\Financial_accounts_model')->ci_save(['is_active'=>0,'updated_at'=>get_current_utc_time()],$id);return $this->response->setJSON(['success'=>(bool)$ok]);}
    public function transfer(){
        try{$this->validate_submitted_data(['source_account_id'=>'required|numeric','destination_account_id'=>'required|numeric','amount'=>'required']);$id=(new FinancialTransferService(db_connect()))->create((int)$this->request->getPost('source_account_id'),(int)$this->request->getPost('destination_account_id'),$this->request->getPost('amount'),$this->request->getPost('transfer_date')?:get_today_date(),$this->request->getPost('note'),(int)$this->login_user->id);return $this->response->setJSON(['success'=>true,'id'=>$id]);}
        catch(Throwable$e){return $this->response->setStatusCode(422)->setJSON(['success'=>false,'message'=>$e->getMessage()]);}
    }
    public function cancel_transfer(){
        try{$this->validate_submitted_data(['id'=>'required|numeric','reason'=>'required']);(new FinancialTransferService(db_connect()))->cancel((int)$this->request->getPost('id'),(int)$this->login_user->id,(string)$this->request->getPost('reason'));return $this->response->setJSON(['success'=>true]);}
        catch(Throwable$e){return $this->response->setStatusCode(422)->setJSON(['success'=>false,'message'=>$e->getMessage()]);}
    }
}
