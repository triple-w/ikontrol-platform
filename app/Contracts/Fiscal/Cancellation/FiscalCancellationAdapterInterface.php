<?php
declare(strict_types=1);

namespace App\Contracts\Fiscal\Cancellation;

interface FiscalCancellationAdapterInterface
{
    /** @return array{status:string,code:?string,message:string,ack_base64:?string,request_sent:bool} */
    public function cancel(array $request): array;

    /** @return array{status:string,code:?string,message:string,ack_base64:?string,request_sent:bool} */
    public function query(array $request): array;
}
