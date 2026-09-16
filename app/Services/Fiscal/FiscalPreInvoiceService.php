<?php
declare(strict_types=1);
namespace App\Services\Fiscal;
use RuntimeException;
final class FiscalPreInvoiceService
{
    public function __construct(private mixed$db=null){$this->db??=db_connect();}
    public function build(int$draftId):array{
        $draft=$this->db->table('fiscal_drafts')->where('id',$draftId)->get(1)->getRowArray();
        if(!$draft)throw new RuntimeException('FISCAL_DRAFT_NOT_FOUND');
        $payload=json_decode((string)$draft['fiscal_payload'],true,512,JSON_THROW_ON_ERROR);
        $sales=$this->db->table('fiscal_draft_sales a')->select('a.sale_id,a.allocated_subtotal,a.allocated_tax,a.allocated_total,i.display_id')
            ->join('invoices i','i.id=a.sale_id','left')->where(['a.fiscal_draft_id'=>$draftId,'a.allocation_status'=>'reserved'])->get()->getResultArray();
        if(!$sales)$sales=$this->db->table('fiscal_draft_sales a')->select('a.sale_id,a.allocated_subtotal,a.allocated_tax,a.allocated_total,i.display_id')
            ->join('invoices i','i.id=a.sale_id','left')->where('a.fiscal_draft_id',$draftId)->get()->getResultArray();
        $issuer=$this->db->table('fiscal_profiles')->where('id',$draft['issuer_id'])->get(1)->getRowArray()?:[];
        $receiver=$this->db->table('fiscal_profiles')->where('id',$draft['receiver_profile_id']??0)->get(1)->getRowArray()?:[];
        $items=$this->db->table('fiscal_draft_items')->where('fiscal_draft_id',$draftId)->orderBy('id')->get()->getResultArray();
        $transferred=$withheld='0.000000';foreach($items as&$item){$item['snapshot']=json_decode((string)$item['fiscal_snapshot'],true)?:[];$item['taxes']=$this->db->table('fiscal_draft_item_taxes')->where('fiscal_draft_item_id',$item['id'])->orderBy('calculation_order')->get()->getResultArray();foreach($item['taxes']as$tax){if($tax['tax_type']==='withholding')$withheld=FiscalDecimal::add($withheld,(string)$tax['tax_amount']);else$transferred=FiscalDecimal::add($transferred,(string)$tax['tax_amount']);}}
        return['legend'=>'PREFACTURA','validity_notice'=>'DOCUMENTO SIN VALIDEZ FISCAL','draft'=>$draft,
            'issuer'=>$issuer?:($payload['issuer']??[]),'receiver'=>$receiver?:($payload['receiver']??[]),'sales'=>$sales,
            'concepts'=>$items?:($payload['concepts']??[]),'taxes'=>$payload['taxes']??[],
            'subtotal'=>$draft['subtotal'],'discount'=>$draft['discount'],'transferred'=>$transferred,'withheld'=>$withheld,'total'=>$draft['total'],
            'payment_form'=>$draft['payment_form_code'],'payment_method'=>$draft['payment_method_code'],
            'cfdi_use'=>$draft['cfdi_use_code'],'issue_date'=>$draft['issue_date'],
            'observations'=>$payload['observations']??''];
    }
}
