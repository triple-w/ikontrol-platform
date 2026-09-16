<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
helper(['general','date_time']);
use App\Services\Fiscal\FiscalPreviewModeGuard;
use App\Services\Legacy\PreviewDatabaseTargetGuard;
$pass=0;$fail=0;$a=static function(bool$v,string$m)use(&$pass,&$fail){echo($v?'[PASS] ':'[FAIL] ').$m.PHP_EOL;$v?$pass++:$fail++;};
$db=db_connect('dold_preview',false);
$identity=(new PreviewDatabaseTargetGuard($db,'dold_preview'))->verify('ikontrol20_dold_preview');
$a($identity['database']==='ikontrol20_dold_preview','physical preview target is exact');
$a((new FiscalPreviewModeGuard($db))->isPreview(),'physical preview database activates fiscal preview mode');
$a($db->table('clients')->countAllResults()===180,'180 commercial clients represent 182 mapped source rows');
$a($db->table('legacy_import_mappings')->where('source_table','clientes')->countAllResults()===182,'182 client mappings exist');
$a($db->table('items')->countAllResults()===255,'255 products exist');
$a($db->table('legacy_import_mappings')->where('source_table','productos')->countAllResults()===255,'255 product mappings exist');
$a($db->table('item_fiscal_settings')->where('status','incomplete')->countAllResults()===255,'all product fiscal settings remain incomplete');
$a($db->table('fiscal_series')->countAllResults()===0,'no fiscal series imported');
$a($db->table('fiscal_documents')->countAllResults()===0&&$db->table('fiscal_stamp_attempts')->countAllResults()===0,'no document or PAC attempt exists');
$f=config('Fiscal');$oldPreview=$f->previewMode;$oldStamp=$f->stampingEnabled;$f->previewMode=true;$f->stampingEnabled=false;
foreach(['assertStampingAllowed','assertCancellationAllowed']as$m){$blocked=false;try{(new FiscalPreviewModeGuard())->$m();}catch(RuntimeException){$blocked=true;}$a($blocked,$m.' blocks before persistence');}
$f->previewMode=$oldPreview;$f->stampingEnabled=$oldStamp;
echo "DoldPreview: {$pass} passed, {$fail} failed".PHP_EOL;exit($fail?1:0);
