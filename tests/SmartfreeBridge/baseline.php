<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
use App\Services\Baseline\IkontrolBaselineCheckService;
use Config\Database;
$database=$argv[1]??null;if(!$database){fwrite(STDERR,"usage: php tests/SmartfreeBridge/baseline.php <local-copy>\n");exit(2);} 
$base=config('Database')->default;$config=is_array($base)?$base:get_object_vars($base);$db=Database::connect(array_replace($config,['database'=>$database,'DBPrefix'=>'sf_']),false);
echo json_encode((new IkontrolBaselineCheckService($db))->run(),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).PHP_EOL;
