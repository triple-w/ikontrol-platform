<?php
declare(strict_types=1);namespace App\Services\Fiscal\Pdf;
use RuntimeException;
final class FiscalIssuerBrandingService{
 public function __construct(private readonly mixed$resolver=null,private readonly int$maxBytes=2097152,private readonly mixed$db=null){}
 public function logoBase64(int$issuerId):?string{
  $resolved=is_callable($this->resolver)?($this->resolver)($issuerId):$this->companyLogoPath($issuerId);
  if(!is_string($resolved)||$resolved===''||!is_file($resolved))return null;
  $bytes=file_get_contents($resolved);if($bytes===false||strlen($bytes)>$this->maxBytes)return null;
  $mime=(new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);if(!in_array($mime,['image/png','image/jpeg'],true))return null;
  return base64_encode($bytes);
 }
 private function companyLogoPath(int$issuerId):string{
  $db=$this->db?:db_connect();
  $issuer=$db->table('fiscal_profiles')->select('company_id')->where('id',$issuerId)->get(1)->getRow();
  if(!$issuer||!(int)$issuer->company_id)return '';
  $company=$db->table('company')->select('logo')->where(['id'=>(int)$issuer->company_id,'deleted'=>0])->get(1)->getRow();
  $files=@unserialize((string)($company->logo??''));$file=is_array($files)?($files[0]??null):null;
  if(!is_array($file))return '';
  $source=get_source_url_of_file($file,get_setting('system_file_path'),'thumbnail',true);
  if(is_file($source))return $source;
  $path=realpath(FCPATH.ltrim($source,'/\\'));return $path&&is_file($path)?$path:'';
 }
}
