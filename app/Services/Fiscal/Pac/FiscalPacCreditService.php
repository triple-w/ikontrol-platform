<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Pac;

use Config\TimbradorXpress;
use RuntimeException;
use Throwable;

final class FiscalPacCreditService
{
    private mixed $client;

    public function __construct(
        private mixed $db = null,
        private ?TimbradorXpress $configuration = null,
        mixed $client = null
    ) {
        $this->db ??= db_connect();
        $this->client = $client ?? service('curlrequest');
    }

    public function consult(int $issuerProfileId, ?int $userId = null): array
    {
        $config = $this->configuration ?? config('TimbradorXpress');
        if ($config->environment !== 'sandbox') throw new RuntimeException('La consulta de créditos sólo está habilitada para development.');
        $config->assertSandbox();
        if (!$config->isConfigured()) throw new RuntimeException('PAC development no configurado.');
        $issuer = $this->db->table('fiscal_profiles')->where(['id' => $issuerProfileId, 'profile_type' => 'issuer'])->get(1)->getRow();
        if (!$issuer) throw new RuntimeException('Emisor fiscal no encontrado.');

        $response = $this->client->post($config->baseUrl . 'consultarCreditosDisponibles', [
            'form_params' => ['apikey' => $config->apiKey],
            'connect_timeout' => $config->connectTimeout,
            'timeout' => $config->requestTimeout,
            'verify' => true,
            'http_errors' => false,
            'headers' => ['Accept' => 'application/json', 'Content-Type' => 'application/x-www-form-urlencoded'],
        ]);
        $body = (string) $response->getBody();
        $httpStatus = (int) $response->getStatusCode();
        $parsed = $this->parse($body, $httpStatus);
        $consultedAt = get_current_utc_time();
        $this->db->table('fiscal_pac_credit_consultations')->insert([
            'issuer_profile_id' => $issuerProfileId,
            'provider' => 'timbradorxpress',
            'environment' => 'development',
            'available_credits' => $parsed['available_credits'],
            'provider_code' => $parsed['provider_code'],
            'provider_message' => $parsed['provider_message'],
            'http_status' => $httpStatus,
            'response_sha256' => hash('sha256', $body),
            'consulted_at' => $consultedAt,
            'created_by' => $userId,
        ]);
        $consultationId=(int)$this->db->insertID();
        if($this->db->tableExists('fiscal_pac_credit_snapshots'))$this->db->table('fiscal_pac_credit_snapshots')->insert(['provider'=>'timbradorxpress','environment'=>'development','available_credits'=>$parsed['available_credits'],'consulted_at'=>$consultedAt,'provider_code'=>$parsed['provider_code'],'created_at'=>$consultedAt]);
        return $parsed + [
            'provider' => 'timbradorxpress',
            'environment' => 'development',
            'consulted_at' => $consultedAt,
            'consultation_id' => $consultationId,
        ];
    }

    public function parse(string $body, int $httpStatus): array
    {
        if ($body === '' || strlen($body) > 1048576) throw new RuntimeException('Respuesta de créditos vacía o excesiva.');
        try {$outer = json_decode($body, true, 16, JSON_THROW_ON_ERROR);} catch (Throwable) {
            throw new RuntimeException('La respuesta de créditos no es JSON válido.');
        }
        if (!is_array($outer)) throw new RuntimeException('La respuesta de créditos no es un objeto.');
        $code = isset($outer['code']) && is_scalar($outer['code']) ? trim((string) $outer['code']) : null;
        $message = isset($outer['message']) && is_scalar($outer['message']) ? $this->sanitize((string) $outer['message']) : '';
        if ($httpStatus < 200 || $httpStatus >= 300 || !in_array($code, ['200', '210'], true)) {
            throw new RuntimeException($message ?: 'El PAC rechazó la consulta de créditos.');
        }
        $data = $outer['data'] ?? null;
        if (is_string($data)) {
            $trimmed = trim($data);
            if (ctype_digit($trimmed)) $data = (int) $trimmed;
            elseif ($trimmed !== '') {
                try {$data = json_decode($trimmed, true, 8, JSON_THROW_ON_ERROR);} catch (Throwable) {}
            }
        }
        $credits = is_int($data) ? $data : null;
        if (is_array($data)) {
            foreach (['creditos', 'creditosDisponibles', 'credits', 'available_credits', 'disponibles'] as $key) {
                if (isset($data[$key]) && is_numeric($data[$key]) && (string)(int)$data[$key] === trim((string)$data[$key])) {
                    $credits = (int) $data[$key]; break;
                }
            }
        }
        if ($credits === null || $credits < 0) throw new RuntimeException('El PAC no devolvió un saldo entero reconocible.');
        return ['available_credits' => $credits, 'provider_code' => $code, 'provider_message' => $message];
    }

    private function sanitize(string $value): string
    {
        return mb_substr(trim(strip_tags((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value))), 0, 500);
    }
}
