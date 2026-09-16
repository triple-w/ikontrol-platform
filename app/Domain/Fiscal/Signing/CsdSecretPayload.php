<?php
declare(strict_types=1);

namespace App\Domain\Fiscal\Signing;

final class CsdSecretPayload
{
    public function __construct(
        public readonly int $version,
        public readonly string $algorithm,
        public readonly string $nonce,
        public readonly string $tag,
        public readonly string $ciphertext
    ) {
    }

    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'algorithm' => $this->algorithm,
            'nonce' => $this->nonce,
            'tag' => $this->tag,
            'ciphertext' => $this->ciphertext,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
