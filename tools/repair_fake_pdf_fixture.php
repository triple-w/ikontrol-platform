<?php
declare(strict_types=1);

require dirname(__DIR__).'/tests/bootstrap.php';
helper(['general','date_time']);

$documentId=14;
$apply=in_array('--apply',$argv,true);
foreach($argv as$argument)if(str_starts_with($argument,'--document='))$documentId=(int)substr($argument,11);
if($documentId<1)throw new RuntimeException('Use --document=<id> con un ID positivo.');

$databaseConfig=config('Database');
$db=Config\Database::connect($databaseConfig->default,false);
$service=new App\Services\Fiscal\Pac\FakePacPdfFixtureRepairService($db);
if(!$apply){
    $candidate=(new App\Services\Fiscal\Pac\PacPdfValidator())->validate(App\Services\Fiscal\Pac\FakePacPdfFixture::base64());
    echo json_encode(['mode'=>'dry-run','inspection'=>$service->inspect($documentId),'replacement'=>[
        'decoded_size_bytes'=>$candidate['decoded_size_bytes'],
        'decoded_sha256'=>$candidate['decoded_sha256'],
        'page_count'=>$candidate['page_count'],
    ]],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

$user=$db->table('users')->where(['is_admin'=>1,'deleted'=>0])->orderBy('id','ASC')->get(1)->getRow();
if(!$user)throw new RuntimeException('No existe un administrador local para auditar la reparación.');
$result=$service->repair($documentId,(int)$user->id);
echo json_encode(['mode'=>'applied','result'=>$result],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
