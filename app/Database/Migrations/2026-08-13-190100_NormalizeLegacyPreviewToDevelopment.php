<?php
declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class NormalizeLegacyPreviewToDevelopment extends Migration
{
    public function up():void
    {
        foreach(['fiscal_profiles','fiscal_series','fiscal_drafts','fiscal_documents','fiscal_document_stamps','fiscal_stamp_attempts','fiscal_cancellation_requests','fiscal_pdf_generation_attempts','fiscal_pac_configurations'] as $table){
            if($this->db->tableExists($table)&&$this->db->fieldExists('environment',$table)){
                $this->db->table($table)->whereIn('environment',['preview','legacy'])->update(['environment'=>'development']);
            }
        }
    }

    public function down():void
    {
        // Environment normalization is intentionally not guessed backwards.
    }
}
