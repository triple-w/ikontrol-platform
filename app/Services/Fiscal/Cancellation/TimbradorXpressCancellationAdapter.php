<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Cancellation;

use App\Contracts\Fiscal\Cancellation\FiscalCancellationAdapterInterface;
use Config\TimbradorXpress;
use RuntimeException;
use Throwable;

final class TimbradorXpressCancellationAdapter implements FiscalCancellationAdapterInterface
{
    private $client;
    public function __construct(private readonly ?TimbradorXpress $configuration=null,$client=null){$this->client=$client?:service('curlrequest');}

    public function cancel(array $request):array
    {
        foreach(['uuid','issuer_rfc','receiver_rfc','total','reason','key_pem','certificate_pem'] as$key)if(!isset($request[$key])||$request[$key]==='')throw new RuntimeException("Falta {$key} para cancelar el CFDI.");
        return $this->call('cancelarPEM',[
            'keyPEM'=>$request['key_pem'],'cerPEM'=>$request['certificate_pem'],'uuid'=>$request['uuid'],
            'rfcEmisor'=>$request['issuer_rfc'],'rfcReceptor'=>$request['receiver_rfc'],'total'=>$request['total'],
            'motivo'=>$request['reason'],'folioSustitucion'=>$request['replacement_uuid']??'',
        ],true);
    }

    public function query(array $request):array
    {
        foreach(['uuid','issuer_rfc','receiver_rfc','total'] as$key)if(empty($request[$key]))throw new RuntimeException("Falta {$key} para consultar la cancelación.");
        return $this->call('consultarEstadoSAT',['uuid'=>$request['uuid'],'rfcEmisor'=>$request['issuer_rfc'],'rfcReceptor'=>$request['receiver_rfc'],'total'=>$request['total']],false);
    }

    private function call(string$operation,array$params,bool$cancellation):array
    {
        $config=$this->configuration??config('TimbradorXpress');
        if(!$config->isConfigured())throw new RuntimeException('TimbradorXpress no está configurado.');
        $config->assertSandbox();
        try{
            $response=$this->client->post($config->baseUrl.$operation,['form_params'=>['apikey'=>$config->apiKey]+$params,'connect_timeout'=>$config->connectTimeout,'timeout'=>$config->requestTimeout,'verify'=>true,'http_errors'=>false,'headers'=>['Accept'=>'application/json','Content-Type'=>'application/x-www-form-urlencoded']]);
            $body=(string)$response->getBody();$http=(int)$response->getStatusCode();return$this->interpret($body,$http,$operation);
        }catch(Throwable$e){log_message('warning','TimbradorXpress cancellation transport: {type}',['type'=>get_class($e)]);return['status'=>'unknown','code'=>null,'message'=>'No fue posible confirmar la respuesta de TimbradorXpress.','ack_base64'=>null,'request_sent'=>true,'http_status'=>0];}
    }
    public function interpret(string$body,int$http,string$operation):array
    {
        $decoded=json_decode($body,true,32,JSON_THROW_ON_ERROR);if(!is_array($decoded))throw new RuntimeException('TimbradorXpress devolvió una estructura no reconocida.');
            $data=array_key_exists('data',$decoded)?$decoded['data']:$decoded;$code=is_scalar($decoded['code']??null)?trim((string)$decoded['code']):'';$message=is_scalar($decoded['message']??null)?$this->sanitize((string)$decoded['message']):'';
            if(isset($decoded['CodigoEstatus'])&&is_scalar($decoded['CodigoEstatus'])){$message=$this->sanitize((string)$decoded['CodigoEstatus']);if(preg_match('/\b([A-Z]\s*-\s*\d{3})\b/i',$message,$match))$code=str_replace(' ','',strtoupper($match[1]));}
            if(is_string($data)){try{$inner=json_decode($data,true,32,JSON_THROW_ON_ERROR);$data=$inner;}catch(Throwable){}}
            $mapped=(new FiscalCancellationStatusMapper())->map($operation,$decoded,$http);$status=$mapped['service_status'];
            $ack=null;foreach($this->scalars($data)as$value){$bytes=base64_decode($value,true);if($bytes!==false&&str_starts_with(ltrim($bytes),'<?xml')){$ack=$value;break;}if(str_starts_with(ltrim($value),'<?xml')){$ack=base64_encode($value);break;}}
            return['status'=>$status,'code'=>$code!==''?$code:null,'message'=>$message!==''?$message:'Respuesta recibida de TimbradorXpress.','ack_base64'=>$ack,'request_sent'=>true,'http_status'=>$http,'provider_payload_base64'=>base64_encode($body),'operation'=>$operation,'evidence'=>$data,'normalized'=>$mapped];
    }
    private function scalars(mixed$value):array{$out=[];$walk=static function(mixed$v)use(&$walk,&$out):void{if(is_array($v)||is_object($v)){foreach((array)$v as$key=>$entry){$out[]=(string)$key;$walk($entry);}}elseif(is_scalar($v))$out[]=trim((string)$v);};$walk($value);return$out;}
    private function sanitize(string$value):string{return mb_substr(trim(strip_tags((string)preg_replace('/[\x00-\x1F\x7F]+/u',' ',$value))),0,500);}
}
