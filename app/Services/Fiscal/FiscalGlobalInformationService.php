<?php
declare(strict_types=1);
namespace App\Services\Fiscal;
use RuntimeException;
final class FiscalGlobalInformationService
{
 public const PERIODICITIES=['01'=>'Diario','02'=>'Semanal','03'=>'Quincenal','04'=>'Mensual','05'=>'Bimestral'];
 public const MONTHS=['01'=>'Enero','02'=>'Febrero','03'=>'Marzo','04'=>'Abril','05'=>'Mayo','06'=>'Junio','07'=>'Julio','08'=>'Agosto','09'=>'Septiembre','10'=>'Octubre','11'=>'Noviembre','12'=>'Diciembre','13'=>'Enero-Febrero','14'=>'Marzo-Abril','15'=>'Mayo-Junio','16'=>'Julio-Agosto','17'=>'Septiembre-Octubre','18'=>'Noviembre-Diciembre'];
 public function isRequired(array|object $receiver,string $cfdiUse,string $documentType='I'):bool{$r=(array)$receiver;return strtoupper((string)($r['rfc']??''))==='XAXX010101000'&&$this->normalize((string)($r['legal_name']??''))==='PUBLICO EN GENERAL'&&strtoupper($documentType)==='I';}
 public function validate(array $values,array|object $receiver,string $cfdiUse,string $documentType,string $issueDate,array $operationDates=[],array|object $issuer=[]):array
 {
  $periodicity=trim((string)($values['global_periodicity']??''));$months=trim((string)($values['global_months']??''));$year=trim((string)($values['global_year']??''));$required=$this->isRequired($receiver,$cfdiUse,$documentType);$supplied=$periodicity!==''||$months!==''||$year!=='';
  if(!$required){if($supplied)throw new RuntimeException('Informacion Global solo aplica al CFDI de ingreso para PUBLICO EN GENERAL con RFC XAXX010101000.');return['required'=>false,'global_periodicity'=>null,'global_months'=>null,'global_year'=>null];}
  if(!$supplied||$periodicity===''||$months===''||$year==='')throw new RuntimeException('La factura global requiere Periodicidad, Meses y Anio del periodo real de las operaciones.');
  $r=(array)$receiver;$receiverRegime=(string)($r['tax_regime_code']??$r['receiver_tax_regime_code']??'');if($receiverRegime!=='616'||strtoupper($cfdiUse)!=='S01')throw new RuntimeException('PUBLICO EN GENERAL requiere regimen receptor 616 y uso CFDI S01 para la factura global.');
  if(!isset(self::PERIODICITIES[$periodicity]))throw new RuntimeException('La Periodicidad de la factura global no pertenece al catalogo SAT.');if(!isset(self::MONTHS[$months]))throw new RuntimeException('Meses de la factura global no pertenece al catalogo SAT.');if(($periodicity==='05')!==in_array($months,['13','14','15','16','17','18'],true))throw new RuntimeException('Periodicidad bimestral requiere un bimestre; las demas periodicidades requieren un mes 01 a 12.');
  $i=(array)$issuer;$issuerRegime=(string)($i['tax_regime_code']??$i['issuer_tax_regime_code']??'');if($periodicity==='05'&&$issuerRegime!=='621')throw new RuntimeException('Periodicidad bimestral 05 solo aplica cuando el emisor tributa en el regimen 621.');
  if(!preg_match('/^\d{4}$/',$year)||(int)$year<2019)throw new RuntimeException('El Anio de la factura global debe ser un anio SAT valido desde 2019.');$issueYear=(int)substr($issueDate,0,4);if($issueYear<2019||(int)$year>$issueYear)throw new RuntimeException('El Anio de la factura global no puede ser posterior a la fecha de expedicion.');$allowedMonths=$this->monthNumbers($months);
  foreach($operationDates as$date){$date=trim((string)$date);if($date==='')throw new RuntimeException('No fue posible determinar el periodo real de una venta incluida en la factura global.');if((int)substr($date,0,4)!==(int)$year||!in_array((int)substr($date,5,2),$allowedMonths,true))throw new RuntimeException('Las ventas seleccionadas no pertenecen al Anio y Meses indicados para la factura global.');}
  return['required'=>true,'global_periodicity'=>$periodicity,'global_months'=>$months,'global_year'=>(int)$year];
 }
 private function monthNumbers(string $code):array{if((int)$code<=12)return[(int)$code];$first=1+(((int)$code-13)*2);return[$first,$first+1];}
 private function normalize(string $value):string{$value=strtoupper(trim($value));$value=strtr($value,['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U']);return trim((string)preg_replace('/\s+/u',' ',$value));}
}
