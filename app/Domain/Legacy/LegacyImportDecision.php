<?php

declare(strict_types=1);

namespace App\Domain\Legacy;

final class LegacyImportDecision
{
    public function __construct(
        public readonly string $action,
        public readonly string $reason,
        public readonly ?object $mapping = null
    ) {
    }
}
