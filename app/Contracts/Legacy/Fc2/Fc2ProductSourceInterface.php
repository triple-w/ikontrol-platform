<?php
declare(strict_types=1);
namespace App\Contracts\Legacy\Fc2;
interface Fc2ProductSourceInterface { public function iterateByOwner(int $ownerId, int $chunkSize = 200): iterable; }
