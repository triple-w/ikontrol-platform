<?php
declare(strict_types=1);
namespace App\Services\Fiscal;

use Throwable;

final class FiscalDraftValidationService
{
    public function __construct(private mixed $db = null, private ?FiscalIssueDatePolicy $dates = null)
    {
        $this->db ??= db_connect();
        $this->dates ??= new FiscalIssueDatePolicy();
    }

    public function validate(array $draft, array $allocations, array $concepts): array
    {
        $errors = [];
        $warnings = [];
        $add = static function (array &$target, string $field, string $code, string $message, string $section): void {
            $target[] = compact('field','code','message','section');
        };
        $issuer = $this->db->table('fiscal_profiles')->where([
            'id' => (int) ($draft['issuer_id'] ?? 0), 'profile_type' => 'issuer',
        ])->whereIn('status',['active','ready'])->get(1)->getRow();
        if (!$issuer) $add($errors,'issuer_id','ISSUER_REQUIRED','Selecciona un emisor fiscal activo.','issuer');
        if ($issuer) {
            foreach (['rfc'=>'RFC','legal_name'=>'razón social','tax_regime_id'=>'régimen fiscal','expedition_postal_code'=>'código postal de expedición'] as $field=>$label) {
                if (trim((string) ($issuer->{$field} ?? '')) === '') $add($errors,$field,'ISSUER_FIELD_REQUIRED',"Falta {$label} del emisor.",'issuer');
            }
            $certificate = $this->db->table('fiscal_issuer_certificates')->where([
                'issuer_profile_id' => (int) $issuer->id, 'deleted' => 0,
            ])->whereIn('status',['active','valid'])->where('valid_from <=', date('Y-m-d H:i:s'))->where('valid_to >=', date('Y-m-d H:i:s'))->get(1)->getRow();
            if (!$certificate) $add($errors,'certificate','CSD_NOT_USABLE','El emisor no tiene un CSD vigente y utilizable.','issuer');
        }
        $receiver = $this->db->table('fiscal_profiles')->where([
            'id' => (int) ($draft['receiver_profile_id'] ?? 0), 'profile_type' => 'receiver',
        ])->whereIn('status',['active','ready'])->get(1)->getRow();
        if (!$receiver) {
            $add($errors,'receiver_profile_id','RECEIVER_REQUIRED','Faltan los datos fiscales del receptor.','receiver');
        } else {
            foreach ([
                'rfc'=>'RFC del receptor','legal_name'=>'razón social del receptor',
                'tax_regime_id'=>'régimen fiscal del receptor','fiscal_postal_code'=>'código postal fiscal',
            ] as $field=>$label) {
                if (trim((string) ($receiver->{$field} ?? '')) === '' || (str_ends_with($field, '_id') && (int)$receiver->{$field} <= 0)) {
                    $add($errors,$field,'RECEIVER_FIELD_REQUIRED',"Falta {$label}.",'receiver');
                }
            }
        }
        foreach (['payment_form_code'=>'forma de pago','payment_method_code'=>'método de pago','currency_code'=>'moneda'] as $field=>$label) {
            if (trim((string) ($draft[$field] ?? '')) === '') $add($errors,$field,'COMPROBANTE_FIELD_REQUIRED',"Falta {$label}.",'document');
        }
        if(trim((string)($draft['cfdi_use_code']??''))==='')$add($errors,'cfdi_use_code','CFDI_USE_REQUIRED','Falta Uso CFDI.','document');
        if(trim((string)($draft['payment_form_code']??''))!==''&&trim((string)($draft['payment_method_code']??''))!==''){
            try{(new CfdiPaymentRuleService($this->db))->validate((string)$draft['payment_method_code'],(string)$draft['payment_form_code']);}
            catch(Throwable$e){$add($errors,'payment','CFDI_PAYMENT_COMBINATION_INVALID',$e->getMessage(),'document');}
        }
        if (($draft['currency_code'] ?? 'MXN') !== 'MXN') {
            try {
                if (FiscalDecimal::micros((string) ($draft['exchange_rate'] ?? '0')) <= 0) throw new \RuntimeException();
            } catch (Throwable) {
                $add($errors,'exchange_rate','EXCHANGE_RATE_REQUIRED','Captura un tipo de cambio válido.','document');
            }
        }
        try {
            $this->dates->validate((string) ($draft['issue_date'] ?? ''));
        } catch (Throwable $e) {
            $message = match ($e->getMessage()) {
                'FISCAL_ISSUE_DATE_REQUIRED' => 'La fecha de expediciÃ³n es obligatoria.',
                'FISCAL_ISSUE_DATE_FUTURE' => 'La fecha de expedición no puede estar en el futuro.',
                'FISCAL_ISSUE_DATE_TOO_OLD' => 'La fecha de expedición excede la antigüedad permitida.',
                default => 'La fecha de expedición no tiene un formato válido.',
            };
            $add($errors,'issue_date',$e->getMessage(),$message,'document');
        }
        if (!$allocations) $add($errors,'sales','SALES_REQUIRED','Selecciona al menos una venta con saldo disponible.','sales');
        if (!$concepts) $add($errors,'concepts','CONCEPTS_REQUIRED','Selecciona al menos un concepto.','concepts');
        foreach ($concepts as $concept) {
            try {
                if (FiscalDecimal::micros((string) ($concept['quantity'] ?? '0')) <= 0) throw new \RuntimeException();
                if (FiscalDecimal::micros((string) ($concept['total'] ?? '0')) <= 0) throw new \RuntimeException();
            } catch (Throwable) {
                $add($errors,'concepts','CONCEPT_INVALID','Las cantidades e importes de los conceptos deben ser mayores que cero.','concepts');
            }
            $snapshot = $concept['snapshot'] ?? [];
            if(trim((string)($snapshot['fiscal_description']??''))==='')$add($errors,'fiscal_description','CONCEPT_FISCAL_DESCRIPTION_REQUIRED','Falta descripción fiscal en un concepto.','concepts');
            foreach (['product_service_code','unit_code','tax_object_code'] as $field) {
                if (trim((string) ($snapshot[$field] ?? '')) === '') {
                    $add($errors,$field,'CONCEPT_FISCAL_DATA_REQUIRED','Un concepto no tiene configuración fiscal completa.','concepts');
                }
            }
            if((int)($snapshot['snapshot_version']??0)<2){
                $add($errors,'snapshot_version','FISCAL_DRAFT_SNAPSHOT_INCOMPLETE','El borrador debe editarse y guardarse nuevamente antes de facturarse.','concepts');
                continue;
            }
            $object=(string)($snapshot['object_tax']??$snapshot['tax_object_code']??'');$taxes=(array)($snapshot['taxes']??[]);
            if($object!=='01'&&!$taxes)$add($errors,'taxes','TAX_BREAKDOWN_REQUIRED','Falta el desglose de impuestos del concepto.','concepts');
            $transfer=$withheld='0.000000';
            foreach($taxes as$tax){
                if(!in_array($tax['tax_type']??'', ['transfer','withholding'],true)||!preg_match('/^\d{3}$/',(string)($tax['tax_code']??''))||!in_array($tax['factor_type']??'',['Tasa','Cuota','Exento'],true)){
                    $add($errors,'taxes','TAX_CONFIGURATION_INVALID','La tasa del impuesto no es válida.','concepts');continue;
                }
                try{$base=(string)($tax['tax_base']??'');if(FiscalDecimal::micros($base)<0)throw new \RuntimeException();$factor=$tax['factor_type'];$amount=(string)($tax['tax_amount']??'0');if($factor==='Tasa')$expected=FiscalDecimal::multiply($base,(string)($tax['rate_or_quota']??''));elseif($factor==='Cuota')$expected=FiscalDecimal::multiply((string)$concept['quantity'],(string)($tax['rate_or_quota']??''));else$expected='0.000000';if(FiscalDecimal::micros($expected)!==FiscalDecimal::micros($amount))throw new \RuntimeException();if(($tax['tax_type']??'')==='withholding')$withheld=FiscalDecimal::add($withheld,$amount);else$transfer=FiscalDecimal::add($transfer,$amount);}catch(Throwable){$add($errors,'tax_amount','TAX_AMOUNT_MISMATCH','El importe del impuesto no coincide con la base y la tasa.','concepts');}
            }
            try{$expected=FiscalDecimal::subtract(FiscalDecimal::add((string)$snapshot['taxable_base'],$transfer),$withheld);if(FiscalDecimal::micros($expected)!==FiscalDecimal::micros((string)$concept['total']))$add($errors,'total','CONCEPT_TOTAL_MISMATCH','El total del concepto no coincide con sus impuestos.','concepts');}catch(Throwable){$add($errors,'total','CONCEPT_TOTAL_INVALID','El total del concepto no es válido.','concepts');}
        }
        return ['valid' => !$errors, 'errors' => $errors, 'warnings' => $warnings];
    }
}
