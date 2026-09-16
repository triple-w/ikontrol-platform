<?php
declare(strict_types=1);
namespace App\Models\Fiscal;
use CodeIgniter\Model;
final class Fiscal_draft_audit_model extends Model
{
    protected $table = 'fiscal_draft_audit';
    protected $returnType = 'object';
    protected $useTimestamps = false;
    protected $allowedFields = ['fiscal_draft_id','sale_id','user_id','event','summary_json','created_at'];
}
