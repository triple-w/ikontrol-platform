<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Pac;

use RuntimeException;
use Throwable;

/**
 * Local maintenance only. It never invokes a PAC or creates a stamp attempt.
 */
final class FakePacPdfFixtureRepairService
{
    private $db;

    public function __construct($db=null)
    {
        $this->db=$db?:db_connect();
    }

    public function inspect(int $documentId):array
    {
        $document=$this->db->table('fiscal_documents')->where(['id'=>$documentId,'deleted'=>0])->get(1)->getRow();
        $stamp=$this->db->table('fiscal_document_stamps')->where('fiscal_document_id',$documentId)->get(1)->getRow();
        if(!$document||!$stamp)throw new RuntimeException('El documento fiscal o su timbre no existe.');
        $attempt=$this->db->table('fiscal_stamp_attempts')->where('id',(int)$stamp->stamp_attempt_id)->get(1)->getRow();
        $artifact=$stamp->pac_pdf_artifact_id
            ?$this->db->table('fiscal_document_binary_artifacts')->where('id',(int)$stamp->pac_pdf_artifact_id)->get(1)->getRow()
            :null;
        $xml=$this->db->table('fiscal_document_artifacts')->where('id',(int)$stamp->stamped_xml_artifact_id)->get(1)->getRow();
        if(!$attempt||!$artifact||!$xml)throw new RuntimeException('El timbre no tiene todos sus artefactos históricos.');
        if(strtolower((string)$stamp->provider)!=='fake'||strtolower((string)$stamp->environment)!=='local')throw new RuntimeException('La reparación sólo está permitida para FakePacAdapter en entorno local.');
        $valid=true;$validationError=null;
        try{(new PacPdfValidator())->validate((string)$artifact->content_base64);}catch(Throwable$e){$valid=false;$validationError='PDF_STRUCTURE_INVALID';}
        return[
            'document_id'=>$documentId,
            'document_status'=>(string)$document->status,
            'stamp_id'=>(int)$stamp->id,
            'uuid'=>(string)$stamp->uuid,
            'attempt_id'=>(int)$attempt->id,
            'attempt_count'=>$this->db->table('fiscal_stamp_attempts')->where('fiscal_document_id',$documentId)->countAllResults(),
            'xml_artifact_id'=>(int)$xml->id,
            'xml_sha256'=>(string)$xml->sha256,
            'pdf_artifact_id'=>(int)$artifact->id,
            'pdf_size'=>(int)$artifact->decoded_size_bytes,
            'pdf_sha256'=>(string)$artifact->decoded_sha256,
            'pdf_valid'=>$valid,
            'pdf_validation_error'=>$validationError,
            'provider'=>(string)$stamp->provider,
            'environment'=>(string)$stamp->environment,
        ];
    }

    public function repair(int $documentId,int $userId):array
    {
        if(config('Fiscal')->allowRealPac||config('Fiscal')->pacAdapter!=='fake')throw new RuntimeException('La reparación exige PAC falso y llamadas reales deshabilitadas.');
        $before=$this->inspect($documentId);
        $fixture=(new PacPdfValidator())->validate(FakePacPdfFixture::base64());
        if($before['pdf_valid']){
            if(hash_equals($before['pdf_sha256'],$fixture['decoded_sha256']))return['changed'=>false,'before'=>$before,'after'=>$before];
            throw new RuntimeException('El PDF actual ya es válido; no puede sustituirse automáticamente.');
        }

        $this->db->transBegin();
        try{
            $locked=$this->db->query('SELECT id FROM '.$this->db->prefixTable('fiscal_documents').' WHERE id = ? FOR UPDATE',[$documentId])->getRow();
            if(!$locked)throw new RuntimeException('No fue posible bloquear el documento fiscal.');
            $current=$this->inspect($documentId);
            if($current['attempt_id']!==$before['attempt_id']||$current['pdf_artifact_id']!==$before['pdf_artifact_id'])throw new RuntimeException('El documento cambió durante la reparación.');
            $this->db->table('fiscal_document_binary_artifacts')->where('id',$before['pdf_artifact_id'])->update([
                'artifact_type'=>'pac_pdf_replaced',
                'validation_status'=>'invalid_structure',
            ]);
            $new=(new PacPdfArtifactService($this->db))->store(
                $documentId,
                $before['attempt_id'],
                $before['uuid'],
                $fixture['content_base64'],
                'Principal',
                $userId,
                'fake',
                null,
                true
            );
            $this->db->table('fiscal_document_stamps')->where('id',$before['stamp_id'])->update([
                'pac_pdf_artifact_id'=>$new->id,
                'pdf_status'=>'valid',
                'pdf_template'=>'Principal',
            ]);
            $this->db->table('fiscal_documents')->where('id',$documentId)->update([
                'status'=>'stamped',
                'updated_at'=>get_current_utc_time(),
            ]);
            $this->db->table('fiscal_document_audit')->insert([
                'fiscal_document_id'=>$documentId,
                'invoice_id'=>$this->db->table('fiscal_documents')->select('invoice_id')->where('id',$documentId)->get(1)->getRow()->invoice_id,
                'user_id'=>$userId,
                'action'=>'fake_pdf_fixture_replaced',
                'reason'=>json_encode([
                    'old_artifact_id'=>$before['pdf_artifact_id'],
                    'old_sha256'=>$before['pdf_sha256'],
                    'old_size'=>$before['pdf_size'],
                    'new_artifact_id'=>(int)$new->id,
                    'new_sha256'=>(string)$new->decoded_sha256,
                    'new_size'=>(int)$new->decoded_size_bytes,
                    'attempt_id'=>$before['attempt_id'],
                ],JSON_UNESCAPED_SLASHES),
                'created_at'=>get_current_utc_time(),
            ]);
            if(!$this->db->transStatus())throw new RuntimeException('La transacción de reparación no pudo confirmarse.');
            $this->db->transCommit();
        }catch(Throwable $e){
            $this->db->transRollback();
            throw $e;
        }

        $after=$this->inspect($documentId);
        if($after['attempt_id']!==$before['attempt_id']||$after['attempt_count']!==$before['attempt_count']||$after['uuid']!==$before['uuid']||$after['xml_sha256']!==$before['xml_sha256'])throw new RuntimeException('La invariancia fiscal no se conservó después de reparar el fixture.');
        return['changed'=>true,'before'=>$before,'after'=>$after];
    }
}
