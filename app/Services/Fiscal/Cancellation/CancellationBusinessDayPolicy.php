<?php
declare(strict_types=1);
namespace App\Services\Fiscal\Cancellation;
use DateTimeImmutable;
final class CancellationBusinessDayPolicy
{
    public function recommendedAt(DateTimeImmutable$requested):DateTimeImmutable{$date=$requested;$added=0;while($added<3){$date=$date->modify('+1 day');if((int)$date->format('N')<6)$added++;}return$date;}
}
