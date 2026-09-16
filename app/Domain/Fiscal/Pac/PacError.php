<?php
declare(strict_types=1);

namespace App\Domain\Fiscal\Pac;

final class PacError
{
    public function __construct(
        public readonly string $category,
        public readonly string $title,
        public readonly string $message,
        public readonly string $recommendedAction,
        public readonly bool $retryable,
        public readonly bool $requiresReconciliation,
        public readonly string $severity = 'error'
    ) {
    }
}
