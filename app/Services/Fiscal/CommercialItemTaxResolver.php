<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use RuntimeException;

/** Fuente canónica para resolver y calcular impuestos de una partida comercial. */
final class CommercialItemTaxResolver
{
    public function __construct(private mixed $db = null)
    {
        $this->db ??= db_connect();
    }

    public function invoiceItem(int $invoiceItemId, string $quantity, string $unitPrice, string $discount = '0.000000', ?int $issuerId = null): array
    {
        $resolved = (new InvoiceItemTaxResolver($this->db))->resolve($invoiceItemId, $issuerId);
        return $this->calculate($resolved, $quantity, $unitPrice, $discount, $invoiceItemId);
    }

    public function product(int $productId, int $companyId, string $quantity, string $unitPrice, string $discount = '0.000000', ?int $issuerId = null, ?array $override = null, string $priceOrigin = 'manual'): array
    {
        $normalized=(new FiscalItemOverrideContract())->normalizeStored($override,$productId);
        if($normalized&&$normalized['ready'])return $this->calculate($this->pricing(['source'=>'item_override']+$normalized,$priceOrigin),$quantity,$unitPrice,$discount,0);
        $override=null; // invalid/partial stored flags are never trusted; product is the fallback.
        if ($productId <= 0) {
            return $this->notReady('manual_line', $normalized['missing']??['La partida libre requiere configuración fiscal propia.'],$normalized??[]);
        }
        if($override && !empty($override['ready'])){$issuer=$issuerId?$this->db->table('fiscal_profiles')->where('id',$issuerId)->get(1)->getRow():(new FiscalIssuerResolver($this->db))->resolve($companyId,config('Fiscal')->environment);$mode=(string)($issuer->tax_pricing_mode??'tax_inclusive');$resolved=array_merge($override,['source'=>'item_override','prices_include_tax'=>in_array($mode,['tax_inclusive','preserve_total'],true),'pricing_mode'=>$mode]);}else{$resolved=(new InvoiceItemTaxResolver($this->db))->resolveProduct($productId,$companyId,$issuerId);}
        return $this->calculate($this->pricing($resolved,$priceOrigin), $quantity, $unitPrice, $discount, 0);
    }

    private function pricing(array $resolved,string $origin):array{if($origin==='cost_margin'){$resolved['pricing_mode']='tax_exclusive';$resolved['prices_include_tax']=false;}$resolved['price_origin']=$origin==='cost_margin'?'cost_margin':'manual';return$resolved;}

    private function calculate(array $resolved, string $quantity, string $unitPrice, string $discount, int $invoiceItemId): array
    {
        if (empty($resolved['ready'])) {
            return $this->notReady((string) ($resolved['source'] ?? 'none'), (array) ($resolved['missing'] ?? ['Configuración fiscal incompleta.']), $resolved);
        }

        $gross = FiscalDecimal::multiply($quantity, $unitPrice);
        if (FiscalDecimal::micros($discount) < 0 || FiscalDecimal::micros($discount) > FiscalDecimal::micros($gross)) {
            throw new RuntimeException('El descuento de la partida no es válido.');
        }
        $net = FiscalDecimal::subtract($gross, $discount);
        $concept = [
            'sale_item_id' => $invoiceItemId,
            'quantity' => $quantity,
            'subtotal' => $gross,
            'discount' => $discount,
            'total' => $net,
            'snapshot' => [
                'tax_object_code' => (string) ($resolved['tax_object_code'] ?? ''),
                'taxes' => (array) ($resolved['taxes'] ?? []),
            ],
        ];
        $pure = (new CommercialItemTaxCalculator())->calculate($quantity,$unitPrice,$discount,(string)($resolved['pricing_mode']??'tax_inclusive'),(string)($resolved['tax_object_code']??$resolved['setting']['tax_object_code']??''),(array)($resolved['taxes']??[]));

        return array_merge($resolved, [
            'source' => (string) ($resolved['source'] ?? 'none'),
            'prices_include_tax' => !empty($resolved['prices_include_tax']),
            ...$pure,
            'ready' => true,
            'blockers' => [],
        ]);
    }

    private function notReady(string $source, array $blockers, array $resolved = []): array
    {
        return array_merge($resolved, [
            'source' => $source,
            'prices_include_tax' => (bool) ($resolved['prices_include_tax'] ?? true),
            'gross_price' => '0.000000', 'base' => '0.000000',
            'transfers' => '0.000000', 'withholdings' => '0.000000',
            'discount' => '0.000000', 'total' => '0.000000',
            'ready' => false, 'blockers' => array_values(array_unique($blockers)),
        ]);
    }
}
