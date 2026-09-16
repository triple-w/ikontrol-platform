<?php
namespace App\Models\Fiscal;
use App\Models\Crud_model;
class Fiscal_document_artifacts_model extends Crud_model{public function __construct(){parent::__construct('fiscal_document_artifacts');}public function active(int$documentId){return$this->db->table($this->table)->where(['fiscal_document_id'=>$documentId,'artifact_type'=>'pre_xml','superseded_at'=>null])->orderBy('id','DESC')->get(1)->getRow();}}
