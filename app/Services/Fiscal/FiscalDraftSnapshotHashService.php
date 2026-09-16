<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

final class FiscalDraftSnapshotHashService
{
    private const OMIT = [
        'id'=>true, 'fiscal_document_id'=>true, 'fiscal_draft_id'=>true,
        'fiscal_draft_item_id'=>true, 'fiscal_payload'=>true, 'fiscal_snapshot'=>true,
        'source_snapshot_hash'=>true, 'status'=>true, 'allocation_status'=>true,
        'requires_snapshot_refresh'=>true, 'validation'=>true,
        'created_by'=>true, 'updated_by'=>true,
    ];

    public function hash(array $snapshot): string
    {
        $canonical = $this->canonicalize($snapshot);
        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function canonicalize(array $value): array
    {
        $result = [];
        foreach ($value as $key => $entry) {
            if (is_string($key) && ($this->omit($key))) continue;
            $result[$key] = is_array($entry) ? $this->canonicalize($entry) : $entry;
        }
        if (!array_is_list($result)) ksort($result, SORT_STRING);
        return $result;
    }

    private function omit(string $key): bool
    {
        return isset(self::OMIT[$key]) || str_ends_with($key, '_at') || str_ends_with($key, '_by');
    }
}