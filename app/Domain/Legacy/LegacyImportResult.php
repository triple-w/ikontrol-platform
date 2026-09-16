<?php

declare(strict_types=1);

namespace App\Domain\Legacy;

final class LegacyImportResult
{
    public function __construct(
        public readonly int $mappingId,
        public readonly string $status,
        public readonly string $action
    ) {
    }
}
