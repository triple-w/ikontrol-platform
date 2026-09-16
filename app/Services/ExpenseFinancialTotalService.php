<?php
namespace App\Services;
final class ExpenseFinancialTotalService
{
    public function __construct(private $db=null){$this->db??=db_connect();}
    public function total(mixed$subtotal,int$taxId=0,int$taxId2=0):string{$subtotal=FinancialMoney::positive($subtotal);$total=$subtotal;foreach(array_filter([$taxId,$taxId2])as$id){$tax=$this->db->table('taxes')->select('percentage')->where(['id'=>$id,'deleted'=>0])->get(1)->getRow();if($tax)$total=FinancialMoney::add($total,FinancialMoney::percent($subtotal,(string)$tax->percentage));}return$total;}
}
