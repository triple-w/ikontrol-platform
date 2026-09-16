<?php
declare(strict_types=1);

namespace App\Services;

use App\Services\Fiscal\FiscalDecimal;
use CodeIgniter\Database\BaseConnection;

final class SupplierComparisonService
{
    private const VALID_STATUSES = ['sent', 'accepted', 'declined', 'not_paid', 'partially_paid', 'paid', 'open', 'closed'];

    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= db_connect('default');
    }

    public function compare(int $productId): array
    {
        $history = $this->db->table('product_supplier_cost_history h')
            ->select('h.id,h.product_id,h.supplier_id,h.source_type,h.source_id,h.source_item_id,h.source_folio,h.proposal_id,h.proposal_item_id,h.client_id,h.unit_cost,h.sale_unit_price,h.quantity,h.currency,h.quoted_at,h.source_status,s.name supplier_name,s.rfc supplier_rfc,s.status supplier_status,s.deleted supplier_deleted,c.company_name')
            ->join('suppliers s', 's.id=h.supplier_id')
            ->join('clients c', 'c.id=h.client_id')
            ->where('h.product_id', $productId)
            ->whereIn('h.source_status', self::VALID_STATUSES)
            ->where('h.unit_cost IS NOT NULL', null, false)
            ->orderBy('h.quoted_at', 'DESC')->orderBy('h.id', 'DESC')
            ->get()->getResultArray();

        $suppliers = [];
        $generalBest = null;
        foreach ($history as $row) {
            $supplierId = (int) $row['supplier_id'];
            if (!isset($suppliers[$supplierId])) {
                $suppliers[$supplierId] = [
                    'supplier_id' => $supplierId,
                    'supplier_name' => $row['supplier_name'],
                    'supplier_rfc' => $row['supplier_rfc'],
                    'active' => $row['supplier_status'] === 'active' && !(bool) $row['supplier_deleted'],
                    'last_cost' => $this->decimal($row['unit_cost']),
                    'best_cost' => $this->decimal($row['unit_cost']),
                    'last_date' => $row['quoted_at'],
                    'last_sale_unit_price' => $this->decimal($row['sale_unit_price']),
                    'quote_count' => 0,
                    'history' => [],
                ];
            }
            $suppliers[$supplierId]['quote_count']++;
            if (bccomp((string) $row['unit_cost'], $suppliers[$supplierId]['best_cost'], 6) < 0) {
                $suppliers[$supplierId]['best_cost'] = $this->decimal($row['unit_cost']);
            }
            $suppliers[$supplierId]['history'][] = [
                'date' => $row['quoted_at'], 'cost' => $this->decimal($row['unit_cost']),
                'source_type' => $row['source_type'] ?: 'proposal',
                'source_id' => (int) ($row['source_id'] ?: $row['proposal_id']),
                'source_item_id' => (int) ($row['source_item_id'] ?: $row['proposal_item_id']),
                'document_folio' => $row['source_folio'] ?: ('#' . ($row['source_id'] ?: $row['proposal_id'])),
                'proposal_id' => (int) $row['proposal_id'], 'proposal_public_key' => $row['source_type'] === 'proposal' ? $row['source_folio'] : null,
                'client_id' => (int) $row['client_id'], 'client_name' => $row['company_name'],
                'sale_unit_price' => $this->decimal($row['sale_unit_price']),
                'quantity' => $this->decimal($row['quantity']),
            ];
            if ($generalBest === null || bccomp((string) $row['unit_cost'], $generalBest, 6) < 0) {
                $generalBest = $this->decimal($row['unit_cost']);
            }
        }
        $rows = array_values($suppliers);
        $lowestLast = null;
        foreach ($rows as $row) {
            if ($lowestLast === null || bccomp($row['last_cost'], $lowestLast, 6) < 0) {
                $lowestLast = $row['last_cost'];
            }
        }
        foreach ($rows as &$row) {
            $row['is_latest'] = isset($history[0]) && (int) $history[0]['supplier_id'] === $row['supplier_id'];
            $row['is_lowest_last'] = $lowestLast !== null && bccomp($row['last_cost'], $lowestLast, 6) === 0;
            $row['is_best_historical'] = $generalBest !== null && bccomp($row['best_cost'], $generalBest, 6) === 0;
        }
        unset($row);
        return [
            'product_id' => $productId,
            'suppliers' => $rows,
            'indicators' => [
                'last_supplier' => $history[0]['supplier_name'] ?? null,
                'last_supplier_id' => isset($history[0]) ? (int) $history[0]['supplier_id'] : null,
                'last_cost' => isset($history[0]) ? $this->decimal($history[0]['unit_cost']) : null,
                'last_date' => $history[0]['quoted_at'] ?? null,
                'best_cost' => $generalBest,
                'supplier_count' => count($rows),
            ],
        ];
    }

    public function supplierForProduct(int $productId, int $supplierId): ?array
    {
        foreach ($this->compare($productId)['suppliers'] as $supplier) {
            if ($supplier['supplier_id'] === $supplierId) {
                return $supplier;
            }
        }
        return null;
    }

    public function variation(string $lastCost, string $currentCost): array
    {
        $last = $this->decimal($lastCost); $current = $this->decimal($currentCost);
        $difference = FiscalDecimal::subtract($current, $last);
        $percent = FiscalDecimal::micros($last) === 0 ? null : FiscalDecimal::prorate($difference, '100.000000', $last);
        return ['difference' => $difference, 'percent' => $percent];
    }

    private function decimal(mixed $value): string
    {
        return bcadd((string) $value, '0', 6);
    }
}
