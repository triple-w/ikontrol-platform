<?php
declare(strict_types=1);
namespace App\Models\Fiscal;
use CodeIgniter\Model;
final class Fiscal_document_relations_model extends Model
{
    protected $table = 'fiscal_document_relations';
    protected $returnType = 'object';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'source_document_id','related_document_id','relation_type','created_by',
    ];
}
