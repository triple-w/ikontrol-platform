<?php
namespace App\Models\Fiscal;
use App\Models\Crud_model;
class Sale_fiscal_pricing_preparations_model extends Crud_model{
 public function __construct(){parent::__construct('sale_fiscal_pricing_preparations');}
 public function latestForInvoice(int$id){return$this->db->table($this->table)->where('invoice_id',$id)->orderBy('id','DESC')->get(1)->getRow();}
 public function supersedeActive(int$invoiceId):void{$this->db->table($this->table)->where('invoice_id',$invoiceId)->whereIn('status',['simulated','confirmation_required','confirmed'])->update(['status'=>'superseded','updated_at'=>get_current_utc_time()]);}
}
