<?php
declare(strict_types=1);

namespace App\Contracts\Fiscal\Pac;

use App\Domain\Fiscal\Pac\PacResponse;
use App\Domain\Fiscal\Pac\StampRequest;

interface PacAdapterInterface
{
    public function stamp(StampRequest $request): PacResponse;

    public function getStampStatus(array $query): PacResponse;
}
