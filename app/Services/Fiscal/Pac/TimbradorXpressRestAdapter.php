<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Pac;

use App\Contracts\Fiscal\Pac\PacAdapterInterface;
use App\Domain\Fiscal\Pac\PacResponse;
use App\Domain\Fiscal\Pac\StampRequest;
use App\Services\Fiscal\FiscalRuntimeContext;
use Config\Fiscal;
use Config\TimbradorXpress;
use RuntimeException;
use Throwable;

final class TimbradorXpressRestAdapter implements PacAdapterInterface
{
    private $client;

    public function __construct(
        private readonly ?TimbradorXpress $configuration = null,
        $client = null,
        private readonly ?Fiscal $fiscal = null,
        private $evidenceStore = null
    ) {
        $this->client = $client ?: service('curlrequest');
    }

    public function stamp(StampRequest $request): PacResponse
    {
        $config = $this->configuration ?? config('TimbradorXpress');
        FiscalRuntimeContext::assertPacOperational($this->fiscal ?? config('Fiscal'), $config);
        if ($request->provider !== 'timbradorxpress' || $request->environment !== $config->environment) {
            throw new RuntimeException('Proveedor o ambiente PAC no permitido.');
        }
        if (strlen($request->signedXml) > $config->maxSignedXmlBytes) throw new RuntimeException('El XML firmado excede el límite permitido.');
        if ($request->keyPem === null || preg_match('/-----BEGIN (?:RSA )?PRIVATE KEY-----/', $request->keyPem) !== 1) {
            throw new RuntimeException('La llave CSD no está disponible para timbrarConSello.');
        }

        $url = $config->baseUrl . 'timbrarConSello';
        try {
            $response = $this->client->post($url, [
                'form_params' => ['apikey'=>$config->apiKey,'xmlCFDI'=>$request->signedXml,'keyPEM'=>$request->keyPem],
                'connect_timeout'=>$config->connectTimeout,'timeout'=>$config->requestTimeout,
                'verify'=>true,'http_errors'=>false,
                'headers'=>['Accept'=>'application/json','Content-Type'=>'application/x-www-form-urlencoded'],
            ]);
        } catch (Throwable $e) {
            log_message('warning','PAC transport failure for document {document}: {type}',['document'=>$request->fiscalDocumentId,'type'=>get_class($e)]);
            $timeout = str_contains(strtolower($e->getMessage()), 'timed out') || str_contains(strtolower($e->getMessage()), 'timeout');
            return new PacResponse(null,'No fue posible confirmar la respuesta del PAC.',null,0,[
                'request_sent'=>null,'response_received'=>false,'transport_phase'=>'request_exception_before_response',
                'exception_type'=>get_class($e),
            ],true,$timeout);
        }
        try {
            $body=(string)$response->getBody();$status=(int)$response->getStatusCode();
            $contentType=method_exists($response,'getHeaderLine')?(string)$response->getHeaderLine('Content-Type'):'';
        } catch (Throwable $e) {
            return new PacResponse(null,'La petición fue enviada, pero no fue posible leer la respuesta del PAC.',null,0,[
                'request_sent'=>true,'response_received'=>false,'transport_phase'=>'response_read_failed',
                'exception_type'=>get_class($e),
            ],true,false);
        }
        $forensic=['request_sent'=>true,'response_received'=>true,'transport_phase'=>'http_response_received',
            'response_content_type'=>mb_substr(trim($contentType),0,160),'response_body_length'=>strlen($body),
            'response_body_sha256'=>hash('sha256',$body)];
        $evidence=$this->preserveEvidence($request->fiscalDocumentId,$body);
        try {
            $parsed=(new TimbradorXpressResponseParser())->parse($body,$status);
            return new PacResponse($parsed->code,$parsed->message,$parsed->data,$parsed->httpStatus,
                $parsed->metadata+$forensic+$evidence,false,false);
        } catch (Throwable $parseError) {
            return new PacResponse(null,'Respuesta exterior PAC inválida.',null,$status,$forensic+$evidence+[
                'parsing_phase'=>'outer_response_invalid','response_error_class'=>get_class($parseError),
                'response_error_message'=>$this->sanitize($parseError->getMessage()),'response_structure'=>$this->describeOuter($body),
            ],false,false);
        }
    }

    public function getStampStatus(array $query): PacResponse
    {
        $config=$this->configuration??config('TimbradorXpress');
        FiscalRuntimeContext::assertPacOperational($this->fiscal??config('Fiscal'),$config);
        foreach(['environment','uuid','rfcEmisor','rfcReceptor','total'] as $key)if(empty($query[$key]))throw new RuntimeException("Falta {$key} para consultar el estado SAT.");
        if($query['environment']!==$config->environment)throw new RuntimeException('PAC no configurado para el ambiente.');
        $url=$config->baseUrl.'consultarEstadoSAT';
        try {
            $response=$this->client->post($url,['form_params'=>['apikey'=>$config->apiKey,'uuid'=>$query['uuid'],'rfcEmisor'=>$query['rfcEmisor'],'rfcReceptor'=>$query['rfcReceptor'],'total'=>$query['total']],'connect_timeout'=>$config->connectTimeout,'timeout'=>$config->requestTimeout,'verify'=>true,'http_errors'=>false,'headers'=>['Accept'=>'application/json','Content-Type'=>'application/x-www-form-urlencoded']]);
            return (new TimbradorXpressResponseParser())->parse((string)$response->getBody(),(int)$response->getStatusCode());
        } catch (\Throwable $e) {
            return new PacResponse(null,'No fue posible consultar el estado SAT.',null,0,['exception_type'=>get_class($e)],true,false);
        }
    }

    private function sanitize(string $value): string{return mb_substr(trim(strip_tags((string)preg_replace('/[\x00-\x1F\x7F]+/u',' ',$value))),0,500);}
    private function describeOuter(string $body): array{$decoded=json_decode($body,true);return['json_valid'=>json_last_error()===JSON_ERROR_NONE,'type'=>get_debug_type($decoded),'keys'=>is_array($decoded)?array_slice(array_map('strval',array_keys($decoded)),0,30):[],'utf8_valid'=>mb_check_encoding($body,'UTF-8')];}
    private function preserveEvidence(int $documentId,string $body):array
    {
        try {
            $stored=is_callable($this->evidenceStore)
                ? ($this->evidenceStore)($documentId,$body)
                : (new PacContingencyStorageService(new PacSecretVault()))->storePayload($documentId,$body);
            return ['evidence_storage'=>'stored','forensic_path'=>$stored['path']??null,'forensic_hash'=>$stored['hash']??null];
        } catch (Throwable $e) {
            log_message('error','PAC response received but local evidence storage failed for document {document}: {type}',[
                'document'=>$documentId,'type'=>get_class($e),
            ]);
            return ['evidence_storage'=>'failed','evidence_storage_error_class'=>get_class($e)];
        }
    }
}
