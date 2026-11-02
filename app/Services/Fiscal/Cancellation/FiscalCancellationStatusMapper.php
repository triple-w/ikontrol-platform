<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Cancellation;

/** Normalizes only explicit SAT/PAC states; unrecognized values fail closed. */
final class FiscalCancellationStatusMapper
{
    public function map(string $operation, array $payload, int $httpStatus = 200): array
    {
        $flat = $this->normalize(implode(' ', $this->scalars($payload)));
        $providerCode = $this->providerCode($payload);
        if ($httpStatus === 0 || str_contains($flat, 'N-998') || str_contains($flat, 'INTERMITENCIA') || str_contains($flat, 'CONSULTARLO MAS TARDE')) {
            return $this->result('unknown', 'unknown', true, false);
        }
        if ($operation === 'cancelarPEM') {
            $folio = $this->folioStatus($payload);
            if (in_array($folio, ['203','204','205'], true) || str_contains($flat, 'RECHAZ')) return $this->result('rejected', 'active', false, true);
            if ($folio === '202' || str_contains($flat, 'CANCELADO') || str_contains($flat, 'CANCELADA')) return $this->result('cancelled', 'cancelled', false, false);
            if ($folio === '201' || $providerCode === '201' || str_contains($flat, 'SOLICITUD DE CANCELACION')) return $this->result('pending', 'active', false, false);
            return $this->result('unknown', 'unknown', true, false);
        }

        $estado = $this->normalize((string) ($payload['Estado'] ?? $payload['estado'] ?? $this->nested($payload, 'Estado')));
        $cancel = $this->normalize((string) ($payload['EstatusCancelacion'] ?? $payload['estatusCancelacion'] ?? $this->nested($payload, 'EstatusCancelacion')));
        $cancelable = $this->normalize((string) ($payload['EsCancelable'] ?? $payload['esCancelable'] ?? $this->nested($payload, 'EsCancelable')));
        if (str_contains($estado, 'CANCELADO')) return $this->result('cancelled', 'cancelled', false, false);
        if (str_contains($cancel, 'RECHAZ') || str_contains($cancel, 'NO ACEPT')) return $this->result('rejected', 'active', false, true);
        if (str_contains($cancel, 'PLAZO VIGENTE') || str_contains($cancel, 'PENDIENTE DE ACEPTACION')) return $this->result('pending_acceptance', 'active', false, false);
        if (str_contains($cancel, 'EN PROCESO') || $cancel === 'PENDIENTE') return $this->result('pending', 'active', false, false);
        if (str_contains($estado, 'VIGENTE') && $cancel === '') return $this->result('none', 'active', false, false);
        if (str_contains($estado, 'VIGENTE') && $cancelable !== '' && $cancel === '') return $this->result('none', 'active', false, false);
        return $this->result('unknown', 'unknown', true, false);
    }

    private function result(string $cancellation, string $fiscal, bool $reconcile, bool $retry): array
    {
        return ['cancellation_status'=>$cancellation,'fiscal_status'=>$fiscal,'requires_reconciliation'=>$reconcile,'retry_allowed'=>$retry,
            'service_status'=>match ($cancellation) {'cancelled'=>'accepted','pending','pending_acceptance'=>'pending','rejected'=>'rejected',default=>$fiscal==='active'?'active':'unknown'}];
    }
    private function nested(array $payload, string $key): string
    {
        $data=$payload['data']??null;
        if(is_string($data))$data=json_decode($data,true);
        return is_array($data)&&is_scalar($data[$key]??null)?(string)$data[$key]:'';
    }
    private function normalize(string $value): string
    {
        return strtr(mb_strtoupper(trim($value),'UTF-8'),['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N']);
    }
    private function providerCode(array $payload): string { return is_scalar($payload['code']??null)?trim((string)$payload['code']):''; }
    private function folioStatus(array $payload): string
    {
        $uuid=$payload['data']['uuid']??$payload['uuid']??null;
        if(is_array($uuid))foreach($uuid as$value)if(is_scalar($value)&&preg_match('/^20[1-5]$/',(string)$value))return(string)$value;
        if(isset($payload['data']['acuse'])&&preg_match('/<EstatusUUID>(20[1-5])<\/EstatusUUID>/',(string)$payload['data']['acuse'],$match))return$match[1];
        return'';
    }
    private function scalars(mixed $value): array
    {
        $out=[];$walk=static function(mixed$entry)use(&$walk,&$out):void{if(is_array($entry)||is_object($entry))foreach((array)$entry as$key=>$nested){$out[]=(string)$key;$walk($nested);}elseif(is_scalar($entry))$out[]=trim((string)$entry);};$walk($value);return$out;
    }
}
