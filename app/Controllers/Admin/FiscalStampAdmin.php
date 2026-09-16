<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\Fiscal\FiscalStampAdminService;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

final class FiscalStampAdmin extends Controller
{
    private const BASE_PATH = 'admin/ikontrol/timbres/manage-7f9c2a4d91';
    private FiscalStampAdminService $service;

    public function __construct()
    {
        helper(['general', 'date_time']);
        $this->service = new FiscalStampAdminService();
    }

    public function index(): ResponseInterface|string
    {
        $key = $this->authorize();
        return view('external/fiscal_stamp_admin/index', [
            'accounts'=>$this->service->getAccounts(),'key'=>$key,'base_path'=>self::BASE_PATH,
            'message'=>(string)session()->getFlashdata('stamp_admin_message'),
            'error'=>(string)session()->getFlashdata('stamp_admin_error'),
        ]);
    }

    public function adjust(): ResponseInterface
    {
        $key = $this->authorize();
        try {
            $issuerId=(int)$this->request->getPost('issuer_profile_id');
            $environment=trim((string)$this->request->getPost('environment'));
            $quantity=filter_var($this->request->getPost('quantity'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1000000]]);
            if($quantity===false)throw new \InvalidArgumentException('La cantidad debe ser un entero entre 1 y 1,000,000.');
            $type=(string)$this->request->getPost('type');$reason=(string)$this->request->getPost('reason');$reference=$this->request->getPost('reference');$requestId=(string)$this->request->getPost('request_id');
            $movement=match($type){'credit'=>$this->service->credit($issuerId,$environment,$quantity,$reason,$reference,$requestId),'debit'=>$this->service->debit($issuerId,$environment,$quantity,$reason,$reference,$requestId),default=>throw new \InvalidArgumentException('El tipo de ajuste no es válido.')};
            session()->setFlashdata('stamp_admin_message','Ajuste registrado. Saldo disponible: '.(int)$movement->available_after.'.');
        } catch (Throwable $e) {
            $diagnostic = json_encode([
                    'issuer_profile_id' => $issuerId ?? (int) $this->request->getPost('issuer_profile_id'),
                    'environment' => $environment ?? trim((string) $this->request->getPost('environment')),
                    'adjustment_type' => $type ?? (string) $this->request->getPost('type'),
                    'quantity' => $quantity ?? $this->request->getPost('quantity'),
                    'reason' => $reason ?? (string) $this->request->getPost('reason'),
                    'reference' => $reference ?? $this->request->getPost('reference'),
                    'exception_class' => get_class($e),
                    'exception_message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            log_message('error', 'FISCAL_STAMP_ADMIN_ADJUSTMENT_FAILURE {context}', ['context' => $diagnostic]);
            error_log('FISCAL_STAMP_ADMIN_ADJUSTMENT_FAILURE ' . $diagnostic);
            @file_put_contents(WRITEPATH . 'logs/fiscal_stamp_admin.log', date('c') . ' FISCAL_STAMP_ADMIN_ADJUSTMENT_FAILURE ' . $diagnostic . PHP_EOL, FILE_APPEND | LOCK_EX);
            $message=$e->getMessage()==='Insufficient fiscal stamp balance.'?'No hay timbres disponibles suficientes para realizar el ajuste.':$e->getMessage();
            session()->setFlashdata('stamp_admin_error',$e instanceof \InvalidArgumentException||$e instanceof \App\Services\Fiscal\Stamps\FiscalStampBalanceException?$message:'No fue posible registrar el ajuste.');
        }
        return redirect()->to(site_url(self::BASE_PATH).'?key='.rawurlencode($key));
    }

    public function history(?int $issuerId = null): ResponseInterface|string
    {
        $key=$this->authorize();$type=trim((string)$this->request->getGet('type'));$from=trim((string)$this->request->getGet('from'));$to=trim((string)$this->request->getGet('to'));
        return view('external/fiscal_stamp_admin/history',['history'=>$this->service->getHistory($issuerId,$type?:null,$from?:null,$to?:null),'issuer_id'=>$issuerId,'type'=>$type,'from'=>$from,'to'=>$to,'key'=>$key,'base_path'=>self::BASE_PATH]);
    }

    private function authorize(): string
    {
        $configured=trim((string)env('TIMBRES_ADMIN_SECRET',''));
        $provided=trim((string)($this->request->getHeaderLine('X-Timbres-Admin-Secret')?:$this->request->getVar('key')));
        if(strlen($configured)<32||$provided===''||!hash_equals($configured,$provided)){
            $this->response->setStatusCode(404)->setHeader('Cache-Control','no-store, private')->setBody('Not Found')->send();exit;
        }
        $this->response->setHeader('Cache-Control','no-store, private')->setHeader('Referrer-Policy','no-referrer')->setHeader('X-Robots-Tag','noindex, nofollow');
        return $provided;
    }
}
