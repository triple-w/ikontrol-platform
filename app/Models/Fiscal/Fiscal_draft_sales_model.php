<?php
declare(strict_types=1);
namespace App\Models\Fiscal;
use CodeIgniter\Model;
final class Fiscal_draft_sales_model extends Model
{
    protected $table = 'fiscal_draft_sales';
    protected $returnType = 'object';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'fiscal_draft_id','sale_id','allocated_subtotal','allocated_tax','allocated_total',
        'allocation_status','created_by',
    ];
    protected $validationRules = [
        'allocation_status' => 'required|in_list[reserved,released,converted,discarded]',
    ];
}
