<?php
declare(strict_types=1);
namespace App\Domain\Fiscal\Cfdi40;
final class CfdiDocument{
 public function __construct(public array$data,public CfdiParty$issuer,public CfdiParty$receiver,public array$concepts,public array$taxTotals){}
 public function get(string$key):?string{$v=$this->data[$key]??null;return$v===null?null:(string)$v;}
}
