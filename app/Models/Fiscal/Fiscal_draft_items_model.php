<?php
declare(strict_types=1);
namespace App\Models\Fiscal;
use CodeIgniter\Model;
final class Fiscal_draft_items_model extends Model
{
    protected $table = 'fiscal_draft_items';
    protected $returnType = 'object';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'fiscal_draft_id','sale_id','sale_item_id','product_id','quantity','unit_price',
        'discount','subtotal','tax','total','fiscal_snapshot',
    ];
}
