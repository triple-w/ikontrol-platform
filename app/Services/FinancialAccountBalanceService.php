<?php
namespace App\Services;
final class FinancialAccountBalanceService
{
    public function __construct(private $db) {}
    public function balance(int $accountId): array
    {
        $a=$this->db->table('financial_accounts')->where('id',$accountId)->get(1)->getRow();
        $in=$this->db->table('financial_account_movements')->selectSum('amount','total')->where(['financial_account_id'=>$accountId,'direction'=>'in','is_active'=>1])->get()->getRow()->total??0;
        $out=$this->db->table('financial_account_movements')->selectSum('amount','total')->where(['financial_account_id'=>$accountId,'direction'=>'out','is_active'=>1])->get()->getRow()->total??0;
        $opening=FinancialMoney::fromDatabase($a->opening_balance??'0');$incoming=FinancialMoney::fromDatabase($in??'0');$outgoing=FinancialMoney::fromDatabase($out??'0');
        return ['opening_balance'=>$opening,'in'=>$incoming,'out'=>$outgoing,'current'=>FinancialMoney::subtract(FinancialMoney::add($opening,$incoming),$outgoing)];
    }
}
