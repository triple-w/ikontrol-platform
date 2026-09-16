<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Cfdi40;

use RuntimeException;

final class CfdiSemanticValidationException extends RuntimeException
{
    public function __construct(private readonly array $validation)
    {
        parent::__construct('La validaci?n sem?ntica contiene errores.');
    }

    public function diagnostic(): array
    {
        return [
            'message' => $this->getMessage(),
            'validation_level' => (string) ($this->validation['validation_level'] ?? 'pre_sign_validation'),
            'semantic_errors' => $this->groups((array) ($this->validation['errors'] ?? [])),
            'semantic_warnings' => $this->messages((array) ($this->validation['warnings'] ?? [])),
            'semantic_checks' => $this->checks((array) ($this->validation['checks'] ?? [])),
        ];
    }

    private function groups(array $groups): array
    {
        $safe = [];
        foreach ($groups as $group => $messages) {
            foreach ((array) $messages as $message) {
                $safe[] = ['group' => $this->text($group), 'message' => $this->text($message)];
            }
        }
        return $safe;
    }

    private function checks(array $checks): array
    {
        $safe = [];
        foreach ($checks as $check) {
            if (!is_array($check)) continue;
            $safe[] = [
                'group' => $this->text($check['group'] ?? ''),
                'message' => $this->text($check['message'] ?? ''),
                'passed' => !empty($check['passed']),
            ];
        }
        return $safe;
    }

    private function messages(array $messages): array
    {
        return array_values(array_map(fn($message) => $this->text($message), $messages));
    }

    private function text(mixed $value): string
    {
        return mb_substr(preg_replace('/[\r\n\t]+/u', ' ', trim((string) $value)) ?? '', 0, 500);
    }
}
