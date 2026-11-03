<?php
declare(strict_types=1);
namespace App\Services\Reports;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\ResultInterface;
use InvalidArgumentException;

final class SalesItemDetailReportService
{
    private const ORDER=['quantity'=>'quantity','item'=>'item_title','rate'=>'rate','total'=>'line_total','remission'=>'invoice_id','client'=>'client_name','collection_date'=>'collection_date','due_date'=>'due_date','vat'=>'vat_gst','status'=>'payment_status'];
    public function __construct(private BaseConnection$db){}
    public function page(array$options):array
    {
        [$inner,$params,$outer]=$this->statement($options);$order=self::ORDER[(string)($options['order_by']??'')]??'invoice_id';$direction=strtoupper((string)($options['order_dir']??'DESC'))==='ASC'?'ASC':'DESC';$limit=max(1,min(100,(int)($options['limit']??25)));$skip=max(0,(int)($options['skip']??0));
        $query=$this->db->query("SELECT * FROM ({$inner}) report_rows {$outer} ORDER BY {$order} {$direction},line_id ASC LIMIT {$limit} OFFSET {$skip}",$params);
        $count=(int)($this->db->query("SELECT COUNT(*) total FROM ({$inner}) report_rows {$outer}",$params)->getRow()->total??0);
        $totals=$this->db->query("SELECT COALESCE(SUM(quantity),0) quantity_total,COALESCE(SUM(line_total),0) amount_total,COUNT(DISTINCT invoice_id) remission_count FROM ({$inner}) report_rows {$outer}",$params)->getRowArray();
        return['data'=>$query->getResult(),'recordsTotal'=>$count,'recordsFiltered'=>$count,'summation'=>['quantity_total'=>$totals['quantity_total']??0,'amount_total'=>$totals['amount_total']??0,'remission_count'=>(int)($totals['remission_count']??0)]];
    }
    public function export(array$options):ResultInterface
    {
        [$inner,$params,$outer]=$this->statement($options);return$this->db->query("SELECT * FROM ({$inner}) report_rows {$outer} ORDER BY bill_date ASC,invoice_id ASC,line_id ASC",$params);
    }
    private function statement(array$options):array
    {
        $i=$this->q('invoices');$ii=$this->q('invoice_items');$c=$this->q('clients');$pa=$this->q('payment_allocations');$p=$this->q('invoice_payments');$params=[];$where=["i.deleted=0","ii.deleted=0","i.type='invoice'","i.status NOT IN ('draft','cancelled')","COALESCE(i.commercial_status,'open')<>'cancelled'"];
        foreach(['start_date'=>'>=','end_date'=>'<=']as$key=>$operator)if(($value=$this->date($options[$key]??null))!==null){$where[]="i.bill_date {$operator} ?";$params[]=$value;}
        foreach(['client_id'=>'i.client_id','item_id'=>'ii.item_id']as$key=>$field)if((int)($options[$key]??0)>0){$where[]="{$field}=?";$params[]=(int)$options[$key];}
        if((int)($options['own_creator']??0)>0){$where[]='i.created_by=?';$params[]=(int)$options['own_creator'];}
        if((int)($options['own_client_owner']??0)>0){$where[]='c.owner_id=?';$params[]=(int)$options['own_client_owner'];}
        if(($term=trim((string)($options['remission']??'')))!==''){$where[]="(i.display_id LIKE ? OR CAST(i.id AS CHAR) LIKE ?)";$params[]='%'.$term.'%';$params[]='%'.$term.'%';}
        if(($term=trim((string)($options['search_by']??'')))!==''){$where[]="(ii.title LIKE ? OR c.company_name LIKE ? OR i.display_id LIKE ? OR CAST(i.id AS CHAR) LIKE ?)";for($n=0;$n<4;$n++)$params[]='%'.$term.'%';}
        if((int)($options['payment_method_id']??0)>0){$where[]="EXISTS(SELECT 1 FROM {$pa} paf JOIN {$p} pf ON pf.id=paf.invoice_payment_id WHERE paf.invoice_id=i.id AND paf.deleted=0 AND paf.status='active' AND pf.deleted=0 AND pf.status='active' AND pf.payment_method_id=?)";$params[]=(int)$options['payment_method_id'];}
        $payments="SELECT pa.invoice_id,SUM(pa.amount_applied) paid_amount,MAX(pa.allocation_date) last_payment_date FROM {$pa} pa JOIN {$p} p ON p.id=pa.invoice_payment_id WHERE pa.deleted=0 AND pa.status='active' AND p.deleted=0 AND p.status='active' GROUP BY pa.invoice_id";
        $creditJoin='LEFT JOIN (SELECT NULL invoice_id,0 credited_amount) credits ON 1=0';if($this->db->tableExists('fiscal_credit_notes')&&$this->db->tableExists('fiscal_documents')){$n=$this->q('fiscal_credit_notes');$d=$this->q('fiscal_documents');$creditJoin="LEFT JOIN (SELECT n.source_invoice_id invoice_id,SUM(n.total) credited_amount FROM {$n} n JOIN {$d} d ON d.id=n.fiscal_document_id WHERE n.deleted=0 AND n.status='stamped' AND d.deleted=0 AND d.status<>'cancelled' GROUP BY n.source_invoice_id) credits ON credits.invoice_id=i.id";}
        $status="CASE WHEN i.status='credited' THEN 'credited' WHEN COALESCE(pay.paid_amount,0)+COALESCE(credits.credited_amount,0)>=i.invoice_total-0.005 THEN 'paid' WHEN COALESCE(pay.paid_amount,0)+COALESCE(credits.credited_amount,0)>0 THEN 'partially_paid' ELSE 'not_paid' END";
        $inner="SELECT ii.id line_id,ii.quantity,ii.title item_title,ii.item_id,ii.rate,ii.total line_total,i.id invoice_id,i.display_id,i.bill_date,i.due_date,i.invoice_total,i.client_id,c.company_name client_name,COALESCE(NULLIF(c.vat_number,''),NULLIF(c.gst_number,''),'') vat_gst,c.currency_symbol,CASE WHEN COALESCE(pay.paid_amount,0)>=i.invoice_total-0.005 THEN pay.last_payment_date ELSE NULL END collection_date,{$status} payment_status FROM {$ii} ii JOIN {$i} i ON i.id=ii.invoice_id LEFT JOIN {$c} c ON c.id=i.client_id LEFT JOIN ({$payments}) pay ON pay.invoice_id=i.id {$creditJoin} WHERE ".implode(' AND ',$where);
        $outer='WHERE 1=1';$statusFilter=(string)($options['payment_status']??'');if(in_array($statusFilter,['not_paid','partially_paid','paid','credited'],true)){$outer.=' AND payment_status='.$this->db->escape($statusFilter);}
        return[$inner,$params,$outer];
    }
    private function date(mixed$value):?string{$value=trim((string)$value);if($value==='')return null;$date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);if(!$date||$date->format('Y-m-d')!==$value)throw new InvalidArgumentException('El rango de fechas no es válido.');return$value;}
    private function q(string$table):string{return$this->db->protectIdentifiers($this->db->prefixTable($table));}
}
