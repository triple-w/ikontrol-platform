<?php
declare(strict_types=1);
namespace App\Domain\Fiscal\Cfdi40;
final class CfdiConceptTax{public function __construct(public string$taxCode,public string$taxType,public string$factorType,public?string$rateOrQuota,public string$base,public string$amount){}}
