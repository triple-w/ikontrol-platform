<?php
declare(strict_types=1);namespace App\Services\Fiscal\Pdf;
use App\Domain\Fiscal\Pdf\FiscalPdfTemplateSelection;use Config\FiscalPdfProvider;use RuntimeException;
final class FiscalPdfTemplateResolver{
 private $db;public function __construct($db=null,private readonly ?FiscalPdfProvider$config=null){$this->db=$db?:db_connect();}
 public function resolve(int$issuerId,string$provider,string$documentType):FiscalPdfTemplateSelection{$provider=strtolower(trim($provider));$type=strtoupper(trim($documentType));if(!in_array($type,['I','E','P','T','N'],true))throw new RuntimeException('FISCAL_PDF_DOCUMENT_TYPE_INVALID');$row=$this->db->table('fiscal_issuer_pdf_templates')->where(['issuer_id'=>$issuerId,'provider'=>$provider,'document_type'=>$type,'is_active'=>1])->get(1)->getRow();$code=$row?(string)$row->template_code:($this->config??config('FiscalPdfProvider'))->defaultFor($type);$source=$row?'issuer':'default';if(!$this->valid($code))throw new RuntimeException('FISCAL_PDF_TEMPLATE_MISSING');return new FiscalPdfTemplateSelection($issuerId,$provider,$type,$code,$source,true);}
 public function valid(string$code):bool{return preg_match('/^[A-Za-z0-9._-]{1,40}$/',$code)===1;}
}
