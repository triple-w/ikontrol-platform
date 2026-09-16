<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use RuntimeException;

/** Canonical derived balance for a stamped income CFDI. */
final class FiscalInvoiceOutstandingBalanceService
{
    public function __construct(private $db = null) { $this->db ??= db_connect(); }

    public function breakdown(int $fiscalDocumentId, ?int $excludeComplementId = null): array
    {
        $document=$this->db->table('fiscal_documents')->select('id,total')->where('id',$fiscalDocumentId)->get(1)->getRow();
        if(!$document)throw new RuntimeException('La factura fiscal no existe.');
        $total=$this->money($document->total);$stamped=$this->stampedPayments($fiscalDocumentId);
        $credits=(new CreditNoteBalanceService($this->db))->creditedDocumentAmount($fiscalDocumentId);
        $outstanding=$this->nonNegative(FiscalDecimal::subtract(FiscalDecimal::subtract($total,$stamped),$credits));
        $reserved=$this->reservations($fiscalDocumentId,$excludeComplementId);
        return['total'=>$total,'stamped_payments'=>$stamped,'active_credit_notes'=>$credits,'outstanding'=>$outstanding,'reserved'=>$reserved,
            'available'=>$this->nonNegative(FiscalDecimal::subtract($outstanding,$reserved)),'installment_number'=>$this->stampedInstallments($fiscalDocumentId)+1];
    }

    public function getTotal(int$id):string{return$this->breakdown($id)['total'];}
    public function getStampedPayments(int$id):string{return$this->stampedPayments($id);}
    public function getActiveCreditNotes(int$id):string{return(new CreditNoteBalanceService($this->db))->creditedDocumentAmount($id);}
    public function getReservedAmount(int$id,?int$exclude=null):string{return$this->reservations($id,$exclude);}
    public function getOutstanding(int$id):string{return$this->breakdown($id)['outstanding'];}
    public function getAvailableForNewComplement(int$id,?int$exclude=null):string{return$this->breakdown($id,$exclude)['available'];}

    private function stampedPayments(int$id):string
    {
        $row=$this->db->table('payment_complement_documents d')->selectSum('d.amount_paid','total')->join('payment_complement_payments p','p.id=d.payment_complement_payment_id')
            ->join('payment_complements c','c.id=p.payment_complement_id')->join('fiscal_documents fd','fd.id=c.fiscal_document_id')
            ->where(['d.fiscal_document_id'=>$id,'d.deleted'=>0,'p.deleted'=>0,'c.deleted'=>0,'c.status'=>'stamped'])->where('fd.status !=','cancelled')->where('fd.cancelled_at',null)->get()->getRow();
        return$this->money($row->total??'0');
    }

    private function reservations(int$id,?int$exclude):string
    {
        $q=$this->db->table('payment_complement_documents d')->selectSum('d.amount_paid','total')->join('payment_complement_payments p','p.id=d.payment_complement_payment_id')
            ->join('payment_complements c','c.id=p.payment_complement_id')->where(['d.fiscal_document_id'=>$id,'d.deleted'=>0,'p.deleted'=>0,'c.deleted'=>0])->whereIn('c.status',['draft','complete_draft']);
        if($exclude)$q->where('c.id !=',$exclude);
        return$this->money($q->get()->getRow()->total??'0');
    }

    private function stampedInstallments(int$id):int
    {
        return$this->db->table('payment_complement_documents d')->join('payment_complement_payments p','p.id=d.payment_complement_payment_id')->join('payment_complements c','c.id=p.payment_complement_id')
            ->join('fiscal_documents fd','fd.id=c.fiscal_document_id')->where(['d.fiscal_document_id'=>$id,'d.deleted'=>0,'p.deleted'=>0,'c.deleted'=>0,'c.status'=>'stamped'])
            ->where('fd.status !=','cancelled')->where('fd.cancelled_at',null)->countAllResults();
    }

    private function nonNegative(string$v):string{return FiscalDecimal::micros($v)<0?'0.000000':$v;}
    private function money($v):string{return FiscalDecimal::format(FiscalDecimal::micros((string)$v));}
}
