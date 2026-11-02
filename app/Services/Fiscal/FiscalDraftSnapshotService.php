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
        $version = (int) ($draft['snapshot_version'] ?? 1);
        if ($version < 2 || (int) ($draft['requires_snapshot_refresh'] ?? 0) === 1) {
            throw new RuntimeException('FISCAL_DRAFT_SNAPSHOT_INCOMPLETE');
        }
        if ($version > 2) {
            throw new RuntimeException('FISCAL_DRAFT_SNAPSHOT_UNSUPPORTED');
        }
        $payload = $this->decodeSnapshot((string) ($draft['fiscal_payload'] ?? ''));
        $items = $this->db->table('fiscal_draft_items')->where('fiscal_draft_id', $id)->orderBy('id')->get()->getResultArray();
        if (!array_key_exists('issuer_snapshot', $payload) || !array_key_exists('receiver_snapshot', $payload)
            || $payload['issuer_snapshot'] === [] || $payload['receiver_snapshot'] === []) {
            throw new RuntimeException('FISCAL_DRAFT_SNAPSHOT_INCOMPLETE');
        }
        if (!is_array($payload['issuer_snapshot']) || !is_array($payload['receiver_snapshot'])) {
            throw new RuntimeException('FISCAL_DRAFT_SNAPSHOT_CORRUPT');
        }
        if (array_key_exists('series_snapshot', $payload) && !is_array($payload['series_snapshot'])) {
            throw new RuntimeException('FISCAL_DRAFT_SNAPSHOT_CORRUPT');
        }
        if (!$items) {
            throw new RuntimeException('FISCAL_DRAFT_SNAPSHOT_INCOMPLETE');
        }
        foreach ($items as &$item) {
            $item['snapshot'] = $this->decodeSnapshot((string) ($item['fiscal_snapshot'] ?? ''));
            $itemVersion = (int) ($item['snapshot']['snapshot_version'] ?? 0);
            if ($itemVersion > 2) {
                throw new RuntimeException('FISCAL_DRAFT_SNAPSHOT_UNSUPPORTED');
            }
            $item['taxes'] = $this->db->table('fiscal_draft_item_taxes')->where('fiscal_draft_item_id', $item['id'])->orderBy('calculation_order')->get()->getResultArray();
            $object = (string)($item['snapshot']['object_tax'] ?? $item['snapshot']['tax_object_code'] ?? '');
            if ($itemVersion < 2 || ($object !== '01' && !$item['taxes'])) {
                throw new RuntimeException('FISCAL_DRAFT_SNAPSHOT_INCOMPLETE');
            }
        }
        unset($item);
        try {
            $calculation = (new FiscalCanonicalCalculationService())->calculate($items);
        } catch (RuntimeException $exception) {
            throw new RuntimeException('FISCAL_DRAFT_SNAPSHOT_CORRUPT', 0, $exception);
        }
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

    private function decodeSnapshot(string $json): array
    {
        if (trim($json) === '') {
            throw new RuntimeException('FISCAL_DRAFT_SNAPSHOT_INCOMPLETE');
        }
        try {
            $snapshot = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('FISCAL_DRAFT_SNAPSHOT_CORRUPT', 0, $exception);
        }
        if (!is_array($snapshot)) {
            throw new RuntimeException('FISCAL_DRAFT_SNAPSHOT_CORRUPT');
        }
        return $snapshot;
    }
}