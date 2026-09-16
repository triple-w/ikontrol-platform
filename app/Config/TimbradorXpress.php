<?php
declare(strict_types=1);
namespace Config;

use CodeIgniter\Config\BaseConfig;
use RuntimeException;

final class TimbradorXpress extends BaseConfig
{
    public const SANDBOX_URL='https://dev.timbradorxpress.mx/api/rest/servicio/';
    public const PRODUCTION_URL='https://app.timbradorxpress.mx/api/rest/servicio/';
    public string $environment;
    public string $apiKey;
    public string $baseUrl;
    public int $connectTimeout;
    public int $requestTimeout;
    public bool $productionEnabled;
    public int $maxSignedXmlBytes=2097152;
    public int $maxResponseBytes=8388608;
    public int $maxPdfBytes=10485760;
    public string $pdfTemplate='Principal';

    public function __construct()
    {
        parent::__construct();
        $this->environment=strtolower(trim((string)env('TIMBRADORXPRESS_ENVIRONMENT','sandbox')));
        if(!in_array($this->environment,['sandbox','production'],true))throw new RuntimeException('TIMBRADORXPRESS_ENVIRONMENT debe ser sandbox o production.');
        $this->productionEnabled=filter_var(env('TIMBRADORXPRESS_PRODUCTION_ENABLED',false),FILTER_VALIDATE_BOOL);
        if($this->environment==='production'&&!$this->productionEnabled)throw new RuntimeException('TimbradorXpress producción está deshabilitado por el servidor.');
        $sandbox=(string)env('TIMBRADORXPRESS_BASE_URL_SANDBOX',self::SANDBOX_URL);
        $production=(string)env('TIMBRADORXPRESS_BASE_URL_PRODUCTION',self::PRODUCTION_URL);
        if($sandbox!==self::SANDBOX_URL||$production!==self::PRODUCTION_URL)throw new RuntimeException('Los endpoints TimbradorXpress no pertenecen a la lista permitida.');
        $this->baseUrl=$this->environment==='sandbox'?$sandbox:$production;
        $this->apiKey=trim((string)env($this->environment==='sandbox'?'TIMBRADORXPRESS_APIKEY_SANDBOX':'TIMBRADORXPRESS_APIKEY_PRODUCTION',''));
        $this->connectTimeout=max(2,min(30,(int)env('TIMBRADORXPRESS_CONNECT_TIMEOUT',10)));
        $this->requestTimeout=max(5,min(120,(int)env('TIMBRADORXPRESS_REQUEST_TIMEOUT',60)));
    }
    public function isConfigured():bool{return $this->apiKey!=='';}
    public function assertSandbox():void
    {
        if($this->environment!=='sandbox'||$this->productionEnabled||!str_starts_with($this->baseUrl,'https://dev.timbradorxpress.mx/'))throw new RuntimeException('La primera prueba sólo está permitida en sandbox con producción deshabilitada.');
    }
}
