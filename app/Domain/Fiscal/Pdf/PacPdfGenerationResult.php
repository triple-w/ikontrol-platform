<?php
declare(strict_types=1);namespace App\Domain\Fiscal\Pdf;
final class PacPdfGenerationResult{public function __construct(public readonly bool$success,public readonly string$provider,public readonly ?string$providerCode,public readonly ?string$providerMessage,public readonly ?string$pdfBase64,public readonly string$mimeType,public readonly string$templateCode,public readonly bool$requestSent,public readonly bool$retryable,public readonly string$status,public readonly bool$requiresReconciliation=false){}}
