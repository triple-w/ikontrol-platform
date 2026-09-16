<?php
declare(strict_types=1);
namespace App\Services\Legacy\Fc2;
use InvalidArgumentException;
final class Fc2DataNormalizer {
 public function text($v):?string{$v=$v===null?null:trim((string)$v);return$v===''?null:$v;}
 public function rfc($v):?string{$v=$this->text($v);return$v===null?null:mb_strtoupper($v,'UTF-8');}
 public function emailComparison($v):?string{$v=$this->text($v);return$v===null?null:mb_strtolower($v,'UTF-8');}
 public function duplicateText($v):?string{$v=$this->text($v);if($v===null)return null;$v=mb_strtoupper($v,'UTF-8');return preg_replace('/\s+/u',' ',$v);}
 public function validRfc(?string$v):bool{return$v!==null&&(bool)preg_match('/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/u',$v);}
 public function validPostalCode(?string$v):bool{return$v!==null&&(bool)preg_match('/^\d{5}$/',$v);}
 public function decimal($v,int$maxScale=4):?string{$v=$this->text($v);if($v===null)return null;if(!preg_match('/^-?\d+(?:\.(\d+))?$/',$v,$m)||strlen($m[1]??'')>$maxScale)throw new InvalidArgumentException('Invalid exact legacy decimal.');return$v;}
 public function canonicalJson(array$v):string{$v=$this->sort($v);return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
 public function hash(array$v):string{return hash('sha256',$this->canonicalJson($v));}
 private function sort($v){if(is_float($v))throw new InvalidArgumentException('Floats are forbidden in FC2 snapshots.');if(!is_array($v))return$v;if(array_is_list($v))return array_map(fn($x)=>$this->sort($x),$v);ksort($v,SORT_STRING);foreach($v as$k=>$x)$v[$k]=$this->sort($x);return$v;}
}
