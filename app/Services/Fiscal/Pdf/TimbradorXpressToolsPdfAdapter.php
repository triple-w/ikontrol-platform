<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Pdf;

use App\Contracts\Fiscal\Pdf\PacPdfGenerationAdapterInterface;
use App\Domain\Fiscal\Pdf\PacPdfGenerationRequest;
use App\Domain\Fiscal\Pdf\PacPdfGenerationResult;
use App\Services\Fiscal\Pac\PacPdfValidator;
use Config\Fiscal;
use Config\FiscalPdfProvider;
use RuntimeException;
use SoapFault;
use Throwable;

final class TimbradorXpressToolsPdfAdapter implements PacPdfGenerationAdapterInterface
{
    public function __construct(
        private readonly FiscalPdfProvider $config,
        private readonly Fiscal $fiscal,
        private readonly mixed $clientFactory = null,
        private readonly ?PacPdfValidator $validator = null
    ) {
        $this->assertConfiguration();
    }

    public function generate(PacPdfGenerationRequest $request): PacPdfGenerationResult
    {
        $this->assertConfiguration();
        $correlationId = $request->correlationId !== '' ? $request->correlationId
            : hash('sha256', $request->documentId . '|' . $request->stampId . '|' . microtime(true));
        $this->stage('PDF_STAGE_3_BEFORE_SOAPCLIENT', $request, $correlationId);
        if (!class_exists(\SoapClient::class) && $this->clientFactory === null) {
            throw new RuntimeException('FISCAL_PDF_SOAP_EXTENSION_MISSING');
        }
        $factory = $this->clientFactory
            ?? static fn(string $wsdl, array $options) => new \SoapClient($wsdl, $options);
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
            ],
            'http' => ['timeout' => $this->config->requestTimeout],
        ]);
        $client = $factory($this->config->wsdl, [
            'exceptions' => true,
            'trace' => false,
            'cache_wsdl' => defined('WSDL_CACHE_MEMORY') ? constant('WSDL_CACHE_MEMORY') : 0,
            'connection_timeout' => $this->config->connectTimeout,
            'stream_context' => $context,
        ]);
        $this->stage('PDF_STAGE_4_SOAPCLIENT_CREATED', $request, $correlationId);
        $metadata = base64_encode(json_encode(
            $request->printMetadata,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));

        try {
            $this->stage('PDF_STAGE_5_BEFORE_GENERARPDF', $request, $correlationId);
            $soapTemplate = ctype_digit($request->templateCode)
                ? (int) $request->templateCode
                : $request->templateCode;
            $raw = $client->generarPDF(
                $this->config->username,
                $this->config->password,
                base64_encode($request->stampedXml),
                $soapTemplate,
                $metadata,
                $request->logoBase64 ?? ''
            );
            $this->stage('PDF_STAGE_6_AFTER_GENERARPDF', $request, $correlationId);
        } catch (SoapFault $fault) {
            return new PacPdfGenerationResult(
                false, 'timbradorxpress-tools', $this->sanitize((string) $fault->faultcode),
                $this->sanitize((string) $fault->getMessage()) ?: 'El proveedor rechazó la generación.',
                null, 'application/pdf', $request->templateCode, true, false, 'unknown', true
            );
        } catch (Throwable) {
            return new PacPdfGenerationResult(
                false, 'timbradorxpress-tools', null,
                'No fue posible confirmar la generación del PDF.', null,
                'application/pdf', $request->templateCode, true, false, 'unknown', true
            );
        }

        log_message('info', 'WSTools33 generarPDF response shape: {shape}', [
            'shape' => json_encode($this->describeShape($raw), JSON_UNESCAPED_SLASHES),
        ]);
        $data = $this->normalizeSoapResponse($raw);
        $this->stage('PDF_STAGE_7_RESPONSE_NORMALIZED', $request, $correlationId);
        $code = (string) ($data['code'] ?? '');
        $message = (string) ($data['message'] ?? '');
        $pdf = $data['pdf'] ?? null;
        if ($code !== '210' || !is_string($pdf) || trim($pdf) === '') {
            if ($code === '210' && (!is_string($pdf) || trim($pdf) === '')) {
                $message = 'El proveedor indicó éxito pero no entregó el PDF.';
            }
            return new PacPdfGenerationResult(
                false, 'timbradorxpress-tools', $code ?: null,
                $message ?: 'El proveedor no devolvió un PDF válido.', null,
                'application/pdf', $request->templateCode, true, false, 'rejected'
            );
        }
        try {
            $validated = ($this->validator ?? new PacPdfValidator())->validate(trim($pdf));
        } catch (Throwable) {
            return new PacPdfGenerationResult(
                false, 'timbradorxpress-tools', $code,
                'El proveedor devolvió una representación impresa inválida.', null,
                'application/pdf', $request->templateCode, true, false, 'rejected'
            );
        }
        return new PacPdfGenerationResult(
            true, 'timbradorxpress-tools', $code, $message ?: 'PDF generado.',
            $validated['content_base64'], 'application/pdf', $request->templateCode,
            true, false, 'success'
        );
    }

    private function stage(string $stage, PacPdfGenerationRequest $request, string $correlationId): void
    {
        log_message('info', $stage . ' {context}', ['context' => json_encode([
            'attempt_id' => $request->pdfAttemptId,
            'document_id' => $request->documentId,
            'provider' => 'timbradorxpress-tools',
            'template_code' => $request->templateCode,
            'correlation_id' => $correlationId,
            'timestamp' => gmdate('c'),
        ], JSON_UNESCAPED_SLASHES)]);
    }

    private function sanitize(string $value): ?string
    {
        $value = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value));
        return $value === '' ? null : mb_substr($value, 0, 240);
    }

    private function normalizeSoapResponse(mixed $response): array
    {
        $data = is_object($response) ? get_object_vars($response)
            : (is_array($response) ? $response : []);
        return [
            'code' => $data['code'] ?? $data['codigo'] ?? $data['CODIGO'] ?? null,
            'message' => $data['message'] ?? $data['mensaje'] ?? $data['MENSAJE'] ?? null,
            'pdf' => $data['pdf'] ?? $data['PDF'] ?? null,
        ];
    }

    private function describeShape(mixed $value, int $depth = 0): array
    {
        if ($depth >= 3) {
            return ['type' => get_debug_type($value), 'depth_limited' => true];
        }
        if (is_string($value)) {
            return ['type' => 'string', 'value' => '[redacted length=' . strlen($value) . ']'];
        }
        if (is_object($value)) {
            $properties = get_object_vars($value);
            return [
                'type' => 'object',
                'class' => get_class($value),
                'properties' => array_keys($properties),
                'children' => array_map(fn(mixed $item) => $this->describeShape($item, $depth + 1), $properties),
            ];
        }
        if (is_array($value)) {
            return [
                'type' => 'array',
                'keys' => array_keys($value),
                'children' => array_map(fn(mixed $item) => $this->describeShape($item, $depth + 1), $value),
            ];
        }
        return ['type' => get_debug_type($value)];
    }

    private function assertConfiguration(): void
    {
        if (!$this->fiscal->enabled || !$this->fiscal->allowExternalPdf
            || !$this->config->enabled || !$this->config->allowExternalPdf) {
            throw new RuntimeException('FISCAL_PDF_EXTERNAL_DISABLED');
        }
        if ($this->fiscal->runtimeMode === 'automated_test' && ENVIRONMENT !== 'testing') {
            throw new RuntimeException('FISCAL_PDF_RUNTIME_INVALID');
        }
        if ($this->config->username === '' || $this->config->password === '' || $this->config->wsdl === '') {
            throw new RuntimeException('FISCAL_PDF_CREDENTIALS_MISSING');
        }
        $parts = parse_url($this->config->wsdl);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || !in_array($host, $this->config->allowedHosts, true)) {
            throw new RuntimeException('FISCAL_PDF_HOST_NOT_ALLOWED');
        }
        if ($scheme !== 'https'
            && !($scheme === 'http' && $this->config->allowInsecureHttp
                && $this->fiscal->runtimeMode === 'integration')) {
            throw new RuntimeException('FISCAL_PDF_INSECURE_ENDPOINT');
        }
    }
}
