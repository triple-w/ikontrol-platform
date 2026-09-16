<?php
namespace App\Services;

use RuntimeException;

final class FinancialAccountService
{
    public const TYPES = ['cash', 'bank', 'card', 'wallet', 'other'];

    public function __construct(private $db = null)
    {
        $this->db ??= db_connect();
    }

    public function save(array $input, int $id, ?int $actor): int
    {
        $type = (string) ($input['type'] ?? '');
        if (!in_array($type, self::TYPES, true)) {
            throw new RuntimeException('El tipo de cuenta no es válido.');
        }
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('El nombre de la cuenta es obligatorio.');
        }
        $opening = FinancialMoney::normalize($input['opening_balance'] ?? '0');
        if ($id) {
            $current = $this->db->table('financial_accounts')->where(['id' => $id, 'deleted' => 0])->get(1)->getRow();
            if (!$current) {
                throw new RuntimeException('La cuenta no existe.');
            }
            $hasMovements = $this->db->table('financial_account_movements')->where(['financial_account_id' => $id, 'is_active' => 1])->countAllResults() > 0;
            if ($hasMovements && FinancialMoney::normalize((string) $current->opening_balance) !== $opening) {
                throw new RuntimeException('El saldo inicial queda bloqueado después del primer movimiento. Registre un ajuste.');
            }
        }
        $data = [
            'name' => $name,
            'type' => $type,
            'description' => $input['description'] ?? null,
            'bank_name' => trim((string) ($input['bank_name'] ?? '')) ?: null,
            'bank_rfc' => strtoupper(trim((string) ($input['bank_rfc'] ?? ''))) ?: null,
            'account_number' => trim((string) ($input['account_number'] ?? '')) ?: null,
            'clabe' => trim((string) ($input['clabe'] ?? '')) ?: null,
            'currency' => 'MXN',
            'opening_balance' => $opening,
            'is_active' => array_key_exists('is_active', $input) ? (int) (bool) $input['is_active'] : 1,
            'updated_at' => get_current_utc_time(),
        ];
        if (!$id) {
            $data['created_by'] = $actor;
            $data['created_at'] = get_current_utc_time();
            $data['deleted'] = 0;
            if (!$this->db->table('financial_accounts')->insert($data)) {
                throw new RuntimeException('No fue posible crear la cuenta.');
            }
            return (int) $this->db->insertID();
        }
        if (!$this->db->table('financial_accounts')->where('id', $id)->update($data)) {
            throw new RuntimeException('No fue posible actualizar la cuenta.');
        }
        return $id;
    }
}
