<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Services\Fiscal\Pac\FiscalDocumentStatusPresenter;

final class FiscalInvoiceCenterQueryService
{
    private $db;

    public function __construct($db = null) { $this->db = $db ?: db_connect(); }

    /** This projection deliberately excludes XML and Base64 columns. */
    public function search(array $filters = [], int $limit = 250, int $offset = 0, bool $includeFixtures = false): array
    {
        $builder = $this->baseQuery();
        if(!$includeFixtures)$builder->where('d.is_test_fixture',0);
        $this->applyFilters($builder, $filters);
        $rows = $builder->orderBy('d.issue_date', 'DESC')->orderBy('d.id', 'DESC')
            ->limit(max(1, min(500, $limit)), max(0, $offset))->get()->getResult();
        $presenter = new FiscalDocumentStatusPresenter($this->db);
        foreach ($rows as $row) {
            $projection = $presenter->forDocument((int) $row->id);
            $row->visible_status = $projection->visibleStatus;
            $row->xml_available = $projection->xmlAvailable;
            $row->pdf_available = $projection->pdfAvailable;
            $row->requires_reconciliation = $projection->requiresReconciliation;
            $row->pdf_attempt_unknown = in_array((string) ($row->pdf_attempt_status ?? ''), ['unknown', 'sending', 'pending'], true)
                || (int) ($row->pdf_requires_reconciliation ?? 0) === 1;
            $row->cancellation_status = $this->cancellationStatus($row);
        }
        return $rows;
    }

    public function detail(int $documentId): ?array
    {
        $document = $this->baseQuery()->where('d.id', $documentId)->get(1)->getRow();
        if (!$document) return null;
        $projection = (new FiscalDocumentStatusPresenter($this->db))->forDocument($documentId);
        $document->visible_status = $projection->visibleStatus;
        $document->xml_available = $projection->xmlAvailable;
        $document->pdf_available = $projection->pdfAvailable;
        $document->cancellation_status = $this->cancellationStatus($document);
        return [
            'document' => $document,
            'issuer' => $this->db->table('fiscal_document_issuers')->where('fiscal_document_id', $documentId)->get(1)->getRow(),
            'receiver' => $this->db->table('fiscal_document_receivers')->where('fiscal_document_id', $documentId)->get(1)->getRow(),
            'stamp_attempts' => $this->db->table('fiscal_stamp_attempts')
                ->select('id,status,provider,environment,provider_code,provider_message,retryable,requires_reconciliation,started_at,responded_at completed_at,created_at')
                ->where('fiscal_document_id', $documentId)->orderBy('id', 'DESC')->get()->getResult(),
            'pdf_attempts' => $this->db->table('fiscal_pdf_generation_attempts')
                ->select('id,status,provider,environment,template_code,provider_code,provider_message,request_sent,retryable,requires_reconciliation,started_at,completed_at,created_at')
                ->where('document_id', $documentId)->orderBy('id', 'DESC')->get()->getResult(),
            'artifacts' => array_merge(
                $this->db->table('fiscal_document_artifacts')
                    ->select('id,artifact_type,sha256,byte_size,validation_status,created_at,superseded_at')
                    ->where('fiscal_document_id', $documentId)->orderBy('id', 'DESC')->get()->getResult(),
                $this->db->table('fiscal_document_binary_artifacts')
                    ->select('id,artifact_type,decoded_mime_type,decoded_size_bytes,decoded_sha256,provider,template_code,validation_status,artifact_status,created_at,superseded_at')
                    ->where('fiscal_document_id', $documentId)->orderBy('id', 'DESC')->get()->getResult()
            ),
            'sales' => $this->db->table('fiscal_document_sales a')
                ->select('a.sale_id,a.allocated_total,a.allocation_status,i.display_id')
                ->join('invoices i','i.id=a.sale_id','left')
                ->where('a.fiscal_document_id',$documentId)->get()->getResult(),
            'projection' => $projection,
            'cancellation' => $this->db->table('fiscal_cancellation_requests')->where('fiscal_document_id',$documentId)->orderBy('id','DESC')->get(1)->getRow(),
            'cancellation_movements' => $this->db->table('fiscal_stamp_movements')->select('movement_type,COUNT(*) operation_count,MAX(created_at) last_at',false)->where('fiscal_document_id',$documentId)->whereIn('movement_type',['cancellation_request','cancellation_status_query'])->groupBy('movement_type')->get()->getResult(),
        ];
    }

    private function baseQuery()
    {
        return $this->db->table('fiscal_documents d')->select(implode(',', [
            'd.id','d.invoice_id','d.issuer_profile_id','d.document_type','d.series','d.folio',
            'd.issue_date','d.subtotal','d.transferred_tax_total','d.withheld_tax_total','d.total','d.status','d.environment','d.data_origin','d.is_test_fixture',
            'r.legal_name receiver_name','r.rfc receiver_rfc','s.uuid','s.stamp_date','s.pdf_status',
            's.pac_pdf_artifact_id','bp.provider pdf_provider','c.id cancellation_request_id','c.status cancellation_request_status',
            '(CASE WHEN EXISTS (SELECT 1 FROM '.$this->db->prefixTable('fiscal_cancellation_artifacts').' ca WHERE ca.fiscal_cancellation_request_id=c.id AND ca.artifact_type=\'cancellation_ack\') THEN 1 ELSE 0 END) cancellation_ack_available',
            'c.cancelled_at','pa.status pdf_attempt_status','pa.requires_reconciliation pdf_requires_reconciliation',
            '(SELECT GROUP_CONCAT(COALESCE(NULLIF(i.display_id,\'\'),CONCAT(\'#\',i.id)) ORDER BY i.id SEPARATOR \', \') FROM '.$this->db->prefixTable('fiscal_document_sales').' fds LEFT JOIN '.$this->db->prefixTable('invoices').' i ON i.id=fds.sale_id WHERE fds.fiscal_document_id=d.id) related_sales',
            '(CASE WHEN EXISTS (SELECT 1 FROM '.$this->db->prefixTable('fiscal_document_metadata').' fm WHERE fm.fiscal_document_id=d.id AND fm.metadata_json LIKE \'%"source":"imported_test_fixture"%\') THEN 1 ELSE 0 END) is_imported_fixture',
        ]), false)
            ->join('fiscal_document_receivers r', 'r.fiscal_document_id=d.id', 'left')
            ->join('fiscal_document_stamps s', 's.fiscal_document_id=d.id', 'left')
            ->join('fiscal_document_binary_artifacts bp', 'bp.id=s.pac_pdf_artifact_id AND bp.fiscal_document_id=d.id', 'left')
            ->join($this->db->prefixTable('fiscal_cancellation_requests').' c', 'c.id=(SELECT MAX(cr.id) FROM '.$this->db->prefixTable('fiscal_cancellation_requests').' cr WHERE cr.fiscal_document_id=d.id)', 'left', false)
            ->join($this->db->prefixTable('fiscal_pdf_generation_attempts').' pa', 'pa.id=(SELECT MAX(pga.id) FROM '.$this->db->prefixTable('fiscal_pdf_generation_attempts').' pga WHERE pga.document_id=d.id)', 'left', false)
            ->where('d.deleted', 0);
    }

    private function applyFilters($builder, array $filters): void
    {
        if ($v = trim((string) ($filters['search'] ?? ''))) $builder->groupStart()->like('d.series',$v)->orLike('d.folio',$v)->orLike('s.uuid',$v)->orLike('r.rfc',$v)->orLike('r.legal_name',$v)->groupEnd();
        foreach (['series'=>'d.series','folio'=>'d.folio','uuid'=>'s.uuid','client'=>'r.legal_name','rfc'=>'r.rfc'] as $filter=>$column)
            if ($v = trim((string) ($filters[$filter] ?? ''))) $builder->like($column, $v);
        if ($v = strtoupper(trim((string) ($filters['type'] ?? '')))) {
            $types = match ($v) {
                'I' => ['I','income','ingreso'],
                'E' => ['E','expense','egreso'],
                'P' => ['P','payment','pago'],
                'T' => ['T','transfer','traslado'],
                'N' => ['N','payroll','nomina','nómina'],
                default => [],
            };
            if ($types) $builder->whereIn('d.document_type', $types);
        }
        if ($v = trim((string) ($filters['status'] ?? ''))) $builder->where('d.status', $v);
        if ($v = trim((string) ($filters['pdf_status'] ?? ''))) $builder->where('s.pdf_status', $v);
        if ($v = trim((string) ($filters['cancellation_status'] ?? ''))) $v === 'none' ? $builder->where('c.id', null) : $builder->where('c.status', $v);
        if ($v = trim((string) ($filters['date_from'] ?? ''))) $builder->where('d.issue_date >=', $v.' 00:00:00');
        if ($v = trim((string) ($filters['date_to'] ?? ''))) $builder->where('d.issue_date <=', $v.' 23:59:59');
    }

    private function cancellationStatus(object $row): string
    {
        if (!$row->cancellation_request_id) return 'none';
        return match ((string) $row->cancellation_request_status) {
            'requested'=>'requested','sending','pending'=>'pending',
            'accepted'=>$row->cancelled_at?'cancelled':'accepted',
            'rejected'=>'rejected','unknown'=>'unknown',default=>'unknown',
        };
    }
}
