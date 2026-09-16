<?php
declare(strict_types=1);
namespace App\Domain\Legacy\Fc2;
final class Fc2SeriesRecord extends Fc2SourceRecord { public function __construct(int$ownerId,int$id,array$snapshot,string$hash,public readonly array$data){parent::__construct('folios',$ownerId,$id,$snapshot,$hash);} }
