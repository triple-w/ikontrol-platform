<?php
namespace App\Models\Fiscal;
use App\Models\Crud_model;
class Fiscal_documents_model extends Crud_model{
 public function __construct(){parent::__construct('fiscal_documents');}
 public function forInvoice(int$invoiceId){return$this->db->table($this->table)->where(['invoice_id'=>$invoiceId,'deleted'=>0])->orderBy('id','DESC')->get();}
 public function complete(int$id):array{
  $document=$this->db->table($this->table)->where(['id'=>$id,'deleted'=>0])->get(1)->getRow();
  if(!$document)return[];
  $items=$this->db->table('fiscal_document_items')->where('fiscal_document_id',$id)->orderBy('line_number')->get()->getResult();
  foreach($items as$item)$item->taxes=$this->db->table('fiscal_document_item_taxes')->where('fiscal_document_item_id',$item->id)->orderBy('sort_order')->get()->getResult();
  return['document'=>$document,'issuer'=>$this->db->table('fiscal_document_issuers')->where('fiscal_document_id',$id)->get(1)->getRow(),
   'receiver'=>$this->db->table('fiscal_document_receivers')->where('fiscal_document_id',$id)->get(1)->getRow(),'items'=>$items,
   'tax_totals'=>$this->db->table('fiscal_document_tax_totals')->where('fiscal_document_id',$id)->orderBy('tax_type')->orderBy('tax_code')->get()->getResult(),
   'metadata'=>$this->db->table('fiscal_document_metadata')->where('fiscal_document_id',$id)->get(1)->getRow()];
 }
}
