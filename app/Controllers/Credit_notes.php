<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Services\Fiscal\CreditNoteService;
use App\Services\Fiscal\Pdf\FiscalPdfTemplateResolver;
use CodeIgniter\Exceptions\PageNotFoundException;use Throwable;
final class Credit_notes extends Security_Controller{
 private function service():CreditNoteService{return new CreditNoteService();}
 public function index(){$this->guard('fiscal_invoices_view');return$this->template->rander('credit_notes/index',['configure_pdf_allowed'=>$this->allowed('fiscal_pdf_templates_manage')]);}
 public function list_data(){$this->guard('fiscal_invoices_view');$db=db_connect();$rows=[];foreach($db->table('fiscal_credit_notes n')->select('n.*,i.display_id,cl.company_name,d.status fiscal_status,d.issuer_profile_id,s.uuid,s.stamped_xml_artifact_id')->join('invoices i','i.id=n.source_invoice_id')->join('clients cl','cl.id=n.client_id')->join('fiscal_documents d','d.id=n.fiscal_document_id','left')->join('fiscal_document_stamps s','s.fiscal_document_id=d.id','left')->where('n.deleted',0)->orderBy('n.id','DESC')->get()->getResult()as$n){$status=$n->fiscal_status==='cancelled'?'Cancelada':match($n->status){'draft'=>'Borrador','ready'=>'Lista','stamped'=>'Timbrada',default=>$n->status};$actions='<a class="btn btn-default btn-sm" href="'.get_uri('credit_notes/'.$n->id).'">Ver</a>';if($n->status==='stamped'&&$n->fiscal_document_id){$actions.=' <a class="btn btn-default btn-sm" target="_blank" href="'.get_uri('fiscal/documents/'.$n->fiscal_document_id.'/pdf/preview').'">PDF</a> <a class="btn btn-default btn-sm" href="'.get_uri('fiscal/stamping/xml/download/'.$n->fiscal_document_id).'">XML</a>';if($n->uuid&&$n->stamped_xml_artifact_id&&$this->allowed('fiscal_pdf_generate')&&$this->allowed('fiscal.advanced.regenerate_pdf')){try{$template=(new FiscalPdfTemplateResolver())->resolve((int)$n->issuer_profile_id,(string)config('FiscalPdfProvider')->provider,'E')->templateCode;}catch(Throwable){$template='-';}$actions.=' '.js_anchor('Regenerar PDF',['class'=>'btn btn-warning btn-sm fiscal-regenerate-pdf','data-document-id'=>$n->fiscal_document_id,'data-document-label'=>'Nota #'.$n->id,'data-uuid'=>$n->uuid,'data-template'=>$template]);}if($n->fiscal_status==='stamped'&&$this->allowed('fiscal_invoices_cancel'))$actions.=' '.modal_anchor(get_uri('fiscal/invoices/cancel/form'),'Cancelar',['class'=>'btn btn-danger btn-sm','title'=>'Cancelar Nota de Crédito','data-post-document_id'=>$n->fiscal_document_id]);}$cancel=$n->fiscal_document_id?$db->table('fiscal_cancellation_requests')->where('fiscal_document_id',$n->fiscal_document_id)->orderBy('id','DESC')->get(1)->getRow():null;$rows[]=['#'.$n->id,esc($n->issue_date),esc($n->company_name),esc($n->display_id),to_currency($n->total),esc($n->uuid?:'-'),esc($status),esc($cancel->status??'No solicitada'),$actions];}return$this->response->setJSON(['data'=>$rows]);}
 public function create_form()
 {
  $this->guard('fiscal.drafts.create');
  $source=(int)($this->request->getGet('source_document_id')?:$this->request->getPost('source_document_id'));
  $clientId=0;
  if($source){$row=db_connect()->table('fiscal_documents d')->select('i.client_id')->join('invoices i','i.id=d.invoice_id')->where('d.id',$source)->get(1)->getRow();$clientId=(int)($row->client_id??0);}
  $clients=db_connect()->table('clients')->select('id,company_name,vat_number')->where('deleted',0)->orderBy('company_name')->get()->getResult();
  $documents=$clientId?$this->service()->eligibleDocuments($clientId):[];
  return$this->template->view('credit_notes/create',['clients'=>$clients,'documents'=>$documents,'selected'=>$source,'selected_client'=>$clientId]);
 }
 public function client_documents(int$clientId)
 {
  $this->guard('fiscal.drafts.create');$results=[];
  foreach($this->service()->eligibleDocuments($clientId)as$d)$results[]=['id'=>(int)$d->id,'text'=>trim($d->series.' '.$d->folio).' · '.$d->uuid.' · Disponible '.to_currency($d->credit_available)];
  return$this->response->setJSON(['success'=>true,'results'=>$results,'message'=>$results?'':'El cliente no tiene facturas acreditables.']);
 }
 public function create()
 {
  $this->guard('fiscal.drafts.create');
  try{$mode=(string)$this->request->getPost('creation_mode');$clientId=(int)$this->request->getPost('client_id');$source=(int)$this->request->getPost('source_document_id');
   if(!in_array($mode,['invoice','manual'],true))throw new \RuntimeException('Seleccione cómo crear la Nota de Crédito.');
   if(!$clientId)throw new \RuntimeException('Seleccione un cliente.');
   if(!$source)throw new \RuntimeException($mode==='manual'?'Seleccione la factura fiscal que quedará relacionada.':'Seleccione una factura relacionada.');
   $eligible=array_filter($this->service()->eligibleDocuments($clientId),fn($d)=>(int)$d->id===$source);
   if(!$eligible)throw new \RuntimeException('La factura no pertenece al cliente, no está vigente o ya no tiene importe acreditable.');
   $id=$this->service()->create($source,(int)$this->login_user->id,$mode!=='manual');
   return$this->response->setJSON(['success'=>true,'redirect_to'=>get_uri('credit_notes/'.$id)]);
  }catch(Throwable$e){return$this->response->setStatusCode(422)->setJSON(['success'=>false,'message'=>$e->getMessage()]);}
 } public function edit(int$id){$this->guard('fiscal_invoices_view');try{return$this->template->rander('credit_notes/edit',$this->service()->context($id)+['can_edit'=>$this->allowed('fiscal.drafts.edit'),'can_stamp'=>$this->allowed('fiscal.invoices.stamp'),'can_regenerate_pdf'=>$this->allowed('fiscal_pdf_generate')&&$this->allowed('fiscal.advanced.regenerate_pdf'),'configure_pdf_allowed'=>$this->allowed('fiscal_pdf_templates_manage')]);}catch(Throwable){throw PageNotFoundException::forPageNotFound();}}
 public function save(int$id)
 {
  $this->guard('fiscal.drafts.edit');
  try{$quantities=$this->request->getPost('quantities');if(!is_array($quantities)||!$quantities)throw new \RuntimeException('Capture al menos una cantidad a acreditar.');
   $this->service()->update($id,$quantities,(string)$this->request->getPost('issue_date'),(int)$this->login_user->id);$context=$this->service()->context($id);
   return$this->response->setJSON(['success'=>true,'message'=>'Nota de Crédito actualizada.','data'=>['total'=>$context['note']->total,'subtotal'=>$context['note']->subtotal,'taxes'=>$context['note']->transferred_tax_total,'available'=>$context['available']],'csrf'=>['name'=>csrf_token(),'hash'=>csrf_hash()]]);
  }catch(Throwable$e){return$this->response->setStatusCode(422)->setJSON(['success'=>false,'message'=>$e->getMessage(),'csrf'=>['name'=>csrf_token(),'hash'=>csrf_hash()]]);}
 } public function remove_item(int$id,int$itemId){$this->guard('fiscal.drafts.edit');try{$this->service()->removeItem($id,$itemId);return$this->response->setJSON(['success'=>true]);}catch(Throwable$e){return$this->response->setStatusCode(422)->setJSON(['success'=>false,'message'=>$e->getMessage()]);}}
 public function review(int$id){$this->guard('fiscal_invoices_view');try{return$this->template->view('credit_notes/review',$this->service()->context($id));}catch(Throwable){throw PageNotFoundException::forPageNotFound();}}
 public function preview(int$id){$this->guard('fiscal_invoices_view');try{return$this->template->view('credit_notes/preview',$this->service()->preview($id));}catch(Throwable$e){return$this->response->setStatusCode(422)->setBody('<div class="modal-body"><div class="alert alert-danger">'.esc($e->getMessage()).'</div></div>');}}
 public function stamp(int$id){$this->guard('fiscal.invoices.stamp');try{$r=$this->service()->stamp($id,(int)$this->login_user->id,true);return$this->response->setJSON($r+['message'=>$r['success']?'Nota de Crédito timbrada correctamente.':($r['message']??'PAC rechazó el documento.')]);}catch(Throwable$e){return$this->response->setStatusCode(422)->setJSON(['success'=>false,'message'=>$e->getMessage()]);}}
 private function allowed(string$p):bool{if($this->login_user->is_admin)return true;$a=is_array($this->login_user->permissions)?$this->login_user->permissions:(@unserialize((string)$this->login_user->permissions)?:[]);return(bool)get_array_value($a,$p);}
 private function guard(string$p):void{if(!$this->allowed($p))app_redirect('forbidden');}
}
