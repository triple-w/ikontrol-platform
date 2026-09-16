<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use RuntimeException;

final class FiscalPreparedDocumentLifecycleService
{
    private const UNCERTAIN_ATTEMPTS = ['pending','sending','unknown','timeout_unknown','transport_unknown','reconciliation_required'];

    public function __construct(private mixed $db = null, private ?FiscalDraftSnapshotHashService $hasher = null)
    {
        $this->db ??= db_connect();
        $this->hasher ??= new FiscalDraftSnapshotHashService();
    }

    public function assertDraftEditable(int $draftId): void
    {
        $draft = $this->db->table('fiscal_drafts')->select('fiscal_document_id')->where('id',$draftId)->get(1)->getRow();
        $documentId = (int)($draft->fiscal_document_id ?? 0);
        if (!$documentId) return;
        $document = $this->db->table('fiscal_documents')->select('id,status')->where('id',$documentId)->get(1)->getRow();
        if ($document && $this->isProtected($documentId,(string)$document->status)) {
            throw new RuntimeException('La preparación fiscal está protegida por un timbre o un resultado PAC pendiente de conciliación.');
        }
    }

    public function invalidateIfSnapshotChanged(int $draftId, int $userId): array
    {
        $draft = $this->db->table('fiscal_drafts')->select('fiscal_document_id')->where('id',$draftId)->get(1)->getRow();
        $documentId = (int)($draft->fiscal_document_id ?? 0);
        if (!$documentId) return ['prepared'=>false,'action'=>'none'];
        $document = $this->db->table('fiscal_documents')->select('id,status,source_snapshot_hash')->where(['id'=>$documentId,'deleted'=>0])->get(1)->getRow();
        if (!$document) {
            $this->db->table('fiscal_drafts')->where(['id'=>$draftId,'fiscal_document_id'=>$documentId])->update(['fiscal_document_id'=>null,'updated_by'=>$userId,'updated_at'=>get_current_utc_time()]);
            return ['prepared'=>false,'action'=>'missing_document_detached'];
        }
        $snapshot = (new FiscalDraftSnapshotService($this->db))->getCompleteFiscalSnapshot($draftId);
        $currentHash = $this->hasher->hash($snapshot);
        $oldHash = (string)$document->source_snapshot_hash;
        if ($oldHash !== '' && hash_equals($oldHash,$currentHash)) return ['prepared'=>true,'action'=>'reused','document_id'=>$documentId,'snapshot_hash'=>$currentHash];
        if ($this->isProtected($documentId,(string)$document->status)) {
            throw new RuntimeException('El borrador cambió, pero su documento fiscal tiene un timbre o un resultado PAC pendiente de conciliación.');
        }
        $this->db->table('fiscal_drafts')->where(['id'=>$draftId,'fiscal_document_id'=>$documentId])->update([
            'fiscal_document_id'=>null,'updated_by'=>$userId,'updated_at'=>get_current_utc_time(),
        ]);
        $this->db->table('fiscal_draft_audit')->insert([
            'fiscal_draft_id'=>$draftId,'sale_id'=>null,'user_id'=>$userId,
            'event'=>'draft_prepared_document_invalidated',
            'summary_json'=>json_encode([
                'draft_id'=>$draftId,'old_document_id'=>$documentId,'reason'=>'snapshot_changed',
                'old_source_snapshot_hash'=>$oldHash?:null,'current_snapshot_hash'=>$currentHash,
            ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'created_at'=>get_current_utc_time(),
        ]);
        return ['prepared'=>false,'action'=>'invalidated','document_id'=>$documentId,'snapshot_hash'=>$currentHash];
    }

    public static function decision(string $oldHash,string $currentHash,bool $hasStamp,array $attempts,string $documentStatus='locked'): string
    {
        if ($oldHash !== '' && hash_equals($oldHash,$currentHash)) return 'reuse';
        if ($hasStamp || $documentStatus === 'stamped') return 'protected';
        foreach ($attempts as $attempt) {
            $status = is_array($attempt) ? (string)($attempt['status']??'') : (string)($attempt->status??'');
            $reconciliation = is_array($attempt) ? (int)($attempt['requires_reconciliation']??0) : (int)($attempt->requires_reconciliation??0);
            $uuid = is_array($attempt) ? (string)($attempt['uuid']??'') : (string)($attempt->uuid??'');
            if ($uuid !== '' || $reconciliation === 1 || in_array($status,self::UNCERTAIN_ATTEMPTS,true)) return 'protected';
        }
        return 'invalidate';
    }

    private function isProtected(int $documentId,string $status): bool
    {
        $hasStamp = (bool)$this->db->table('fiscal_document_stamps')->where('fiscal_document_id',$documentId)->countAllResults();
        $attempts = $this->db->table('fiscal_stamp_attempts')->select('status,requires_reconciliation,uuid')->where('fiscal_document_id',$documentId)->get()->getResultArray();
        return self::decision('different','current',$hasStamp,$attempts,$status) === 'protected';
    }
}