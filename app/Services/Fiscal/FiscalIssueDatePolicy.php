<?php
declare(strict_types=1);
namespace App\Services\Fiscal;
use Config\Fiscal;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
final class FiscalIssueDatePolicy
{
    public function __construct(private ?Fiscal$config=null,private ?FiscalIssueDateNormalizer$normalizer=null){}
    public function validate(string$issueDate,?DateTimeImmutable$now=null):void{
        if(trim($issueDate)==='')throw new RuntimeException('FISCAL_ISSUE_DATE_REQUIRED');
        $config=$this->config??config('Fiscal');$normalizer=$this->normalizer??new FiscalIssueDateNormalizer();$zone=$normalizer->timezone();
        $date=$normalizer->parseCanonical($issueDate);
        $now=$now?->setTimezone($zone)??new DateTimeImmutable('now',$zone);
        if(!$config->allowFutureIssueDate&&$date>$now)throw new RuntimeException('FISCAL_ISSUE_DATE_FUTURE');
        if($date<$now->modify('-'.$config->maxIssueAgeHours.' hours'))throw new RuntimeException('FISCAL_ISSUE_DATE_TOO_OLD');
    }

    public function constraints(?DateTimeImmutable $now=null):array
    {
        $config=$this->config??config('Fiscal');
        $zone=($this->normalizer??new FiscalIssueDateNormalizer())->timezone();
        $now=$now?->setTimezone($zone)??new DateTimeImmutable('now',$zone);
        return [
            'min_issue_datetime'=>$now->modify('-'.$config->maxIssueAgeHours.' hours')->format('Y-m-d\TH:i'),
            'max_issue_datetime'=>$config->allowFutureIssueDate?'':$now->format('Y-m-d\TH:i'),
            'timezone'=>$zone->getName(),
            'validation_message'=>'La fecha CFDI debe estar dentro de la ventana permitida de '.$config->maxIssueAgeHours.' horas y no puede ser futura.',
        ];
    }
}
