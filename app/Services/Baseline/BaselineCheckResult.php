<?php

namespace App\Services\Baseline;

use JsonSerializable;

final class BaselineCheckResult implements JsonSerializable
{
    public function __construct(
        public readonly string $key,
        public readonly string $group,
        public readonly string $status,
        public readonly string $message,
        public readonly array $details = [],
        public readonly bool $required = true,
        public readonly ?string $remediationHint = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'group' => $this->group,
            'status' => $this->status,
            'message' => $this->message,
            'details' => $this->details,
            'required' => $this->required,
            'remediationHint' => $this->remediationHint,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
