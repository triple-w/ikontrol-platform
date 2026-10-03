<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use RuntimeException;
use Throwable;

/** Directed repair for issuer profiles created before fiscal environments existed. */
final class LegacyFiscalEnvironmentNormalizer
{
    public function __construct(private mixed $db = null, private ?object $fiscal = null)
    {
        $this->db ??= db_connect();
        $this->fiscal ??= config('Fiscal');
    }

    /** @return array{environment:string,matched:int,updated:int,conflicts:list<array<string,int|string>>,profile_ids:list<int>,dry_run:bool} */
    public function normalize(bool $dryRun = true): array
    {
        if (! $this->db->tableExists('fiscal_profiles')) {
            throw new RuntimeException('La tabla fiscal_profiles no existe.');
        }
        $environment = FiscalRuntimeContext::fiscalEnvironment($this->fiscal);
        if (! in_array($environment, [FiscalRuntimeContext::DEVELOPMENT, FiscalRuntimeContext::PRODUCTION], true)) {
            throw new RuntimeException('El ambiente fiscal configurado no es válido para normalizar perfiles legacy.');
        }

        $legacy = $this->db->table('fiscal_profiles')
            ->select('id,company_id')
            ->where(['profile_type' => 'issuer', 'environment' => 'legacy'])
            ->orderBy('id')
            ->get()->getResultArray();
        $conflicts = [];
        foreach ($legacy as $profile) {
            $existing = $this->db->table('fiscal_profiles')
                ->select('id')
                ->where('profile_type', 'issuer')
                ->where('environment', $environment)
                ->where('id !=', (int) $profile['id']);
            $profile['company_id'] === null
                ? $existing->where('company_id', null)
                : $existing->where('company_id', (int) $profile['company_id']);
            $row = $existing->orderBy('id')->get(1)->getRow();
            if ($row) {
                $conflicts[] = [
                    'legacy_profile_id' => (int) $profile['id'],
                    'configured_profile_id' => (int) $row->id,
                    'company_id' => (int) ($profile['company_id'] ?? 0),
                ];
            }
        }
        if ($conflicts !== []) {
            throw new RuntimeException('Existen emisores legacy con un emisor ya configurado para el ambiente destino; se requiere revisión manual.');
        }

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $legacy);
        if ($dryRun || $ids === []) {
            return ['environment'=>$environment, 'matched'=>count($ids), 'updated'=>0, 'conflicts'=>[], 'profile_ids'=>$ids, 'dry_run'=>$dryRun];
        }

        $this->db->transBegin();
        try {
            $this->db->table('fiscal_profiles')
                ->whereIn('id', $ids)
                ->where(['profile_type'=>'issuer', 'environment'=>'legacy'])
                ->update(['environment'=>$environment]);
            $updated = $this->db->affectedRows();
            if (! $this->db->transStatus()) throw new RuntimeException('No fue posible normalizar el ambiente de los emisores legacy.');
            $this->db->transCommit();
            return ['environment'=>$environment, 'matched'=>count($ids), 'updated'=>$updated, 'conflicts'=>[], 'profile_ids'=>$ids, 'dry_run'=>false];
        } catch (Throwable $error) {
            $this->db->transRollback();
            throw $error;
        }
    }
}
