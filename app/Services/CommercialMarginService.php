<?php
declare(strict_types=1);

namespace App\Services;

use App\Services\Fiscal\FiscalDecimal;
use InvalidArgumentException;

final class CommercialMarginService
{
    public function normalize(mixed $value, string $label, bool $required=false): ?string
    {
        if($value===null||trim((string)$value)===''){if($required)throw new InvalidArgumentException("{$label} es obligatorio.");return null;}
        $value=$this->localized((string)$value);if(!preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,6})?$/',$value))throw new InvalidArgumentException("{$label} debe ser un decimal no negativo válido.");
        return FiscalDecimal::format(FiscalDecimal::micros($value));
    }
    public function priceFromMargin(string $cost,string $margin):string
    {
        $cost=$this->normalize($cost,'Costo',true);$margin=$this->normalize($margin,'Margen',true);if(FiscalDecimal::micros($margin)>=100000000)throw new InvalidArgumentException('El margen debe ser menor que 100%.');
        return FiscalDecimal::prorate($cost,'100.000000',FiscalDecimal::subtract('100.000000',$margin));
    }
    public function marginFromPrice(string $cost,string $price):string
    {
        $cost=$this->normalize($cost,'Costo',true);$price=$this->normalize($price,'Precio de venta',true);if(FiscalDecimal::micros($price)===0)return FiscalDecimal::micros($cost)===0?'0.000000':'0.000000';
        return FiscalDecimal::prorate(FiscalDecimal::subtract($price,$cost),'100.000000',$price);
    }
    public function priceOrigin(mixed $posted,?string $cost,?string $margin,string $price):string
    {
        if($posted!=='cost_margin'||$cost===null||$margin===null)return'manual';
        return FiscalDecimal::micros($this->priceFromMargin($cost,$margin))===FiscalDecimal::micros($price)?'cost_margin':'manual';
    }
    private function localized(string$value):string{$value=trim($value);if(str_contains($value,',')&&str_contains($value,'.'))return strrpos($value,',')>strrpos($value,'.')?str_replace(',','.',str_replace('.','',$value)):str_replace(',','',$value);return str_replace(',','.',$value);}
}
