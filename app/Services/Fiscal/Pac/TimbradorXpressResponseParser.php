<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Pac;

use App\Domain\Fiscal\Pac\PacResponse;
use RuntimeException;

final class TimbradorXpressResponseParser
{
    public function parse(string|array|object $body, int $httpStatus): PacResponse
    {
        if (is_string($body)) {
            if (strlen($body) > config('TimbradorXpress')->maxResponseBytes) throw new RuntimeException('La respuesta del PAC excede el límite permitido.');
            try {
                $decoded = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new RuntimeException('El PAC devolvió una respuesta que no es JSON válido.');
            }
        } else {
            $decoded = json_decode(json_encode($body, JSON_THROW_ON_ERROR), true, 16, JSON_THROW_ON_ERROR);
        }
        if (!is_array($decoded) || !array_key_exists('code', $decoded) || !array_key_exists('message', $decoded) || !array_key_exists('data', $decoded)) {
            throw new RuntimeException('La respuesta del PAC no contiene code, message y data.');
        }
        $code = is_scalar($decoded['code']) ? trim((string)$decoded['code']) : null;
        $message = is_scalar($decoded['message']) ? $this->sanitize((string)$decoded['message']) : '';
        $data = is_string($decoded['data']) ? $decoded['data'] : null;
        return new PacResponse($code, $message, $data, $httpStatus, [
            'has_data' => $data !== null && $data !== '',
            'response_keys' => array_values(array_intersect(array_keys($decoded), ['code','message','data'])),
            'parsing_phase' => 'outer_parsed',
            'outer_type' => 'object',
        ]);
    }

    private function sanitize(string $message): string
    {
        return mb_substr(trim(strip_tags(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $message))), 0, 500);
    }
}
