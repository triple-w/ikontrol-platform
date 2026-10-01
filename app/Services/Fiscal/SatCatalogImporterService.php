<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use CodeIgniter\Database\BaseConnection;
use RuntimeException;

final class SatCatalogImporterService
{
    private const SCHEMA_VERSION = 1;
    private BaseConnection $db;
    private string $root;

    public function __construct(?BaseConnection $db = null, ?string $root = null)
    {
        $this->db = $db ?? db_connect();
        $this->root = rtrim($root ?? ROOTPATH . 'resources/fiscal/catalogs/sat', '/\\');
    }

    public function update(?string $only = null, bool $dryRun = false, bool $force = false): array
    {
        $manifest = $this->manifest();
        $results = [];
        foreach ($manifest['catalogs'] as $entry) {
            if ($only !== null && ($entry['catalog_name'] ?? '') !== $only) continue;
            $results[] = $this->importCatalog($entry, $dryRun, $force);
        }
        if ($only !== null && $results === []) throw new RuntimeException('Catalog is absent from the manifest: ' . $only);
        return ['schema_version' => self::SCHEMA_VERSION, 'dry_run' => $dryRun, 'catalogs' => $results];
    }

    public function manifest(): array
    {
        $path = $this->root . DIRECTORY_SEPARATOR . 'manifest.json';
        $data = json_decode((string) @file_get_contents($path), true);
        if (! is_array($data) || ($data['schema_version'] ?? null) !== self::SCHEMA_VERSION || ! is_array($data['catalogs'] ?? null)) {
            throw new RuntimeException('Invalid SAT catalog manifest.');
        }
        return $data;
    }

    private function importCatalog(array $entry, bool $dryRun, bool $force): array
    {
        $name = (string) ($entry['catalog_name'] ?? '');
        $map = $this->map($name);
        foreach (['source', 'source_version', 'file', 'checksum', 'row_count'] as $field) if (! array_key_exists($field, $entry)) throw new RuntimeException("Manifest {$name} missing {$field}.");
        $path = $this->safeFile((string) $entry['file']);
        $checksum = strtolower(str_replace('sha256:', '', (string) $entry['checksum']));
        if (! hash_equals($checksum, hash_file('sha256', $path))) throw new RuntimeException("Checksum mismatch for {$name}.");
        if ($force && empty($entry['complete_authoritative'])) throw new RuntimeException("Force requires complete_authoritative for {$name}.");
        $stats = array_fill_keys(['source_rows','valid_rows','inserted','updated','unchanged','deactivated','potential_deactivations','missing_from_source','missing_references','errors'], 0);
        $seen = [];
        $this->db->transBegin();
        try {
            $handle = fopen($path, 'rb');
            $headers = fgetcsv($handle);
            if ($headers === false || array_values($headers) !== $map['headers']) throw new RuntimeException("Invalid CSV headers for {$name}.");
            while (($row = fgetcsv($handle)) !== false) {
                $stats['source_rows']++;
                if (count($row) !== count($headers)) throw new RuntimeException("Invalid CSV row for {$name}.");
                $input = array_combine($headers, $row);
                $code = $map['code']($input['code']);
                if ($code === null || isset($seen[$code])) throw new RuntimeException("Invalid or duplicate code in {$name}.");
                $seen[$code] = true; $stats['valid_rows']++;
                $data = $map['data']($input) + ['is_active' => 1];
                $existing = $this->db->table($map['table'])->where('code', $code)->get(1)->getRowArray();
                if ($existing === null) { $stats['inserted']++; if (! $dryRun) $this->db->table($map['table'])->insert($data + ['code'=>$code,'is_active'=>1,'created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]); continue; }
                $changed = false; foreach ($data as $key => $value) if ((string) ($existing[$key] ?? '') !== (string) ($value ?? '')) { $changed=true; break; }
                if ($changed) { $stats['updated']++; if (! $dryRun) $this->db->table($map['table'])->where('id',$existing['id'])->update($data + ['updated_at'=>date('Y-m-d H:i:s')]); } else $stats['unchanged']++;
            }
            fclose($handle);
            if ($stats['source_rows'] !== (int) $entry['row_count']) throw new RuntimeException("Row count mismatch for {$name}.");
            foreach ($this->db->table($map['table'])->select('id,code,is_active')->get()->getResultArray() as $existing) if (! isset($seen[$existing['code']])) { $stats['missing_from_source']++; $stats['missing_references'] += $this->referenceCount($name, (int) $existing['id']); if ($force && (int)$existing['is_active'] === 1) { $stats['potential_deactivations']++; if (! $dryRun) { $stats['deactivated']++; $this->db->table($map['table'])->where('id',$existing['id'])->update(['is_active'=>0,'updated_at'=>date('Y-m-d H:i:s')]); } } }
            if (! $dryRun) $this->metadata($name, $entry, $map['table']);
            $dryRun ? $this->db->transRollback() : $this->db->transCommit();
        } catch (\Throwable $e) { $this->db->transRollback(); throw $e; }
        return ['catalog_name'=>$name] + $stats;
    }

    private function metadata(string $name, array $entry, string $table): void
    {
        $generatedAt = ! empty($entry['generated_at']) ? date('Y-m-d H:i:s', strtotime((string) $entry['generated_at'])) : null;
        $row = ['catalog_name'=>$name,'source'=>(string)$entry['source'],'source_version'=>(string)$entry['source_version'],'source_checksum'=>strtolower(str_replace('sha256:','',(string)$entry['checksum'])),'source_generated_at'=>$generatedAt,'installed_at'=>date('Y-m-d H:i:s'),'row_count'=>$this->db->table($table)->countAllResults(),'active_row_count'=>$this->db->table($table)->where('is_active',1)->countAllResults(),'metadata_json'=>json_encode(['manifest_schema_version'=>self::SCHEMA_VERSION]),'updated_at'=>date('Y-m-d H:i:s')];
        $old=$this->db->table('sat_catalog_installations')->where('catalog_name',$name)->get(1)->getRowArray();
        $old ? $this->db->table('sat_catalog_installations')->where('id',$old['id'])->update($row) : $this->db->table('sat_catalog_installations')->insert($row+['created_at'=>date('Y-m-d H:i:s')]);
    }

    private function safeFile(string $file): string { $path=$this->root.DIRECTORY_SEPARATOR.$file; if ($file==='' || str_contains($file,'..') || !is_file($path)) throw new RuntimeException('Manifest catalog file is missing.'); return $path; }
    private function referenceCount(string $catalog, int $id): int
    {
        $references = [
            'product-service' => ['item_fiscal_settings', 'sat_product_service_key_id'],
            'units' => ['item_fiscal_settings', 'sat_unit_key_id'],
            'tax-codes' => ['taxes', 'sat_tax_code_id'],
            'tax-factor-types' => ['taxes', 'factor_type_id'],
            'cfdi-uses' => ['fiscal_customer_profiles', 'default_cfdi_use_id'],
            'tax-regimes' => ['fiscal_customer_profiles', 'tax_regime_id'],
            'tax-object-codes' => ['item_fiscal_settings', 'tax_object_code_id'],
        ];
        if (! isset($references[$catalog])) return 0;
        [$table, $column] = $references[$catalog];
        if (! $this->db->tableExists($table) || ! $this->db->fieldExists($column, $table)) return 0;
        return $this->db->table($table)->where($column, $id)->countAllResults();
    }
    private function map(string $name): array
    {
        $simple = fn(string $table, array $headers, callable $data, callable $code) => compact('table','headers','data','code');
        $code = static fn(string $code): ?string => trim($code) === '' ? null : trim($code);
        return match ($name) {
            'product-service' => $simple('sat_product_service_keys',['code','description','valid_from','valid_to'],fn($r)=>['description'=>$r['description'],'normalized_description'=>SatCatalogTextNormalizer::description($r['description']),'valid_from'=>$r['valid_from']?:null,'valid_to'=>$r['valid_to']?:null],fn($v)=>preg_match('/^\d{8}$/',trim($v))?trim($v):null),
            'units' => $simple('sat_unit_keys',['code','name','description','symbol','valid_from','valid_to'],fn($r)=>['name'=>$r['name'],'description'=>$r['description']?:null,'symbol'=>$r['symbol']?:null,'normalized_description'=>SatCatalogTextNormalizer::description($r['name']?:$r['description']),'valid_from'=>$r['valid_from']?:null,'valid_to'=>$r['valid_to']?:null],$code),
            'tax-codes' => $simple('sat_tax_codes',['code','name','description'],fn($r)=>['name'=>$r['name'],'description'=>$r['description']?:null],$code),
            'tax-factor-types' => $simple('sat_tax_factor_types',['code','name'],fn($r)=>['name'=>$r['name']],$code),
            'cfdi-uses' => $simple('sat_cfdi_uses',['code','description','applies_to_individual','applies_to_company','valid_from','valid_to'],fn($r)=>['description'=>$r['description'],'applies_to_individual'=>(int)$r['applies_to_individual'],'applies_to_company'=>(int)$r['applies_to_company'],'valid_from'=>$r['valid_from']?:null,'valid_to'=>$r['valid_to']?:null],$code),
            'tax-regimes' => $simple('sat_tax_regimes',['code','description','applies_to_individual','applies_to_company','valid_from','valid_to'],fn($r)=>['description'=>$r['description'],'applies_to_individual'=>(int)$r['applies_to_individual'],'applies_to_company'=>(int)$r['applies_to_company'],'valid_from'=>$r['valid_from']?:null,'valid_to'=>$r['valid_to']?:null],$code),
            'tax-object-codes' => $simple('sat_tax_object_codes',['code','description','valid_from','valid_to'],fn($r)=>['description'=>$r['description'],'valid_from'=>$r['valid_from']?:null,'valid_to'=>$r['valid_to']?:null],$code),
            'payment-forms' => $simple('sat_payment_forms',['code','name'],fn($r)=>['name'=>$r['name']],$code),
            'payment-methods' => $simple('sat_payment_methods',['code','name'],fn($r)=>['name'=>$r['name']],$code),
            'currencies' => $simple('sat_currencies',['code','name','requires_exchange_rate'],fn($r)=>['name'=>$r['name'],'requires_exchange_rate'=>(int)$r['requires_exchange_rate']],$code),
            default => throw new RuntimeException('Unsupported SAT catalog: '.$name),
        };
    }
}
