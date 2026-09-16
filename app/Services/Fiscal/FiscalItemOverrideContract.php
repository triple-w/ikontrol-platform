<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use InvalidArgumentException;

/** Canonical, versioned contract for fiscal_override_json in every commercial item. */
final class FiscalItemOverrideContract
{
    public const VERSION = 2;

    public function fromInput(array $input, int $productId): ?array
    {
        if (empty($input['fiscal_override_enabled'])) return null;

        $data = [
            'schema_version' => self::VERSION,
            'override' => true,
            'product_id' => max(0, $productId),
            'product_service_code' => trim((string) ($input['product_service_code'] ?? '')),
            'unit_code' => trim((string) ($input['unit_code'] ?? '')),
            'commercial_unit' => trim((string) ($input['fiscal_commercial_unit'] ?? $input['commercial_unit'] ?? '')),
            'tax_object_code' => trim((string) ($input['tax_object_code'] ?? '')),
            'fiscal_description' => trim((string) ($input['fiscal_description'] ?? '')),
            'pricing_mode' => $this->pricingMode($input),
            'taxes' => $this->normalizeTaxes((array) ($input['fiscal_taxes'] ?? []), true),
        ];

        $normalized = $this->derive($data);
        return $normalized;
    }

    /** Read legacy JSON defensively; never trusts stored ready/complete flags. */
    public function normalizeStored(?array $stored, int $productId = 0): ?array
    {
        if (!$stored) return null;
        $data = [
            'schema_version' => self::VERSION,
            'override' => true,
            'product_id' => $productId > 0 ? $productId : max(0, (int) ($stored['product_id'] ?? 0)),
            'product_service_code' => trim((string) ($stored['product_service_code'] ?? $stored['setting']['product_service_code'] ?? '')),
            'unit_code' => trim((string) ($stored['unit_code'] ?? $stored['setting']['unit_code'] ?? '')),
            'commercial_unit' => trim((string) ($stored['commercial_unit'] ?? $stored['setting']['commercial_unit'] ?? '')),
            'tax_object_code' => trim((string) ($stored['tax_object_code'] ?? $stored['setting']['tax_object_code'] ?? '')),
            'fiscal_description' => trim((string) ($stored['fiscal_description'] ?? $stored['setting']['fiscal_description'] ?? '')),
            'pricing_mode' => $this->pricingMode($stored),
            'taxes' => $this->normalizeTaxes((array) ($stored['taxes'] ?? []), false),
        ];
        return $this->derive($data);
    }

    public function encode(array $normalized): string
    {
        return json_encode($this->derive($normalized), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function derive(array $data): array
    {
        $object = (string) ($data['tax_object_code'] ?? '');
        if ($object === '01') $data['taxes'] = [];
        $missing = [];
        foreach ([
            'product_service_code' => 'ClaveProdServ',
            'unit_code' => 'ClaveUnidad',
            'commercial_unit' => 'unidad',
            'tax_object_code' => 'ObjetoImp',
            'fiscal_description' => 'descripción fiscal',
        ] as $field => $label) {
            if (trim((string) ($data[$field] ?? '')) === '') $missing[] = $label;
        }
        if (!in_array($object, ['01', '02', '03', '04'], true)) $missing[] = 'ObjetoImp válido';
        if ($object !== '' && $object !== '01' && empty($data['taxes'])) $missing[] = 'impuestos';
        foreach ((array) ($data['taxes'] ?? []) as $tax) {
            if (!empty($tax['invalid'])) $missing[] = (string) $tax['invalid'];
        }
        $data['schema_version'] = self::VERSION;
        $data['override'] = true;
        $data['pricing_mode'] = in_array(($data['pricing_mode'] ?? ''), ['tax_inclusive', 'tax_exclusive'], true)
            ? $data['pricing_mode'] : 'tax_inclusive';
        $data['prices_include_tax'] = $data['pricing_mode'] === 'tax_inclusive';
        $data['missing'] = array_values(array_unique($missing));
        $data['ready'] = !$data['missing'];
        $data['complete'] = $data['ready'];
        return $data;
    }

    private function normalizeTaxes(array $rows, bool $strict): array
    {
        $taxes = [];
        foreach ($rows as $row) {
            if (!is_array($row) || trim((string) ($row['tax_code'] ?? '')) === '') continue;
            $code = str_pad(trim((string) $row['tax_code']), 3, '0', STR_PAD_LEFT);
            $type = trim((string) ($row['tax_type'] ?? ''));
            $factor = trim((string) ($row['factor_type'] ?? ''));
            $rate = trim((string) ($row['rate_or_quota'] ?? ''));
            $invalid = null;
            if (!in_array($code, ['001', '002', '003'], true)) $invalid = 'código de impuesto válido';
            elseif (!in_array($type, ['transfer', 'withholding'], true)) $invalid = 'tipo de impuesto válido';
            elseif (!in_array($factor, ['Tasa', 'Cuota', 'Exento'], true)) $invalid = 'factor de impuesto válido';
            elseif ($code === '001' && $type !== 'withholding') $invalid = 'ISR 001 como retención';
            elseif ($factor !== 'Exento' && !preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,6})?$/', $rate)) $invalid = 'tasa o cuota válida';
            if ($factor === 'Exento') $rate = '';
            if ($invalid && $strict) throw new InvalidArgumentException('La configuración fiscal contiene un ' . $invalid . '.');
            $tax = ['tax_code' => $code, 'tax_type' => $type, 'factor_type' => $factor, 'rate_or_quota' => $rate];
            if ($invalid) $tax['invalid'] = $invalid;
            $taxes[] = $tax;
        }
        return $taxes;
    }

    private function pricingMode(array $data): string
    {
        $mode = trim((string) ($data['pricing_mode'] ?? ''));
        if (in_array($mode, ['tax_inclusive', 'tax_exclusive'], true)) return $mode;
        if (array_key_exists('prices_include_tax', $data)) return filter_var($data['prices_include_tax'], FILTER_VALIDATE_BOOL) ? 'tax_inclusive' : 'tax_exclusive';
        return 'tax_inclusive';
    }
}
