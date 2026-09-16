<?php
declare(strict_types=1);
namespace App\Domain\Legacy\Fc2;
final class Fc2IssuerRecord extends Fc2SourceRecord { public function __construct(int$ownerId,int$profileId,array$snapshot,string$hash,public readonly array$data,public readonly array$csdMetadata=[],public readonly array$logoMetadata=[]){parent::__construct('users_perfil',$ownerId,$profileId,$snapshot,$hash);} }
