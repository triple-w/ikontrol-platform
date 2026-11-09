<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use RuntimeException;

/** Validates receiver identity and UsoCFDI against the installed SAT catalogs. */
final class FiscalReceiverRuleService
{
    public function __construct(private mixed $db = null)
    {
    }

    public function validate(
        array|object $receiver,
        string $cfdiUse,
        string $documentType,
        string $expeditionPostalCode,
        string $issueDate = ''
    ): void {
        $receiver = (array) $receiver;
        $rfc = strtoupper(trim((string) ($receiver['rfc'] ?? '')));
        $name = $this->normalize((string) ($receiver['legal_name'] ?? ''));
        $regimeCode = trim((string) ($receiver['tax_regime_code'] ?? $receiver['receiver_tax_regime_code'] ?? ''));
        $postalCode = trim((string) ($receiver['fiscal_postal_code'] ?? ''));
        $cfdiUse = strtoupper(trim($cfdiUse));
        $documentType = strtoupper(trim($documentType));
        if ($documentType === 'INCOME') $documentType = 'I';

        if ($rfc === '' || $name === '' || $regimeCode === '' || $postalCode === '' || $cfdiUse === '') {
            throw new RuntimeException('El receptor fiscal, su regimen, domicilio y UsoCFDI deben estar completos.');
        }

        if ($this->db !== null) {
            $regime = $this->catalogRow('sat_tax_regimes', $regimeCode, $issueDate);
            $use = $this->catalogRow('sat_cfdi_uses', $cfdiUse, $issueDate);
            $generic = in_array($rfc, ['XAXX010101000', 'XEXX010101000'], true);
            if (!$generic) {
                $individual = strlen($rfc) === 13;
                $column = $individual ? 'applies_to_individual' : 'applies_to_company';
                if (!(int) ($regime->{$column} ?? 0)) {
                    throw new RuntimeException('El regimen fiscal del receptor no corresponde a su tipo de persona.');
                }
                if (!(int) ($use->{$column} ?? 0)) {
                    throw new RuntimeException('El UsoCFDI seleccionado no corresponde al tipo de persona del receptor.');
                }
            }
        }

        if (in_array($rfc, ['XAXX010101000', 'XEXX010101000'], true)) {
            if ($regimeCode !== '616') {
                throw new RuntimeException('Los RFC genericos requieren regimen receptor 616 (Sin obligaciones fiscales).');
            }
            if ($cfdiUse !== 'S01') {
                throw new RuntimeException('Los RFC genericos requieren UsoCFDI S01 (Sin efectos fiscales).');
            }
            if ($expeditionPostalCode === '' || $postalCode !== trim($expeditionPostalCode)) {
                throw new RuntimeException('El domicilio fiscal de un RFC generico debe coincidir con el lugar de expedicion.');
            }
        }

        if ($rfc === 'XAXX010101000' && $documentType === 'I' && $name !== 'PUBLICO EN GENERAL') {
            throw new RuntimeException('El RFC XAXX010101000 para ingreso requiere el nombre PUBLICO EN GENERAL.');
        }
    }

    private function catalogRow(string $table, string $code, string $issueDate): object
    {
        $row = $this->db->table($table)->where(['code'=>$code, 'is_active'=>1])->get(1)->getRow();
        if (!$row) {
            throw new RuntimeException('La clave fiscal '.$code.' no existe o no esta activa en el catalogo SAT instalado.');
        }
        $date = substr(trim($issueDate), 0, 10);
        if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            if (!empty($row->valid_from) && $date < (string) $row->valid_from) {
                throw new RuntimeException('La clave fiscal '.$code.' aun no estaba vigente en la fecha del comprobante.');
            }
            if (!empty($row->valid_to) && $date > (string) $row->valid_to) {
                throw new RuntimeException('La clave fiscal '.$code.' ya no estaba vigente en la fecha del comprobante.');
            }
        }
        return $row;
    }

    private function normalize(string $value): string
    {
        $value = strtoupper(trim($value));
        $value = strtr($value, ['Á'=>'A', 'É'=>'E', 'Í'=>'I', 'Ó'=>'O', 'Ú'=>'U', 'Ü'=>'U']);
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
