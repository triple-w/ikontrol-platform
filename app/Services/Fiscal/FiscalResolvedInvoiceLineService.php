<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

/** Canonical backend resolution used by review and persistent draft creation. */
final class FiscalResolvedInvoiceLineService
{
    public function __construct(private mixed $db=null){$this->db??=db_connect();}

    public function resolve(object $item,string $quantity,string $discount,?int $issuerId=null):array
    {
        $calculated=(new CommercialItemTaxResolver($this->db))->invoiceItem((int)$item->id,$quantity,(string)$item->rate,$discount,$issuerId);
        $setting=(array)($calculated['setting']??[]);
        $snapshot=[
            'title'=>(string)$item->title,'description'=>(string)($item->description??''),
            'fiscal_description'=>(string)($calculated['fiscal_description']??$setting['fiscal_description']??$item->description??$item->title),
            'product_service_code'=>(string)($calculated['product_service_code']??$setting['product_service_code']??''),
            'unit_code'=>(string)($calculated['unit_code']??$setting['unit_code']??''),
            'commercial_unit'=>(string)($calculated['commercial_unit']??$setting['commercial_unit']??$item->unit_type),
            'tax_object_code'=>(string)($calculated['tax_object_code']??$setting['tax_object_code']??''),
            'object_tax'=>(string)($calculated['tax_object_code']??$setting['tax_object_code']??''),
            'pricing_mode'=>(string)($calculated['pricing_mode']??'tax_inclusive'),
            'tax_source'=>(string)($calculated['source']??'none'),'taxes'=>(array)($calculated['calculated_taxes']??[]),
            'snapshot_version'=>2,'subtotal_before_tax'=>FiscalDecimal::add((string)$calculated['base'],$discount),
            'discount'=>$discount,'taxable_base'=>(string)$calculated['base'],
            'transferred_total'=>(string)$calculated['transfers'],'withheld_total'=>(string)$calculated['withholdings'],
            'concept_total'=>(string)$calculated['total'],
        ];
        return ['ready'=>(bool)$calculated['ready'],'source'=>(string)($calculated['source']??'none'),'blockers'=>(array)($calculated['blockers']??[]),
            'sale_item_id'=>(int)$item->id,'product_id'=>(int)$item->item_id,'quantity'=>$quantity,
            'unit_price'=>FiscalDecimal::format(FiscalDecimal::micros((string)$item->rate)),'discount'=>$discount,
            'subtotal'=>$snapshot['subtotal_before_tax'],'tax'=>FiscalDecimal::subtract($snapshot['transferred_total'],$snapshot['withheld_total']),
            'total'=>$snapshot['concept_total'],'snapshot'=>$snapshot,'taxes'=>$snapshot['taxes']];
    }
}
