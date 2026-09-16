<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Pac;

use RuntimeException;

final class PacPdfArtifactService
{
    private $db;

    public function __construct($db = null, private readonly ?PacPdfValidator $validator = null)
    {
        $this->db = $db ?: db_connect();
    }

    public function store(
        int $documentId,
        int $attemptId,
        string $uuid,
        string $base64,
        ?string $template,
        int $userId,
        string $provider = 'timbradorxpress',
        ?int $pdfAttemptId = null,
        bool $allowVersion = false,
        bool $deferActivation = false
    ): object {
        $valid = ($this->validator ?? new PacPdfValidator())->validate($base64);
        $stamp = $this->db->table('fiscal_document_stamps')
            ->select('pac_pdf_artifact_id')
            ->where('fiscal_document_id', $documentId)->get(1)->getRow();
        $existing = ($stamp && $stamp->pac_pdf_artifact_id)
            ? $this->db->table('fiscal_document_binary_artifacts')
                ->where('id', (int) $stamp->pac_pdf_artifact_id)->get(1)->getRow()
            : null;

        if ($existing) {
            $existingTemplate = (string) ($existing->template_code ?? $existing->template ?? '');
            if (hash_equals((string) $existing->decoded_sha256, $valid['decoded_sha256'])
                && (!$allowVersion || $existingTemplate === (string) $template)) {
                return $existing;
            }
            if (!$allowVersion) {
                throw new RuntimeException('Ya existe una representación impresa distinta para este CFDI.');
            }
            $alreadyClassified = (string) $existing->artifact_type !== 'pac_pdf'
                || (string) $existing->validation_status !== 'valid';
            $update = $alreadyClassified
                ? []
                : ['artifact_type' => 'pac_pdf_superseded', 'validation_status' => 'superseded'];
            if ($this->db->fieldExists('artifact_status', 'fiscal_document_binary_artifacts')) {
                $update['artifact_status'] = 'superseded';
            }
            if ($this->db->fieldExists('superseded_at', 'fiscal_document_binary_artifacts')) {
                $update['superseded_at'] = get_current_utc_time();
            }
            if ($update && !$deferActivation) {
                $this->db->table('fiscal_document_binary_artifacts')->where('id', $existing->id)->update($update);
            }
        }

        $data = [
            'fiscal_document_id' => $documentId,
            'stamp_attempt_id' => $attemptId,
            'artifact_type' => 'pac_pdf',
            'content_encoding' => 'base64',
            'content_base64' => $valid['content_base64'],
            'decoded_mime_type' => 'application/pdf',
            'decoded_size_bytes' => $valid['decoded_size_bytes'],
            'decoded_sha256' => $valid['decoded_sha256'],
            'provider' => $provider,
            'template' => $template,
            'uuid' => $uuid,
            'validation_status' => 'valid',
            'created_by' => $userId,
            'created_at' => get_current_utc_time(),
        ];
        if ($this->db->fieldExists('pdf_generation_attempt_id', 'fiscal_document_binary_artifacts')) {
            $data['pdf_generation_attempt_id'] = $pdfAttemptId;
        }
        if ($this->db->fieldExists('template_code', 'fiscal_document_binary_artifacts')) {
            $data['template_code'] = $template;
        }
        if ($this->db->fieldExists('artifact_status', 'fiscal_document_binary_artifacts')) {
            $data['artifact_status'] = $deferActivation ? 'pending' : 'active';
        }
        if ($this->db->fieldExists('superseded_at', 'fiscal_document_binary_artifacts')) {
            $data['superseded_at'] = null;
        }
        $this->db->table('fiscal_document_binary_artifacts')->insert($data);
        $data['id'] = (int) $this->db->insertID();
        unset($valid['bytes']);
        return (object) $data;
    }

    public function read(int $documentId): array
    {
        $stamp = $this->db->table('fiscal_document_stamps')
            ->select('pac_pdf_artifact_id')
            ->where('fiscal_document_id', $documentId)->get(1)->getRow();
        $row = ($stamp && $stamp->pac_pdf_artifact_id)
            ? $this->db->table('fiscal_document_binary_artifacts')->where([
                'id' => (int) $stamp->pac_pdf_artifact_id,
                'fiscal_document_id' => $documentId,
                'artifact_type' => 'pac_pdf',
                'validation_status' => 'valid',
            ])->get(1)->getRow()
            : null;
        if (!$row) {
            throw new RuntimeException('La representación impresa del PAC no está disponible.');
        }
        $valid = ($this->validator ?? new PacPdfValidator())->validate((string) $row->content_base64);
        if (!hash_equals((string) $row->decoded_sha256, $valid['decoded_sha256'])
            || (int) $row->decoded_size_bytes !== $valid['decoded_size_bytes']) {
            throw new RuntimeException('La representación impresa no supera la verificación de integridad.');
        }
        return ['artifact' => $row, 'bytes' => $valid['bytes']];
    }
}
