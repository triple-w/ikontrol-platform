<?php
declare(strict_types=1);
namespace App\Domain\Fiscal\Cfdi40;
final class CfdiConcept{public function __construct(public array$data,public array$taxes){}public function get(string$key):?string{$v=$this->data[$key]??null;return$v===null?null:(string)$v;}}
