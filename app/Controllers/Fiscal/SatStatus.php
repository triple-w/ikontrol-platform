<?php
declare(strict_types=1);

namespace App\Controllers\Fiscal;

use App\Controllers\Security_Controller;
use App\Services\Fiscal\Cancellation\FiscalCancellationService;
use App\Services\Fiscal\SatStatus\FiscalSatStatusService;
use CodeIgniter\Exceptions\PageNotFoundException;
use RuntimeException;
use Throwable;

final class SatStatus extends Security_Controller
{
    public function form()
    {
        $this->guardPermission();
        $id=(int)$this->request->getPost('document_id');
        $this->assertDocumentAccess($id);
        $context=(new FiscalSatStatusService())->context($id);
        return $this->template->view('fiscal/invoices/sat_status_form',$context+['query_key'=>bin2hex(random_bytes(16))]);
    }

    public function query(): void
    {
        $this->runQuery(false);
    }

    public function queryCancellation(): void
    {
        $this->runQuery(true);
    }

    private function runQuery(bool $cancellationPermission): void
    {
        $this->response->setContentType('application/json');
        try{
            if($cancellationPermission){if(!$this->allowed('fiscal_status_query'))app_redirect('forbidden');}else{$this->guardPermission();}
            $id=(int)$this->request->getPost('document_id');$this->assertDocumentAccess($id);
            $service=new FiscalSatStatusService(null,null,null,null,
                static fn(int$documentId,int$userId,string$key):array=>(new FiscalCancellationService())->queryStatus($documentId,$userId,true,$key));
            $result=$service->query($id,(int)$this->login_user->id,true,(string)$this->request->getPost('query_key'));
            echo json_encode($result+['csrf'=>['name'=>csrf_token(),'hash'=>csrf_hash()]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        }catch(Throwable$e){
            log_message('warning','SAT status query blocked: {type} {message}',['type'=>get_class($e),'message'=>$e->getMessage()]);
            echo json_encode(['success'=>false,'status'=>'blocked','message'=>$e instanceof RuntimeException?$e->getMessage():'No fue posible consultar el estado SAT.','csrf'=>['name'=>csrf_token(),'hash'=>csrf_hash()]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        }
    }

    private function assertDocumentAccess(int$id):void
    {
        $document=db_connect()->table('fiscal_documents')->select('invoice_id')->where(['id'=>$id,'deleted'=>0])->get(1)->getRow();
        if(!$document||(!$this->login_user->is_admin&&!$this->allowed('fiscal.invoices.view')&&!$this->allowed('fiscal_invoice_view')))throw PageNotFoundException::forPageNotFound();
    }
    private function guardPermission():void{if(!$this->allowed('fiscal.sat_status.query'))app_redirect('forbidden');}
    private function allowed(string$permission):bool{if($this->login_user->is_admin)return true;$all=is_array($this->login_user->permissions)?$this->login_user->permissions:(@unserialize((string)$this->login_user->permissions)?:[]);return(bool)get_array_value($all,$permission);}
}
