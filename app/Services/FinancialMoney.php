<?php
namespace App\Services;
use InvalidArgumentException;
final class FinancialMoney
{
    public const SCALE=6;
    public static function normalize(mixed $value):string{$value=trim((string)$value);if(!preg_match('/^-?\d+(?:\.\d{1,6})?$/',$value))throw new InvalidArgumentException('El monto debe ser un decimal con máximo 6 decimales.');return bcadd($value,'0',self::SCALE);}
    /** Canonicalize DECIMAL/SUM output without silently losing precision. */
    public static function fromDatabase(mixed $value):string
    {
        $value=trim((string)$value);
        if(!preg_match('/^-?\d+(?:\.\d+)?$/',$value))throw new InvalidArgumentException('El monto persistido no es un decimal valido.');
        if(str_contains($value,'.')){
            [$integer,$fraction]=explode('.',$value,2);
            if(strlen($fraction)>self::SCALE){
                $extra=substr($fraction,self::SCALE);
                if(trim($extra,'0')!=='')throw new InvalidArgumentException('El monto persistido excede la precision financiera permitida.');
                $value=$integer.'.'.substr($fraction,0,self::SCALE);
            }
        }
        return self::normalize($value);
    }
    public static function positive(mixed $value):string{$amount=self::normalize($value);if(bccomp($amount,'0',self::SCALE)<=0)throw new InvalidArgumentException('El monto debe ser mayor que cero.');return$amount;}
    public static function add(mixed $left,mixed $right):string{return bcadd(self::normalize($left),self::normalize($right),self::SCALE);}
    public static function subtract(mixed $left,mixed $right):string{return bcsub(self::normalize($left),self::normalize($right),self::SCALE);}
    public static function percent(mixed $base,mixed $percentage):string{return bcdiv(bcmul(self::normalize($base),self::normalize($percentage),12),'100',self::SCALE);}
}
