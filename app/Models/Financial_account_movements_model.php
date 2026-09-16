<?php
namespace App\Models;
class Financial_account_movements_model extends Crud_model
{
    protected $table = null;
    public function __construct() { $this->table = 'financial_account_movements'; parent::__construct($this->table); }
    public function findSource(string $type, int $id): ?object { return $this->get_one_where(['reference_type'=>$type,'reference_id'=>$id]); }
}
