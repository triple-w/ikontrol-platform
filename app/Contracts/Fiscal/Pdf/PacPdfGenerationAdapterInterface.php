<?php
declare(strict_types=1);namespace App\Contracts\Fiscal\Pdf;
use App\Domain\Fiscal\Pdf\PacPdfGenerationRequest;use App\Domain\Fiscal\Pdf\PacPdfGenerationResult;
interface PacPdfGenerationAdapterInterface{public function generate(PacPdfGenerationRequest$request):PacPdfGenerationResult;}
