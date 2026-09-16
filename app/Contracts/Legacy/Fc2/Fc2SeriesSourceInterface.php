<?php
declare(strict_types=1);
namespace App\Contracts\Legacy\Fc2;
interface Fc2SeriesSourceInterface { public function allByOwner(int $ownerId): array; }
