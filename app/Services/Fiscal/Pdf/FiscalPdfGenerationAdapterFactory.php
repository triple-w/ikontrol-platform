<?php
declare(strict_types=1);namespace App\Services\Fiscal\Pdf;
use App\Contracts\Fiscal\Pdf\PacPdfGenerationAdapterInterface;use Config\Fiscal;use Config\FiscalPdfProvider;use RuntimeException;
final class FiscalPdfGenerationAdapterFactory{
 public function __construct(private readonly ?Fiscal$fiscal=null,private readonly ?FiscalPdfProvider$config=null,private readonly ?PacPdfGenerationAdapterInterface$fake=null){}
 public function create():PacPdfGenerationAdapterInterface{$f=$this->fiscal??config('Fiscal');$c=$this->config??config('FiscalPdfProvider');if(!$f->enabled)throw new RuntimeException('FISCAL_PDF_DISABLED');return match($c->provider){'fake'=>$f->runtimeMode==='automated_test'&&ENVIRONMENT==='testing'&&PHP_SAPI==='cli'?($this->fake??throw new RuntimeException('FISCAL_PDF_DISABLED')):throw new RuntimeException('FISCAL_PDF_FAKE_NOT_ALLOWED'),'timbradorxpress-tools'=>$c->enabled?new TimbradorXpressToolsPdfAdapter($c,$f):throw new RuntimeException('FISCAL_PDF_DISABLED'),default=>throw new RuntimeException('FISCAL_PDF_PROVIDER_INVALID')};}
}
