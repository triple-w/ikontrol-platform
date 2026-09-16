<?php
declare(strict_types=1);
namespace App\Services\Fiscal;
final class CreditNoteBalanceService{
 public function __construct(private$db=null){$this->db??=db_connect();}
 public function creditedDocumentAmount(int$id):string{$r=$this->db->table('fiscal_credit_notes n')->selectSum('n.total','amount')->join('fiscal_documents d','d.id=n.fiscal_document_id')->where(['n.source_fiscal_document_id'=>$id,'n.deleted'=>0,'n.status'=>'stamped'])->where('d.status !=','cancelled')->get()->getRow();return bcadd((string)($r->amount??'0'),'0',6);}
 public function creditedItemQuantity(int$id):string{$r=$this->db->table('fiscal_credit_note_items i')->selectSum('i.quantity','amount')->join('fiscal_credit_notes n','n.id=i.fiscal_credit_note_id')->join('fiscal_documents d','d.id=n.fiscal_document_id')->where(['i.source_fiscal_document_item_id'=>$id,'i.deleted'=>0,'n.deleted'=>0,'n.status'=>'stamped'])->where('d.status !=','cancelled')->get()->getRow();return bcadd((string)($r->amount??'0'),'0',6);}
 public function creditedSaleAmount(int$id):string{if(!$this->db->tableExists('fiscal_credit_notes'))return'0.000000';$r=$this->db->table('fiscal_credit_notes n')->selectSum('n.total','amount')->join('fiscal_documents d','d.id=n.fiscal_document_id')->where(['n.source_invoice_id'=>$id,'n.deleted'=>0,'n.status'=>'stamped'])->where('d.status !=','cancelled')->get()->getRow();return bcadd((string)($r->amount??'0'),'0',6);}
 public function paidAdministrative(int$id):string{$r=$this->db->table('payment_allocations')->selectSum('amount_applied','amount')->where(['invoice_id'=>$id,'status'=>'active','deleted'=>0])->get()->getRow();return bcadd((string)($r->amount??'0'),'0',6);}
 public function available(int$id):string{$d=$this->db->table('fiscal_documents')->where('id',$id)->get(1)->getRow();if(!$d)return'0.000000';$v=bcsub(bcsub((string)$d->total,$this->creditedDocumentAmount($id),6),$this->paidAdministrative((int)$d->invoice_id),6);return bccomp($v,'0',6)<0?'0.000000':$v;}
}