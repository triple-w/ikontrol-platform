<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use RuntimeException;

final class FiscalDraftSnapshotService
{
    public function __construct(private mixed $db = null)
    {
        $this->db ??= db_connect();
    }

    public function getCompleteFiscalSnapshot(int $id): array
    {
        $draft = $this->db->table('fiscal_drafts')->where('id', $id)->get(1)->getRowArray();
        if (!$draft) throw new RuntimeException('FISCAL_DRAFT_NOT_FOUND');
        if ((int)($draft['snapshot_version']??1) < 2 || (int)($draft['requires_snapshot_refresh']??0) === 1) {
            throw new RuntimeException('FISCAL_DRAFT_SNAPSHOT_INCOMPLETE');
        }
        $payload = json_decode((string)$draft['fiscal_payload'], true) ?: [];
        $items = $this->db->table('fiscal_draft_items')->where('fiscal_draft_id', $id)->orderBy('id')->get()->getResultArray();
        if (!$items || empty($payload['issuer_snapshot']) || empty($payload['receiver_snapshot'])) {
            throw new RuntimeException('FISCAL_DRAFT_SNAPSHOT_INCOMPLETE');
        }
        foreach ($items as &$item) {
            $item['snapshot'] = json_decode((string)$item['fiscal_snapshot'], true) ?: [];
            $item['taxes'] = $this->db->table('fiscal_draft_item_taxes')->where('fiscal_draft_item_id', $item['id'])->orderBy('calculation_order')->get()->getResultArray();
            $object = (string)($item['snapshot']['object_tax'] ?? $item['snapshot']['tax_object_code'] ?? '');
            if ((int)($item['snapshot']['snapshot_version']??0) < 2 || ($object !== '01' && !$item['taxes'])) {
                throw new RuntimeException('FISCAL_DRAFT_SNAPSHOT_INCOMPLETE');
            }
        }
        unset($item);
        $calculation = (new FiscalCanonicalCalculationService())->calculate($items);
        $allocations = $this->db->table('fiscal_draft_sales')->where('fiscal_draft_id', $id)->get()->getResultArray();
        $flatTaxes = [];
        foreach ($items as $item) $flatTaxes = array_merge($flatTaxes, $item['taxes']);
        return [
            'draft'=>$draft, 'items'=>$items, 'item_taxes'=>$flatTaxes,
            'totals'=>$calculation['totals'],
            'issuer_snapshot'=>$payload['issuer_snapshot'],
            'receiver_snapshot'=>$payload['receiver_snapshot'],
            'series_snapshot'=>$payload['series_snapshot']??[],
            'allocations'=>$allocations,
        ];
    }
}