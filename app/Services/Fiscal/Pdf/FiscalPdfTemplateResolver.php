<?php
declare(strict_types=1);namespace App\Services\Fiscal\Pdf;
use App\Domain\Fiscal\Pdf\FiscalPdfTemplateSelection;use Config\FiscalPdfProvider;use RuntimeException;
final class FiscalPdfTemplateResolver{
 private $db;public function __construct($db=null,private readonly ?FiscalPdfProvider$config=null){$this->db=$db?:db_connect();}
 public function resolveForDocument(int$documentId,int$issuerId,string$provider,string$documentType):FiscalPdfTemplateSelection{
  $provider=strtolower(trim($provider));$type=strtoupper(trim($documentType));
  $stamp=$this->db->table('fiscal_document_stamps')->select('id,pdf_template,pac_pdf_artifact_id')->where('fiscal_document_id',$documentId)->get(1)->getRow();
  $linked=trim((string)($stamp->pdf_template??''));$selected=false;
  if($stamp&&$this->valid($linked)){
   $selected=(int)($stamp->pac_pdf_artifact_id??0)>0;
   if(!$selected&&$this->db->tableExists('fiscal_pdf_generation_attempts'))$selected=$this->db->table('fiscal_pdf_generation_attempts')->where(['document_id'=>$documentId,'provider'=>$provider,'template_code'=>$linked])->countAllResults()>0;
  }
  if($selected)return new FiscalPdfTemplateSelection($issuerId,$provider,$type,$linked,'document',true);
  return$this->resolve($issuerId,$provider,$type);
 }
 public function resolve(int$issuerId,string$provider,string$documentType):FiscalPdfTemplateSelection{$provider=strtolower(trim($provider));$type=strtoupper(trim($documentType));if(!in_array($type,['I','E','P','T','N'],true))throw new RuntimeException('FISCAL_PDF_DOCUMENT_TYPE_INVALID');if($provider==='fake'&&config('Fiscal')->allowsLabFakePac())return new FiscalPdfTemplateSelection($issuerId,$provider,$type,'RC1-SIMULATED-LOCAL','system',true);$row=$this->db->table('fiscal_issuer_pdf_templates')->where(['issuer_id'=>$issuerId,'provider'=>$provider,'document_type'=>$type,'is_active'=>1])->get(1)->getRow();$code=$row?(string)$row->template_code:($this->config??config('FiscalPdfProvider'))->defaultFor($type);$source=$row?'issuer':'default';if(!$this->valid($code))throw new RuntimeException('FISCAL_PDF_TEMPLATE_MISSING');return new FiscalPdfTemplateSelection($issuerId,$provider,$type,$code,$source,true);}
 public function valid(string$code):bool{return preg_match('/^[A-Za-z0-9._-]{1,40}$/',$code)===1;}
}
