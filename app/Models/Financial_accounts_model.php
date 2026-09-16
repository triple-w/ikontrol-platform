<?php
namespace App\Models;
class Financial_accounts_model extends Crud_model
{
    protected $table = null;
    public function __construct() { $this->table = 'financial_accounts'; parent::__construct($this->table); }
    public function get_active(): array { return $this->get_all_where(['deleted'=>0,'is_active'=>1],0,0,'name')->getResult(); }
}
