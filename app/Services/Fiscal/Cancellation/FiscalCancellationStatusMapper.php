<?php
declare(strict_types=1);
namespace App\Services\Fiscal\Cancellation;

final class FiscalCancellationStatusMapper
{
    public function map(string$operation,array$payload,int$httpStatus=200):array
    {
        $flat=strtoupper(implode(' ',$this->scalars($payload)));$providerCode=$this->providerCode($payload);
        if($httpStatus===0||str_contains($flat,'N-998')||str_contains($flat,'INTERMITENCIA')||str_contains($flat,'CONSULTARLO MÁS TARDE'))return$this->result('verifying','stamped',true,false);
        if($operation==='cancelarPEM'){
            $folio=$this->folioStatus($payload);
            if(in_array($folio,['203','204','205'],true)||str_contains($flat,'RECHAZ'))return$this->result('rejected','stamped',false,true);
            if($folio==='202'||str_contains($flat,'CANCELADO')||str_contains($flat,'CANCELADA'))return$this->result('cancelled','cancelled',false,false);
            if($folio==='201'||$providerCode==='201'||str_contains($flat,'SOLICITUD DE CANCELACIÓN'))return$this->result('pending','stamped',false,false);
            return$this->result('verifying','stamped',true,false);
        }
        $estado=strtoupper(trim((string)($payload['Estado']??$payload['estado']??'')));$cancel=strtoupper(trim((string)($payload['EstatusCancelacion']??$payload['estatusCancelacion']??'')));
        if(str_contains($estado,'CANCELADO')||str_contains($cancel,'CANCELADO'))return$this->result('cancelled','cancelled',false,false);
        if(str_contains($cancel,'RECHAZ')||str_contains($cancel,'NO ACEPT'))return$this->result('rejected','stamped',false,true);
        if(str_contains($cancel,'PROCESO')||str_contains($cancel,'PENDIENTE')||str_contains($cancel,'PLAZO'))return$this->result('pending','stamped',false,false);
        if(str_contains($estado,'VIGENTE')&&$cancel!=='')return$this->result('pending','stamped',false,false);
        return$this->result('verifying','stamped',true,false);
    }
    private function result(string$cancellation,string$fiscal,bool$reconcile,bool$retry):array{return['cancellation_status'=>$cancellation,'fiscal_status'=>$fiscal,'requires_reconciliation'=>$reconcile,'retry_allowed'=>$retry,'service_status'=>match($cancellation){'cancelled'=>'accepted','pending'=>'pending','rejected'=>'rejected',default=>'unknown'}];}
    private function providerCode(array$p):string{return is_scalar($p['code']??null)?trim((string)$p['code']):'';}
    private function folioStatus(array$p):string{$uuid=$p['data']['uuid']??$p['uuid']??null;if(is_array($uuid)){foreach($uuid as$value)if(is_scalar($value)&&preg_match('/^20[1-5]$/',(string)$value))return(string)$value;}if(isset($p['data']['acuse'])&&preg_match('/<EstatusUUID>(20[1-5])<\/EstatusUUID>/',(string)$p['data']['acuse'],$m))return$m[1];return'';}
    private function scalars(mixed$v):array{$out=[];$walk=static function(mixed$x)use(&$walk,&$out):void{if(is_array($x)||is_object($x)){foreach((array)$x as$k=>$e){$out[]=(string)$k;$walk($e);}}elseif(is_scalar($x))$out[]=trim((string)$x);};$walk($v);return$out;}
}
