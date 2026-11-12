<?php
declare(strict_types=1);

namespace App\Services\Upgrade;

use RuntimeException;
use Throwable;

/** Directed, idempotent replacement of the legacy one-document-per-draft index. */
final class FiscalDraftDocumentSchemaUpgradeService
{
    public const OLD_INDEX = 'uq_fiscal_document_source_draft';
    public const LOOKUP_INDEX = 'idx_fiscal_document_source_draft';
    public const ACTIVE_INDEX = 'uq_fiscal_document_active_source_draft';
    public const ACTIVE_COLUMN = 'active_source_draft_id';

    private const HISTORICAL = ['superseded', 'rejected', 'cancelled'];
    private const UNCERTAIN = ['pending','sending','unknown','timeout_unknown','transport_unknown','duplicate_reported','response_invalid','stamp_data_invalid','reconciliation_required'];

    public function __construct(private mixed $db = null)
    {
        $this->db ??= db_connect();
    }

    public function inspect(): array
    {
        if (stripos((string)($this->db->DBDriver ?? ''), 'MySQL') === false) {
            return ['compatible'=>true, 'status'=>'NOT_APPLICABLE', 'message'=>'El contrato de índices se valida exclusivamente en MySQL/MariaDB.', 'conflicts'=>[]];
        }
        if (!$this->db->tableExists('fiscal_documents') || !$this->db->fieldExists('source_draft_id', 'fiscal_documents')) {
            return ['compatible'=>false, 'status'=>'BLOCKED', 'message'=>'Falta fiscal_documents.source_draft_id.'];
        }
        $indexes = $this->indexes();
        $compatible = $this->db->fieldExists(self::ACTIVE_COLUMN, 'fiscal_documents')
            && isset($indexes[self::ACTIVE_INDEX]) && $indexes[self::ACTIVE_INDEX]['unique']
            && $indexes[self::ACTIVE_INDEX]['columns'] === [self::ACTIVE_COLUMN]
            && isset($indexes[self::LOOKUP_INDEX]) && !$indexes[self::LOOKUP_INDEX]['unique']
            && $indexes[self::LOOKUP_INDEX]['columns'] === ['source_draft_id']
            && !isset($indexes[self::OLD_INDEX]);
        return [
            'compatible'=>$compatible,
            'status'=>$compatible ? 'READY' : 'UPGRADE_REQUIRED',
            'message'=>$compatible ? 'El ciclo de vida histórico por borrador está habilitado.' : 'El esquema fiscal requiere el upgrade dirigido de documentos por borrador.',
            'column_present'=>$this->db->fieldExists(self::ACTIVE_COLUMN, 'fiscal_documents'),
            'old_unique_present'=>isset($indexes[self::OLD_INDEX]),
            'active_unique_present'=>isset($indexes[self::ACTIVE_INDEX]),
            'lookup_index_present'=>isset($indexes[self::LOOKUP_INDEX]),
            'conflicts'=>$compatible ? [] : $this->conflicts(),
        ];
    }

    public function apply(): array
    {
        if (stripos((string)($this->db->DBDriver ?? ''), 'MySQL') === false) throw new RuntimeException('Este upgrade dirigido sólo admite MySQL/MariaDB.');
        $lock = 'ikontrol_fiscal_draft_document_schema_upgrade';
        $result = $this->db->query('SELECT GET_LOCK(?, 30) acquired', [$lock]);
        try { $acquired = (int)($result->getRow('acquired') ?? 0); } finally { $result->freeResult(); }
        if ($acquired !== 1) throw new RuntimeException('No fue posible adquirir el bloqueo exclusivo del upgrade fiscal.');
        try {
            $before = $this->inspect();
            if ($before['compatible']) return ['changed'=>false, 'before'=>$before, 'after'=>$before];
            $conflicts = $this->conflicts();
            if ($conflicts) throw new RuntimeException('Existen varios documentos protegidos para un mismo borrador: '.implode(',', array_keys($conflicts)).'.');
            $table = $this->quotedTable();
            if (!$this->db->fieldExists(self::ACTIVE_COLUMN, 'fiscal_documents')) {
                $this->db->query("ALTER TABLE {$table} ADD COLUMN `active_source_draft_id` BIGINT UNSIGNED NULL AFTER `source_draft_id`");
                $this->db->resetDataCache();
            }
            $plan = $this->activePlan();
            $this->db->query("UPDATE {$table} SET `active_source_draft_id` = NULL");
            foreach ($plan as $documentId => $draftId) {
                $this->db->table('fiscal_documents')->where('id', $documentId)->update([self::ACTIVE_COLUMN=>$draftId]);
            }
            $indexes = $this->indexes();
            if (!isset($indexes[self::ACTIVE_INDEX])) {
                $this->db->query("CREATE UNIQUE INDEX `".self::ACTIVE_INDEX."` ON {$table} (`active_source_draft_id`)");
            }
            $indexes = $this->indexes();
            if (isset($indexes[self::OLD_INDEX])) $this->db->query("DROP INDEX `".self::OLD_INDEX."` ON {$table}");
            $indexes = $this->indexes();
            if (!isset($indexes[self::LOOKUP_INDEX])) {
                $this->db->query("CREATE INDEX `".self::LOOKUP_INDEX."` ON {$table} (`source_draft_id`)");
            }
            $after = $this->inspect();
            if (!$after['compatible'] || $after['conflicts']) throw new RuntimeException('La verificación posterior del upgrade fiscal falló: '.json_encode($after,JSON_UNESCAPED_SLASHES));
            return ['changed'=>true, 'active_documents'=>count($plan), 'before'=>$before, 'after'=>$after];
        } finally {
            try { $release=$this->db->query('SELECT RELEASE_LOCK(?) released',[$lock]); try{$release->getRow();}finally{$release->freeResult();} } catch (Throwable) {}
        }
    }

    public function assertCompatible(): void
    {
        if (!$this->inspect()['compatible']) {
            throw new RuntimeException('El esquema fiscal no admite reemplazos históricos. Ejecute php spark ikontrol:fiscal-draft-documents-schema --apply --yes.');
        }
    }

    /** @return array<int,int> document id => draft id */
    private function activePlan(): array
    {
        $rows = $this->db->table('fiscal_documents')->select('id,source_draft_id,status,deleted')
            ->where('source_draft_id IS NOT NULL', null, false)->where('deleted', 0)->orderBy('id','ASC')->get()->getResult();
        $byDraft=[];
        foreach($rows as $row) if($this->isProtected($row)) $byDraft[(int)$row->source_draft_id][]=(int)$row->id;
        $plan=[];
        foreach($byDraft as $draftId=>$ids){ if(count($ids)>1) continue; $plan[$ids[0]]=$draftId; }
        return $plan;
    }

    private function conflicts(): array
    {
        if (!$this->db->tableExists('fiscal_stamp_attempts') || !$this->db->tableExists('fiscal_document_stamps')) return [];
        $rows = $this->db->table('fiscal_documents')->select('id,source_draft_id,status,deleted')
            ->where('source_draft_id IS NOT NULL', null, false)->where('deleted',0)->get()->getResult();
        $out=[];
        foreach($rows as $row) if($this->isProtected($row)) $out[(int)$row->source_draft_id][]=(int)$row->id;
        return array_filter($out, static fn(array $ids):bool=>count($ids)>1);
    }

    private function isProtected(object $document): bool
    {
        $id=(int)$document->id;
        if($this->db->tableExists('fiscal_document_stamps') && $this->db->table('fiscal_document_stamps')->where('fiscal_document_id',$id)->countAllResults()) return true;
        if($this->db->tableExists('fiscal_stamp_attempts')){
            $attempts=$this->db->table('fiscal_stamp_attempts')->select('status,requires_reconciliation,uuid')->where('fiscal_document_id',$id)->get()->getResult();
            $definitive=false;
            foreach($attempts as $attempt){
                if(trim((string)($attempt->uuid??''))!=='' || (int)($attempt->requires_reconciliation??0)===1 || in_array((string)$attempt->status,self::UNCERTAIN,true)) return true;
                if(in_array((string)$attempt->status,['rejected','provider_rejected','reconciled_not_found','transport_not_sent_invalidated'],true)) $definitive=true;
            }
            if($definitive) return false;
        }
        return !in_array((string)$document->status,self::HISTORICAL,true);
    }

    private function indexes(): array
    {
        $schema=(string)$this->db->database;
        $table=$this->db->prefixTable('fiscal_documents');
        $rows=$this->db->query('SELECT INDEX_NAME,NON_UNIQUE,COLUMN_NAME,SEQ_IN_INDEX FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX',[$schema,$table])->getResultArray();
        $out=[];
        foreach($rows as $row){$name=(string)$row['INDEX_NAME'];$out[$name]??=['unique'=>(int)$row['NON_UNIQUE']===0,'columns'=>[]];$out[$name]['columns'][]=(string)$row['COLUMN_NAME'];}
        return$out;
    }

    private function quotedTable(): string
    {
        return '`'.str_replace('`','``',$this->db->prefixTable('fiscal_documents')).'`';
    }
}
