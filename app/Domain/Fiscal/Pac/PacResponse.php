<?php
declare(strict_types=1);

namespace App\Domain\Fiscal\Pac;

final class PacResponse
{
    public function __construct(
        public readonly ?string $code,
        public readonly string $message,
        public readonly ?string $data,
        public readonly int $httpStatus,
        public readonly array $metadata = [],
        public readonly bool $transportError = false,
        public readonly bool $timeout = false
    ) {
    }
}
