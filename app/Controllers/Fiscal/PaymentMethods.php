<?php
declare(strict_types=1);
namespace App\Controllers\Fiscal;

use App\Controllers\Security_Controller;
use App\Services\Fiscal\FiscalPaymentMethodReadinessService;
use RuntimeException;
use Throwable;

final class PaymentMethods extends Security_Controller
{
    public function index(){ $this->guard(false);return$this->template->rander('fiscal/payment_methods/index',['assessment'=>(new FiscalPaymentMethodReadinessService())->inspect(),'can_manage'=>$this->allowed(true)]); }
    public function form(){
        $this->guard(true);$id=(int)$this->request->getPost('payment_method_id');$db=db_connect();
        $method=$db->table('payment_methods')->where(['id'=>$id,'deleted'=>0,'available_on_invoice'=>1])->get(1)->getRow();if(!$method)throw new RuntimeException('Método administrativo no elegible.');
        $mappings=$db->table('fiscal_payment_method_mappings')->where('payment_method_id',$id)->orderBy('id')->get()->getResult();
        $forms=[''=>'-'];foreach($db->table('sat_payment_forms')->where(['is_active'=>1])->where('code !=','99')->orderBy('code')->get()->getResult()as$form)$forms[$form->code]=$form->code.' · '.$form->name;
        return$this->template->view('fiscal/payment_methods/form',['method'=>$method,'forms'=>$forms,'mapping'=>count($mappings)===1?$mappings[0]:null,'duplicate'=>count($mappings)>1]);
    }
    public function save():void{
        $this->guard(true);$db=db_connect();
        try{$id=(int)$this->request->getPost('payment_method_id');$code=trim((string)$this->request->getPost('sat_payment_form_code'));
            $method=$db->table('payment_methods')->where(['id'=>$id,'deleted'=>0,'available_on_invoice'=>1])->get(1)->getRow();if(!$method)throw new RuntimeException('Método administrativo no elegible.');
            $mappings=$db->table('fiscal_payment_method_mappings')->where('payment_method_id',$id)->orderBy('id')->get()->getResult();if(count($mappings)>1)throw new RuntimeException('Existen mappings duplicados; se requiere revisión dirigida antes de guardar.');
            if($code==='99')throw new RuntimeException('La forma SAT 99 - Por definir no puede mapearse a un pago administrativo.');
            if($code!==''&&$db->table('sat_payment_forms')->where(['code'=>$code,'is_active'=>1])->countAllResults()!==1)throw new RuntimeException('Selecciona una forma SAT activa.');
            $now=get_current_utc_time();$db->transBegin();$existing=$mappings[0]??null;
            if($existing)$db->table('fiscal_payment_method_mappings')->where('id',$existing->id)->update(['sat_payment_form_code'=>$code?:$existing->sat_payment_form_code,'is_active'=>$code!==''?1:0,'updated_at'=>$now]);
            elseif($code!=='')$db->table('fiscal_payment_method_mappings')->insert(['payment_method_id'=>$id,'sat_payment_form_code'=>$code,'is_active'=>1,'created_by'=>$this->login_user->id,'created_at'=>$now,'updated_at'=>$now]);
            if(!$db->transStatus())throw new RuntimeException('No fue posible guardar el mapping fiscal.');$db->transCommit();echo json_encode(['success'=>true,'message'=>'Mapping fiscal guardado.']);
        }catch(Throwable$e){if($db->transStatus()!==null)$db->transRollback();echo json_encode(['success'=>false,'message'=>$e instanceof RuntimeException?$e->getMessage():'No fue posible guardar el mapping fiscal.']);}
    }
    private function allowed(bool$manage):bool{if($this->login_user->is_admin)return true;$p=is_array($this->login_user->permissions)?$this->login_user->permissions:(@unserialize((string)$this->login_user->permissions)?:[]);return(bool)get_array_value($p,$manage?'can_manage_fiscal_tax_settings':'can_view_fiscal_tax_settings');}
    private function guard(bool$manage):void{if(!$this->allowed($manage))app_redirect('forbidden');}
}
