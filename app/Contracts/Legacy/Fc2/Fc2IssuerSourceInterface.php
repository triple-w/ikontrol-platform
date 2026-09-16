<?php
declare(strict_types=1);
namespace App\Contracts\Legacy\Fc2;
use App\Domain\Legacy\Fc2\Fc2IssuerRecord;
interface Fc2IssuerSourceInterface { public function findByRfc(string $rfc, int $expectedOwnerId): Fc2IssuerRecord; }
