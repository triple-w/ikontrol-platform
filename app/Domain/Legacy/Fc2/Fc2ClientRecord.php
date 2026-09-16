<?php
declare(strict_types=1);
namespace App\Domain\Legacy\Fc2;
final class Fc2ClientRecord extends Fc2SourceRecord { public function __construct(int$ownerId,int$id,array$snapshot,string$hash,public readonly array$data){parent::__construct('clientes',$ownerId,$id,$snapshot,$hash);} }
