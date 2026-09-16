<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Cancellation;

use App\Contracts\Fiscal\Cancellation\FiscalCancellationAdapterInterface;
use RuntimeException;

final class FakeFiscalCancellationAdapter implements FiscalCancellationAdapterInterface
{
    public int $calls = 0;
    public function __construct(private readonly string $scenario = 'accepted') {}

    public function cancel(array $request): array
    {
        $this->calls++;
        $ack = base64_encode('<?xml version="1.0" encoding="UTF-8"?><AcuseCancelacion ambiente="fake" uuid="'
            . htmlspecialchars((string)($request['uuid'] ?? ''), ENT_XML1) . '"/>');
        return match ($this->scenario) {
            'accepted' => ['status'=>'accepted','code'=>'FAKE_ACCEPTED','message'=>'Cancelación fiscal simulada aceptada.','ack_base64'=>$ack,'request_sent'=>true],
            'pending' => ['status'=>'pending','code'=>'FAKE_PENDING','message'=>'Cancelación fiscal simulada pendiente.','ack_base64'=>null,'request_sent'=>true],
            'rejected' => ['status'=>'rejected','code'=>'FAKE_REJECTED','message'=>'Cancelación fiscal simulada rechazada.','ack_base64'=>null,'request_sent'=>true],
            'timeout_unknown' => ['status'=>'unknown','code'=>null,'message'=>'Resultado de cancelación desconocido.','ack_base64'=>null,'request_sent'=>true],
            'transport_not_sent' => ['status'=>'transport_not_sent','code'=>null,'message'=>'La solicitud no salió del proceso local.','ack_base64'=>null,'request_sent'=>false],
            'persistence_error' => ['status'=>'accepted','code'=>'FAKE_ACCEPTED','message'=>'Fallo de persistencia simulado.','ack_base64'=>$ack,'request_sent'=>true,'force_persistence_error'=>true],
            default => throw new RuntimeException('Escenario fake de cancelación no soportado.'),
        };
    }

    public function query(array $request): array
    {
        return $this->cancel($request);
    }
}
