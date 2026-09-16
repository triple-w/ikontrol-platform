<?php
declare(strict_types=1);namespace App\Domain\Fiscal\Pdf;
final class FiscalPdfTemplateSelection{public function __construct(public readonly int$issuerId,public readonly string$provider,public readonly string$documentType,public readonly string$templateCode,public readonly string$source,public readonly bool$configurable=true){}}
