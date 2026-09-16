<?php
declare(strict_types=1);

namespace App\Domain\Fiscal\Signing;

use RuntimeException;

final class CsdSecretException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $safeMessage
    ) {
        parent::__construct($safeMessage);
    }
}
