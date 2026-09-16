<?php
declare(strict_types=1);
namespace App\Services\Fiscal;

class TaxFiscalConfigurationService
{
    public function prepare(array $input, ?object $taxCode, ?object $factor): array
    {
        $enabled = ! empty($input['use_for_fiscal']);
        $data = [
            'sat_tax_code_id' => $this->nullableId($input['sat_tax_code_id'] ?? null),
            'fiscal_tax_type' => $this->nullableText($input['fiscal_tax_type'] ?? null),
            'factor_type_id' => $this->nullableId($input['factor_type_id'] ?? null),
            'xml_rate' => $this->decimalOrNull($input['xml_rate'] ?? null),
            'xml_quota' => $this->decimalOrNull($input['xml_quota'] ?? null),
            'use_for_administrative' => ! empty($input['use_for_administrative']) ? 1 : 0,
            'use_for_fiscal' => $enabled ? 1 : 0,
            'is_fiscal_ready' => 0,
            'fiscal_notes' => $this->nullableText($input['fiscal_notes'] ?? null),
        ];

        if (! $enabled) {
            $data['sat_tax_code_id'] = $data['fiscal_tax_type'] = $data['factor_type_id'] = null;
            $data['xml_rate'] = $data['xml_quota'] = null;
            return ['data' => $data, 'errors' => []];
        }

        $errors = [];
        if (! $data['sat_tax_code_id'] || ! $taxCode || ! (int) ($taxCode->is_active ?? 0)) $errors[] = 'Selecciona una clave de impuesto SAT activa.';
        if (! in_array($data['fiscal_tax_type'], ['transfer', 'withholding'], true)) $errors[] = 'Selecciona traslado o retención.';
        if (! $data['factor_type_id'] || ! $factor || ! (int) ($factor->is_active ?? 0)) $errors[] = 'Selecciona un tipo de factor activo.';

        $factorCode = (string) ($factor->code ?? '');
        if ($factorCode === 'Tasa') {
            if ($data['xml_rate'] === null) $errors[] = 'El factor Tasa requiere una tasa XML decimal exacta.';
            $data['xml_quota'] = null;
        } elseif ($factorCode === 'Cuota') {
            if ($data['xml_quota'] === null) $errors[] = 'El factor Cuota requiere una cuota XML decimal exacta.';
            $data['xml_rate'] = null;
        } elseif ($factorCode === 'Exento') {
            $data['xml_rate'] = $data['xml_quota'] = null;
        }

        $data['is_fiscal_ready'] = $errors === [] ? 1 : 0;
        return ['data' => $data, 'errors' => $errors];
    }

    private function nullableId($value): ?int { return ctype_digit((string) $value) && (int) $value > 0 ? (int) $value : null; }
    private function nullableText($value): ?string { $value = trim((string) $value); return $value === '' ? null : $value; }
    private function decimalOrNull($value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') return null;
        return preg_match('/^\d+(?:\.\d{1,6})?$/', $value) ? $value : null;
    }
}
