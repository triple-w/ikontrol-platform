<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

/** Resolves the one operational fiscal attempt owned by a sale. */
final class FiscalSaleAttemptResolver
{
    private const REUSABLE_STATUSES = ['draft', 'ready', 'error', 'stamping', 'blocked', 'stamped'];

    public function __construct(private mixed $db = null)
    {
        $this->db ??= db_connect();
    }

    public function find(int $saleId): ?object
    {
        if ($saleId < 1) return null;
        return $this->db->table('fiscal_drafts d')
            ->select('d.id,d.status,d.fiscal_document_id,a.allocation_status')
            ->join('fiscal_draft_sales a', 'a.fiscal_draft_id=d.id')
            ->where(['a.sale_id' => $saleId, 'd.data_origin' => 'operational'])
            ->whereIn('d.status', self::REUSABLE_STATUSES)
            ->orderBy('d.id', 'DESC')->get(1)->getRow();
    }

    public function draftId(int $saleId): ?int
    {
        $attempt = $this->find($saleId);
        return $attempt ? (int) $attempt->id : null;
    }
}
