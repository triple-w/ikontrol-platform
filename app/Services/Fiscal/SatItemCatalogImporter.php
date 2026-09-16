<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use CodeIgniter\Database\BaseConnection;
use RuntimeException;

class SatItemCatalogImporter
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? db_connect();
    }

    public function import(string $sourceSchema): array
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $sourceSchema)) {
            throw new RuntimeException('Invalid source database name.');
        }

        $this->assertSourceTable($sourceSchema, 'clave_prod_serv');
        $this->assertSourceTable($sourceSchema, 'clave_unidad');

        return [
            'product_service' => $this->importProductServices($sourceSchema),
            'units' => $this->importUnits($sourceSchema),
        ];
    }

    private function importProductServices(string $schema): array
    {
        $rows = $this->db->query(
            "SELECT TRIM(clave) AS code, MAX(TRIM(descripcion)) AS description, COUNT(*) AS occurrences
             FROM `{$schema}`.`clave_prod_serv`
             GROUP BY TRIM(clave)
             ORDER BY TRIM(clave)"
        )->getResultArray();

        return $this->persist(
            'sat_product_service_keys',
            $rows,
            'FactuCare 2 database:' . $schema,
            static fn (array $row): bool => preg_match('/^[0-9]{8}$/', (string) $row['code']) === 1,
            static fn (array $row): array => ['description' => $row['description']]
        );
    }

    private function importUnits(string $schema): array
    {
        $rows = $this->db->query(
            "SELECT TRIM(clave) AS code, MAX(TRIM(descripcion)) AS description, COUNT(*) AS occurrences
             FROM `{$schema}`.`clave_unidad`
             GROUP BY TRIM(clave)
             ORDER BY TRIM(clave)"
        )->getResultArray();

        return $this->persist(
            'sat_unit_keys',
            $rows,
            'FactuCare 2 database:' . $schema,
            static fn (array $row): bool => $row['code'] !== '' && mb_strlen((string) $row['code']) <= 10,
            static fn (array $row): array => [
                'name' => $row['description'],
                'description' => $row['description'],
                'symbol' => null,
            ]
        );
    }

    private function persist(string $targetTable, array $rows, string $sourceVersion, callable $validCode, callable $map): array
    {
        $existing = [];
        foreach ($this->db->table($targetTable)->get()->getResultArray() as $row) {
            $existing[(string) $row['code']] = $row;
        }

        $stats = ['source_rows' => 0, 'unique_rows' => count($rows), 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];
        $now = date('Y-m-d H:i:s');
        $inserts = [];

        $this->db->transBegin();
        try {
            foreach ($rows as $row) {
                $occurrences = max(1, (int) ($row['occurrences'] ?? 1));
                $stats['source_rows'] += $occurrences;
                $stats['skipped'] += $occurrences - 1; // Identical duplicate source rows are consolidated by code.
                $code = (string) $row['code'];
                $description = trim((string) $row['description']);
                if (!$validCode($row) || $description === '') {
                    $stats['errors']++;
                    continue;
                }

                $data = array_merge($map($row), [
                    'valid_from' => null,
                    'valid_to' => null,
                    'source_version' => $sourceVersion,
                ]);

                if (isset($existing[$code])) {
                    $changed = false;
                    foreach ($data as $field => $value) {
                        if ((string) ($existing[$code][$field] ?? '') !== (string) ($value ?? '')) {
                            $changed = true;
                            break;
                        }
                    }
                    if ($changed) {
                        $this->db->table($targetTable)->where('id', $existing[$code]['id'])->update(array_merge($data, ['updated_at' => $now]));
                        $stats['updated']++;
                    } else {
                        $stats['skipped']++;
                    }
                    continue;
                }

                $inserts[] = array_merge($data, ['code' => $code, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
                if (count($inserts) === 500) {
                    $this->db->table($targetTable)->insertBatch($inserts);
                    $stats['inserted'] += count($inserts);
                    $inserts = [];
                }
            }

            if ($inserts) {
                $this->db->table($targetTable)->insertBatch($inserts);
                $stats['inserted'] += count($inserts);
            }
            if ($this->db->transStatus() === false) {
                throw new RuntimeException('Catalog import transaction failed.');
            }
            $this->db->transCommit();
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }

        return $stats;
    }

    private function assertSourceTable(string $schema, string $table): void
    {
        $exists = (int) $this->db->query(
            'SELECT COUNT(*) AS total FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$schema, $table]
        )->getRow()->total;
        if (!$exists) {
            throw new RuntimeException("Required source table {$schema}.{$table} was not found.");
        }
    }
}
