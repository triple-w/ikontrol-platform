<?php
declare(strict_types=1);
namespace App\Domain\Legacy\Fc2;
abstract class Fc2SourceRecord {
 public readonly string$sourceSystem;public readonly string$sourceOwnerId;public readonly string$sourceId;public readonly string$sourceHash;
 public function __construct(public readonly string$sourceTable,int$ownerId,int|string$sourceId,public readonly array$snapshot,string$hash){$this->sourceSystem='fc2';$this->sourceOwnerId=(string)$ownerId;$this->sourceId=(string)$sourceId;$this->sourceHash=$hash;}
}
