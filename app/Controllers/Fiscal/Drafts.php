<?php
declare(strict_types=1);
namespace App\Controllers\Fiscal;

use App\Controllers\Security_Controller;
use App\Services\Fiscal\FiscalDraftStatusPresenter;
use App\Services\Fiscal\FiscalDraftWorkflowService;
use App\Services\Fiscal\FiscalDraftSnapshotService;
use App\Services\Fiscal\FiscalDraftStampingService;
use App\Services\Fiscal\FiscalPreInvoiceService;
use App\Services\Fiscal\FiscalReviewPresenter;
use App\Services\Fiscal\FiscalInvoiceFlowService;
use App\Services\Fiscal\FiscalReviewPreparation;
use CodeIgniter\Exceptions\PageNotFoundException;
use Throwable;

final class Drafts extends Security_Controller
{
    public function index()
    {
        $this->guard('fiscal.advanced.view');
        return $this->template->rander('fiscal/drafts/index', ['statuses'=>$this->statusOptions()]);
    }

    public function listData(): void
    {
        $this->guard('fiscal.advanced.view');
        $builder=db_connect()->table('fiscal_drafts d')
            ->select('d.id,d.provisional_series,d.issue_date,d.total,d.status,d.created_at,d.updated_at,d.created_by,p.legal_name,u.first_name,u.last_name,COUNT(DISTINCT a.sale_id) sale_count')
            ->join('fiscal_profiles p','p.id=d.receiver_profile_id','left')
            ->join('users u','u.id=d.created_by','left')
            ->join('fiscal_draft_sales a','a.fiscal_draft_id=d.id','left')
            ->groupBy('d.id')->orderBy('d.id','DESC');
        foreach(['status','client','sale_id','created_from','issue_from','user_id']as$key){$value=trim((string)$this->request->getPost($key));if($value==='')continue;match($key){'status'=>$builder->where('d.status',$value),'client'=>$builder->like('p.legal_name',$value),'sale_id'=>$builder->where('a.sale_id',(int)$value),'created_from'=>$builder->where('d.created_at >=',$value.' 00:00:00'),'issue_from'=>$builder->where('d.issue_date >=',$value.' 00:00:00'),'user_id'=>$builder->where('d.created_by',(int)$value)};}
        $amount=trim((string)$this->request->getPost('amount'));if($amount!=='')$builder->where('d.total',$amount);
        $rows=[];
        foreach($builder->limit(200)->get()->getResult()as$row){
            if(!$this->canAccessDraft((int)$row->id))continue;
            [$label,$color]=(new FiscalDraftStatusPresenter())->present((string)$row->status);
            $actions=anchor(get_uri('fiscal/drafts/'.$row->id),'Ver',['class'=>'dropdown-item']);
            if($this->allowed('fiscal.drafts.edit')&&in_array($row->status,['draft','ready','error'],true))$actions.=anchor(get_uri('fiscal/drafts/'.$row->id.'/edit'),'Editar',['class'=>'dropdown-item']);
            $actions.=anchor(get_uri('fiscal/drafts/'.$row->id.'/preinvoice'),'Ver prefactura',['class'=>'dropdown-item','target'=>'_blank']);
            $actions.='<span class="dropdown-item text-muted" title="El timbrado se integrará en el siguiente incremento.">Preparar facturación</span>';
            if($this->allowed('fiscal.drafts.discard')&&in_array($row->status,['draft','ready','error'],true))$actions.=js_anchor('Descartar',['class'=>'dropdown-item text-danger discard-draft','data-id'=>$row->id]);
            $rows[]=['#'.$row->id,esc($row->created_at),esc($row->issue_date),esc($row->legal_name?:'-'),(int)$row->sale_count,to_currency($row->total),'<span class="badge bg-'.$color.'">'.esc($label).'</span>',esc(trim($row->first_name.' '.$row->last_name)),esc($row->updated_at),'<div class="dropdown"><button class="btn btn-default btn-sm dropdown-toggle" data-bs-toggle="dropdown">Acciones</button><div class="dropdown-menu dropdown-menu-end">'.$actions.'</div></div>'];
        }
        echo json_encode(['data'=>$rows],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }

    public function create($saleId=0)
    {
        // Legacy permission contract: guard('fiscal.drafts.create'); the commercial
        // fiscal.sales.invoice permission is equivalent only for this canonical entry.
        $this->guardAny(['fiscal.drafts.create','fiscal.sales.invoice']);$this->guardSale((int)$saleId);
        $workflow=new FiscalDraftWorkflowService();$draftId=$this->activeDraftForSale((int)$saleId);
        $data=$draftId?$this->formData($draftId,[]):$this->formData(null,[(int)$saleId]);
        if(!$draftId){$input=$this->defaultReviewInput($data,(int)$saleId);$input['save_as_draft']=0;$data['preparation']=(new FiscalReviewPreparation())->prepare($data,$input);}
        $data['normal_sale_id']=(int)$saleId;$data['review']=$this->review($data);
        if($this->request->isAJAX())return$this->template->view('fiscal/drafts/review',$data);
        return$this->template->rander('fiscal/drafts/review',$data);
    }

    public function store(): void
    {
        $this->response->setContentType('application/json');
        $this->guardAny(['fiscal.drafts.create','fiscal.sales.invoice']);$saleIds=array_map('intval',(array)$this->request->getPost('sale_ids'));foreach($saleIds as$id)$this->guardSale($id);
        try{$workflow=new FiscalDraftWorkflowService();if($this->request->getPost('confirm_receiver_update')){$sale=db_connect()->table('invoices')->where('id',$saleIds[0])->get(1)->getRow();$workflow->updateReceiver((int)$this->request->getPost('receiver_profile_id'),(int)$sale->client_id,(array)$this->request->getPost(),(int)$this->login_user->id);}$result=$workflow->save((array)$this->request->getPost(),(int)$this->login_user->id);$response=['success'=>true,'message'=>'Revisión fiscal actualizada.','redirect'=>get_uri('fiscal/drafts/'.$result['id']),'data'=>$result,'csrf'=>['name'=>csrf_token(),'hash'=>csrf_hash()]];if($this->request->getPost('ux_mode')==='normal'){$data=$this->formData((int)$result['id'],[]);$data['normal_sale_id']=(int)($this->request->getPost('sale_context_id')?:($saleIds[0]??0));$data['review']=$this->review($data);$response['html']=$this->template->view('fiscal/drafts/review',$data);}echo json_encode($response);}
        catch(Throwable$e){echo json_encode(['success'=>false,'message'=>$this->workflowError($e),'csrf'=>['name'=>csrf_token(),'hash'=>csrf_hash()]]);}
    }

    public function show($draftId=0)
    {
        $this->guard('fiscal.advanced.view');$this->guardDraft((int)$draftId);
        $db=db_connect();$draft=$db->table('fiscal_drafts')->where('id',(int)$draftId)->get(1)->getRow();
        $sales=$db->table('fiscal_draft_sales a')->select('a.*,i.display_id,i.bill_date,i.invoice_total')
            ->join('invoices i','i.id=a.sale_id','left')->where('a.fiscal_draft_id',(int)$draftId)->get()->getResult();
        $items=$db->table('fiscal_draft_items')->where('fiscal_draft_id',(int)$draftId)->get()->getResult();
        $audit=$db->table('fiscal_draft_audit a')->select('a.event,a.created_at,u.first_name,u.last_name')->join('users u','u.id=a.user_id','left')->where('a.fiscal_draft_id',(int)$draftId)->orderBy('a.id','DESC')->get()->getResult();
        $payload=json_decode((string)$draft->fiscal_payload,true)?:[];[$label,$color]=(new FiscalDraftStatusPresenter())->present($draft->status);
        $snapshot=null;try{$snapshot=(new FiscalDraftSnapshotService($db))->getCompleteFiscalSnapshot((int)$draftId);}catch(Throwable){}
        $preflight=(new FiscalDraftStampingService($db))->preflight((int)$draftId);$certificate=$db->table('fiscal_issuer_certificates')->where(['issuer_profile_id'=>$draft->issuer_id,'deleted'=>0])->orderBy('is_default','DESC')->get(1)->getRow();$attempts=$artifacts=[];if($draft->fiscal_document_id){$attempts=$db->table('fiscal_stamp_attempts')->where('fiscal_document_id',$draft->fiscal_document_id)->orderBy('id','DESC')->get()->getResult();$artifacts=$db->table('fiscal_document_artifacts')->where('fiscal_document_id',$draft->fiscal_document_id)->orderBy('id','DESC')->get()->getResult();}
        return$this->template->rander('fiscal/drafts/show',compact('draft','sales','items','audit','payload','label','color','snapshot','preflight','certificate','attempts','artifacts')+['can_edit'=>$this->allowed('fiscal.drafts.edit'),'can_discard'=>$this->allowed('fiscal.drafts.discard'),'can_stamp'=>$this->allowed('fiscal.invoices.stamp')]);
    }

    public function edit($draftId=0)
    {
        $this->guard('fiscal.advanced.view');$this->guardDraft((int)$draftId);
        return$this->template->rander('fiscal/drafts/form',$this->formData((int)$draftId,[]));
    }

    public function update($draftId=0): void
    {
        $this->response->setContentType('application/json');
        if($this->request->getPost('ux_mode')==='normal')$this->guardAny(['fiscal.drafts.edit','fiscal.drafts.create','fiscal.sales.invoice']);else{$this->guard('fiscal.advanced.view');$this->guard('fiscal.drafts.edit');}$this->guardDraft((int)$draftId);$saleIds=array_map('intval',(array)$this->request->getPost('sale_ids'));foreach($saleIds as$id)$this->guardSale($id);
        try{$workflow=new FiscalDraftWorkflowService();if($this->request->getPost('confirm_receiver_update')){$sale=db_connect()->table('invoices')->where('id',$saleIds[0])->get(1)->getRow();$workflow->updateReceiver((int)$this->request->getPost('receiver_profile_id'),(int)$sale->client_id,(array)$this->request->getPost(),(int)$this->login_user->id);}$result=$workflow->save((array)$this->request->getPost(),(int)$this->login_user->id,(int)$draftId);$response=['success'=>true,'message'=>'Revisión fiscal actualizada.','redirect'=>get_uri('fiscal/drafts/'.$draftId),'data'=>$result,'csrf'=>['name'=>csrf_token(),'hash'=>csrf_hash()]];if($this->request->getPost('ux_mode')==='normal'){$data=$this->formData((int)$draftId,[]);$data['normal_sale_id']=(int)($this->request->getPost('sale_context_id')?:($saleIds[0]??0));$data['review']=$this->review($data);$response['html']=$this->template->view('fiscal/drafts/review',$data);}echo json_encode($response);}
        catch(Throwable$e){echo json_encode(['success'=>false,'message'=>$this->workflowError($e),'csrf'=>['name'=>csrf_token(),'hash'=>csrf_hash()]]);}
    }

    public function discard($draftId=0): void
    {
        $this->guard('fiscal.drafts.discard');$this->guardDraft((int)$draftId);
        try{(new FiscalDraftWorkflowService())->discard((int)$draftId,(int)$this->login_user->id,(string)$this->request->getPost('reason'));echo json_encode(['success'=>true,'message'=>'El borrador fue descartado y sus reservas quedaron liberadas.','csrf'=>['name'=>csrf_token(),'hash'=>csrf_hash()]]);}
        catch(Throwable$e){echo json_encode(['success'=>false,'message'=>$this->safeMessage($e),'csrf'=>['name'=>csrf_token(),'hash'=>csrf_hash()]]);}
    }

    public function ready($draftId=0):void
    {
        $this->guard('fiscal.drafts.edit');$this->guardDraft((int)$draftId);
        try{(new FiscalDraftWorkflowService())->markReady((int)$draftId,(int)$this->login_user->id);echo json_encode(['success'=>true,'message'=>'El borrador está listo para facturar.','csrf'=>['name'=>csrf_token(),'hash'=>csrf_hash()]]);}
        catch(Throwable$e){echo json_encode(['success'=>false,'message'=>$this->safeMessage($e),'csrf'=>['name'=>csrf_token(),'hash'=>csrf_hash()]]);}
    }

    public function stamp($draftId=0): void
    {
        $this->guard('fiscal.invoices.stamp');$this->guardDraft((int)$draftId);
        try {
            $result=(new FiscalDraftStampingService())->stamp((int)$draftId,(int)$this->login_user->id,true);
            echo json_encode(['success'=>(bool)($result['result']['xmlAvailable']??false),
                'message'=>($result['result']['xmlAvailable']??false)?'La factura fue generada correctamente.':($result['result']['providerMessage']??'No fue posible completar el timbrado.'),
                'redirect'=>get_uri('fiscal/invoices/'.$result['document_id']),
                'csrf'=>['name'=>csrf_token(),'hash'=>csrf_hash()]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        } catch(Throwable$e) {
            log_message('error','Draft stamping failed for draft {draft}: {type}',['draft'=>(int)$draftId,'type'=>get_class($e)]);
            echo json_encode(['success'=>false,'message'=>$this->stampMessage($e),'csrf'=>['name'=>csrf_token(),'hash'=>csrf_hash()]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        }
    }

    public function invoiceFlow($draftId=0):void
    {
        $this->guard('fiscal.invoices.stamp');$this->guardDraft((int)$draftId);
        $result=(new FiscalInvoiceFlowService())->execute((int)$draftId,(int)$this->login_user->id,true);
        $result['csrf']=['name'=>csrf_token(),'hash'=>csrf_hash()];
        echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }

    public function invoiceSale($saleId=0):void
    {
        $this->guard('fiscal.invoices.stamp');$this->guardSale((int)$saleId);
        $result=(new FiscalInvoiceFlowService())->executeSale((int)$saleId,(array)$this->request->getPost(),(int)$this->login_user->id,true);
        $result['csrf']=['name'=>csrf_token(),'hash'=>csrf_hash()];echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }

    public function preinvoice($draftId=0)
    {
        $this->guardAny(['fiscal.drafts.view','fiscal.drafts.create','fiscal.sales.invoice']);$this->guardDraft((int)$draftId);
        $service=new FiscalDraftWorkflowService();$service->auditPreinvoice((int)$draftId,(int)$this->login_user->id);
        return view('fiscal/drafts/preinvoice',[
            'preinvoice'=>(new FiscalPreInvoiceService())->build((int)$draftId),
            'can_edit'=>$this->allowed('fiscal.drafts.edit'),
            'issuer_logo'=>function_exists('get_company_logo')?get_company_logo(0,'invoice',true):'',
        ]);
    }

    public function updateReceiver($profileId=0):void
    {
        $this->guard('fiscal.drafts.edit');
        $saleId=(int)$this->request->getPost('sale_id');$this->guardSale($saleId);$sale=db_connect()->table('invoices')->where('id',$saleId)->get(1)->getRow();
        try{(new FiscalDraftWorkflowService())->updateReceiver((int)$profileId,(int)$sale->client_id,(array)$this->request->getPost(),(int)$this->login_user->id);echo json_encode(['success'=>true,'message'=>'Datos fiscales actualizados.','csrf'=>['name'=>csrf_token(),'hash'=>csrf_hash()]]);}
        catch(Throwable$e){echo json_encode(['success'=>false,'message'=>$this->safeMessage($e),'csrf'=>['name'=>csrf_token(),'hash'=>csrf_hash()]]);}
    }

    private function stampMessage(Throwable$e):string{$safe=['El borrador debe editarse y guardarse nuevamente antes de facturarse.','El borrador no está listo para facturarse.','El borrador ya está siendo procesado.','La fecha de expedición ya no es válida.','El emisor no tiene un CSD utilizable.','Una venta relacionada no está disponible para facturación.'];return in_array($e->getMessage(),$safe,true)?$e->getMessage():'No fue posible generar el CFDI. Revisa el borrador e inténtalo nuevamente.';}
    private function formData(?int$id,array$sales):array{return(new FiscalDraftWorkflowService())->formData($id,$sales)+['draft_id'=>$id];}
    private function review(array$data):array{return(new FiscalReviewPresenter())->present($data,$this->allowed('fiscal.advanced.view'));}
    private function activeDraftForSale(int$saleId):?int{$row=db_connect()->table('fiscal_drafts d')->select('d.id')->join('fiscal_draft_sales a','a.fiscal_draft_id=d.id')->where(['a.sale_id'=>$saleId,'a.allocation_status'=>'reserved','d.data_origin'=>'operational'])->whereIn('d.status',['draft','ready','error'])->orderBy('d.id','DESC')->get(1)->getRow();return$row?(int)$row->id:null;}
    private function defaultReviewInput(array$data,int$saleId):array
    {
        $issuer=$data['issuer']??null;$receiver=$data['receiver']??null;$series=null;foreach(($data['series']??[])as$candidate)if((int)$candidate->issuer_profile_id===(int)($issuer->id??0)){$series=$candidate;break;}
        $use='';if($receiver?->default_cfdi_use_id)foreach(($data['cfdi_uses']??[])as$row)if((int)$row->id===(int)$receiver->default_cfdi_use_id){$use=(string)$row->code;break;}
        $quantities=[];foreach(($data['sales']??[])as$entry)if((int)$entry['sale']->id===$saleId)foreach($entry['items']as$item)$quantities[(int)$item->id]=(string)$item->quantity;
        $zone=new \DateTimeZone((string)$data['issue_date_constraints']['timezone']);return['ux_mode'=>'normal','save_as_draft'=>1,'sale_ids'=>[$saleId],'quantities'=>$quantities,'issuer_id'=>(int)($issuer->id??0),'receiver_profile_id'=>(int)($receiver->id??0),'fiscal_series_id'=>(int)($series->id??0),'issue_date'=>(new \DateTimeImmutable('now',$zone))->format('Y-m-d\TH:i'),'currency_code'=>'MXN','exchange_rate'=>'1.000000','payment_method_code'=>(string)($data['payment_suggestion']['payment_method_code']??''),'payment_form_code'=>(string)($data['payment_suggestion']['payment_form_code']??''),'cfdi_use_code'=>$use,'conditions'=>'','observations'=>''];
    }
    private function guardDraft(int$id):void{if(!$this->canAccessDraft($id))throw PageNotFoundException::forPageNotFound();}
    private function canAccessDraft(int$id):bool{$rows=db_connect()->table('fiscal_draft_sales')->select('sale_id')->where('fiscal_draft_id',$id)->get()->getResult();if(!$rows)return false;foreach($rows as$row)if(!$this->can_view_invoices((int)$row->sale_id))return false;return true;}
    private function guardSale(int$id):void{if(!$id||!$this->can_view_invoices($id))throw PageNotFoundException::forPageNotFound();}
    private function allowed(string$p):bool{if($this->login_user->is_admin)return true;$all=is_array($this->login_user->permissions)?$this->login_user->permissions:(@unserialize((string)$this->login_user->permissions)?:[]);return(bool)get_array_value($all,$p);}
    private function guard(string$p):void{if(!$this->allowed($p))app_redirect('forbidden');}
    private function guardAny(array$permissions):void{foreach($permissions as$permission)if($this->allowed($permission))return;app_redirect('forbidden');}
    private function statusOptions():array{return[''=>'Todos','draft'=>'Incompleto','ready'=>'Listo para facturar','stamping'=>'En preparación','stamped'=>'Facturado','discarded'=>'Descartado','expired'=>'Expirado','error'=>'Error'];}
    private function safeMessage(Throwable$e):string{$known=['La venta no existe.','Selecciona al menos una venta.','Una venta cancelada no puede facturarse.','Las ventas seleccionadas no son compatibles.','La cantidad seleccionada no es válida.','Selecciona al menos un concepto con cantidad mayor que cero.','El saldo disponible de una o más ventas cambió. Revisa las asignaciones antes de guardar nuevamente.'];return in_array($e->getMessage(),$known,true)||str_starts_with($e->getMessage(),'Falta ')||str_contains($e->getMessage(),'fecha de expedición')?$e->getMessage():'No fue posible completar la operación del borrador.';}
    private function workflowError(Throwable$e):string{$safe=$this->safeMessage($e);if($safe!=='No fue posible completar la operación del borrador.')return$safe;$reference='FD-'.strtoupper(substr(hash('sha256',get_class($e).'|'.$e->getMessage().'|'.$e->getFile().'|'.$e->getLine().'|'.microtime(true)),0,10));log_message('error','Fiscal draft workflow failure ref={reference} class={class} message={message} file={file} line={line}',['reference'=>$reference,'class'=>get_class($e),'message'=>$e->getMessage(),'file'=>$e->getFile(),'line'=>$e->getLine()]);return'No fue posible preparar la factura. Referencia técnica: '.$reference;}
}
