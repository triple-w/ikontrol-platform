<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use RuntimeException;

/** Keeps editable, unstamped sales aligned with the canonical commercial/fiscal result. */
final class CommercialSaleTotalConsistencyService
{
    public const MISMATCH = 'FISCAL_SALE_TOTAL_MISMATCH';

    public function __construct(private mixed $db = null) { $this->db ??= db_connect(); }

    public function assertConsistent(int $saleId, ?int $issuerId = null): array
    {
        $sale = $this->sale($saleId);
        $breakdown = (new CommercialTaxBreakdownService($this->db))->forSale($saleId, $issuerId);
        if (!$breakdown['ready']) throw new RuntimeException((string) ($breakdown['missing'][0] ?? 'Falta la configuración fiscal de una partida.'));
        if ($this->cents((string) $sale->invoice_total) !== $this->cents((string) $breakdown['total'])) throw new RuntimeException(self::MISMATCH);
        return $breakdown;
    }

    /** Returns canonical totals only for editable sales whose fiscal history is not stamped. */
    public function breakdownForEditableSale(int $saleId, ?int $issuerId = null): ?array
    {
        $sale = $this->sale($saleId);
        if ((string) ($sale->type ?? 'invoice') !== 'invoice') return null;
        if (!in_array((string) ($sale->commercial_status ?? 'open'), ['draft', 'open'], true)) return null;
        if (in_array((string) ($sale->status ?? ''), ['cancelled', 'credited'], true)) return null;
        if ($this->hasProtectedFiscalHistory($saleId)) return null;
        $items = $this->db->table('invoice_items')->select('id')->where(['invoice_id' => $saleId, 'deleted' => 0])->get()->getResult();
        if (!$items) return null;
        $breakdown = (new CommercialTaxBreakdownService($this->db))->forSale($saleId, $issuerId);
        return $breakdown['ready'] ? $breakdown : null;
    }

    public function synchronizeIfCanonical(int $saleId, ?int $issuerId = null): bool
    {
        $breakdown = $this->breakdownForEditableSale($saleId, $issuerId);
        if (!$breakdown) return false;
        $updated = $this->db->table('invoices')->where(['id' => $saleId, 'deleted' => 0])->update([
            'invoice_subtotal' => $breakdown['subtotal'],
            'discount_total' => $breakdown['discount'],
            'tax' => FiscalDecimal::subtract((string) $breakdown['transferred'], (string) $breakdown['withheld']),
            'tax2' => '0.000000',
            'tax3' => '0.000000',
            'invoice_total' => $breakdown['total'],
        ]);
        if (!$updated) throw new RuntimeException('No fue posible sincronizar los totales comerciales y fiscales.');
        return true;
    }

    public function hasProtectedFiscalHistory(int $saleId): bool
    {
        return (bool) $this->db->table('fiscal_document_sales a')
            ->join('fiscal_documents d', 'd.id=a.fiscal_document_id')
            ->where(['a.sale_id' => $saleId, 'a.allocation_status' => 'active', 'd.status' => 'stamped'])
            ->countAllResults();
    }

    private function sale(int $saleId): object
    {
        $sale = $this->db->table('invoices')->where(['id' => $saleId, 'deleted' => 0])->get(1)->getRow();
        if (!$sale) throw new RuntimeException('La venta no existe.');
        return $sale;
    }

    private function cents(string $amount): int
    {
        $micros = FiscalDecimal::micros($amount);
        return $micros >= 0 ? intdiv($micros + 5000, 10000) : -intdiv(abs($micros) + 5000, 10000);
    }
}