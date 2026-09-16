<?php
namespace App\Models;
class Payment_allocations_model extends Crud_model
{
    protected $table=null;
    public function __construct(){ $this->table='payment_allocations'; parent::__construct($this->table); }
    public function forPayment(int $id):array{return $this->get_all_where(['invoice_payment_id'=>$id,'deleted'=>0,'status'=>'active'])->getResult();}
    public function forSale(int $id):array{return $this->get_all_where(['invoice_id'=>$id,'deleted'=>0,'status'=>'active'])->getResult();}
}
