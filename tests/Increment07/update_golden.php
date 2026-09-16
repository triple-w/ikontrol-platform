<?php
declare(strict_types=1);
if(($argv[1]??'')!=='--confirm'){fwrite(STDERR,"Refusing to overwrite golden file. Use --confirm explicitly.\n");exit(2);}
require dirname(__DIR__).'/bootstrap.php';$factory=require __DIR__.'/fixture_factory.php';$xml=(new App\Services\Fiscal\Cfdi40\CfdiXmlBuilder())->build($factory());file_put_contents(__DIR__.'/fixtures/mxn_pue_iva16.xml',str_replace("\r\n","\n",$xml));echo"Golden file updated explicitly.\n";
