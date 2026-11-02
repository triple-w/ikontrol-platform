<?php
declare(strict_types=1);
namespace App\Services\Fiscal;

/** Validates each eligible administrative payment method against one active SAT form. */
final class FiscalPaymentMethodReadinessService
{
    public function __construct(private mixed $db = null){$this->db??=db_connect();}

    public function inspect():array
    {
        $methods=$this->db->table('payment_methods')->select('id,title')->where(['deleted'=>0,'available_on_invoice'=>1])->orderBy('id')->get()->getResultArray();
        $rows=[];$ready=0;
        foreach($methods as$method){$row=$this->forMethod((int)$method['id']);$row['title']=(string)$method['title'];$rows[]=$row;if($row['ready'])$ready++;}
        $configured=count($rows);$issues=array_values(array_filter($rows,static fn(array$r):bool=>!$r['ready']));
        return['configured'=>$configured,'available'=>$configured,'mapped'=>$ready,'unmapped'=>$configured-$ready,'coverage_complete'=>$ready===$configured,'ready'=>$ready===$configured,'global_blocking'=>false,'methods'=>$rows,'issues'=>$issues];
    }

    public function forMethod(int$paymentMethodId):array
    {
        $method=$this->db->table('payment_methods')->select('id,title,deleted,available_on_invoice')->where('id',$paymentMethodId)->get(1)->getRowArray();
        if(!$method||(int)$method['deleted']!==0||(int)$method['available_on_invoice']!==1)return$this->result($paymentMethodId,false,'METHOD_NOT_ELIGIBLE',null,'El método administrativo no está habilitado para facturas.');
        $mappings=$this->db->table('fiscal_payment_method_mappings')->where(['payment_method_id'=>$paymentMethodId,'is_active'=>1])->orderBy('id')->get()->getResultArray();
        if($mappings===[])return$this->result($paymentMethodId,false,'MAPPING_MISSING',null,'El método administrativo “'.(string)$method['title'].'” no tiene forma de pago SAT activa.');
        if(count($mappings)!==1)return$this->result($paymentMethodId,false,'MAPPING_DUPLICATE',null,'El método administrativo “'.(string)$method['title'].'” tiene más de un mapping SAT activo.');
        $code=trim((string)$mappings[0]['sat_payment_form_code']);
        $sat=$this->db->table('sat_payment_forms')->select('code,name,is_active')->where('code',$code)->get()->getRowArray();
        if(!$sat||!(int)$sat['is_active'])return$this->result($paymentMethodId,false,'SAT_FORM_INACTIVE',$code,'La forma SAT '.$code.' del método “'.(string)$method['title'].'” no existe o está inactiva.');
        if($code==='99')return$this->result($paymentMethodId,false,'FORM_INCOMPATIBLE',$code,'La forma SAT 99 - Por definir no puede mapearse a un pago administrativo efectivamente recibido.');
        return$this->result($paymentMethodId,true,'READY',$code,'Mapping fiscal listo.',$sat['name']??null);
    }

    private function result(int$id,bool$ready,string$status,?string$code,string$message,?string$name=null):array
    {
        return['payment_method_id'=>$id,'ready'=>$ready,'status'=>$status,'sat_payment_form_code'=>$code,'sat_payment_form_name'=>$name,'message'=>$message];
    }
}
