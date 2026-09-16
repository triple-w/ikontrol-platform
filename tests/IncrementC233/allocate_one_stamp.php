<?php
declare(strict_types=1);
define('ROOTPATH',dirname(__DIR__,2).DIRECTORY_SEPARATOR);define('FCPATH',ROOTPATH);require ROOTPATH.'app/Config/Paths.php';$paths=new Config\Paths();define('APPPATH',realpath($paths->appDirectory).DIRECTORY_SEPARATOR);define('SYSTEMPATH',realpath($paths->systemDirectory).DIRECTORY_SEPARATOR);define('WRITEPATH',realpath($paths->writableDirectory).DIRECTORY_SEPARATOR);define('ENVIRONMENT','development');require $paths->systemDirectory.'/Boot.php';CodeIgniter\Boot::bootTest($paths);helper(['general','date_time']);
if(($argv[1]??'')!=='ALLOCATE-C233-ONE'){fwrite(STDERR,"No allocation performed.\n");exit(2);}
$service=new App\Services\Fiscal\Stamps\FiscalStampAccountService(db_connect());
$movement=$service->allocate(2,1,'Crédito local controlado para la única prueba PAC development C2.3.3.',1,'c233-manual-development-stamp-20260813','Incremento C2.3.3');
echo 'movement_id='.$movement->id.PHP_EOL;
