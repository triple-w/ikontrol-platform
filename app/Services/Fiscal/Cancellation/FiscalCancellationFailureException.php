<?php
declare(strict_types=1);
namespace App\Services\Fiscal\Cancellation;
use RuntimeException;
use Throwable;
final class FiscalCancellationFailureException extends RuntimeException
{
    public function __construct(string$message,public readonly string$reference,public readonly string$stage,public readonly bool$transportStarted,public readonly string$outcome,Throwable$previous){parent::__construct($message,0,$previous);}
}
