<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CodeIgniter\Debug\BaseExceptionHandler;use CodeIgniter\HTTP\RequestInterface;use CodeIgniter\HTTP\ResponseInterface;use Config\Exceptions;
final class MissingArgsInspectableHandler extends BaseExceptionHandler{
 public function handle(Throwable $exception,RequestInterface $request,ResponseInterface $response,int $statusCode,int $exitCode):void{}
 public function sanitize(array$trace):array{return$this->maskSensitiveData($trace,$this->config->sensitiveDataInTrace);}
 public function inspect(Throwable$exception):array{return$this->collectVars($exception,500);}
}
$passed=$failed=0;$assert=static function(bool$c,string$m)use(&$passed,&$failed):void{echo($c?'[PASS] ':'[FAIL] ').$m.PHP_EOL;$c?$passed++:$failed++;};
$handler=new MissingArgsInspectableHandler(config(Exceptions::class));
$trace=[
 ['file'=>'array.php','line'=>1,'args'=>['password'=>'TEST_PASSWORD_SENTINEL','api_key'=>'TEST_API_KEY_SENTINEL','token'=>'TEST_TOKEN_SENTINEL','visible'=>'safe']],
 ['file'=>'missing.php','line'=>2],['file'=>'null.php','line'=>3,'args'=>null],['file'=>'scalar.php','line'=>4,'args'=>'unexpected'],
 ['file'=>'object.php','line'=>5,'args'=>(object)['password'=>'OBJECT_PASSWORD_SENTINEL']],
];
set_error_handler(static function(int$s,string$m):never{throw new ErrorException($m,0,$s);});
try{$sanitized=$handler->sanitize($trace);$secondary=null;}catch(Throwable$e){$sanitized=[];$secondary=$e;}finally{restore_error_handler();}
$assert($secondary===null,'Frames without args produce no secondary warning or exception.');
$assert(is_array($sanitized[0]['args']??null),'Array args remain processable.');
$assert(!array_key_exists('args',$sanitized[1]),'Missing args remains omitted.');
$assert(array_key_exists('args',$sanitized[2])&&$sanitized[2]['args']===null,'Null args remains unchanged.');
$assert(($sanitized[3]['args']??null)==='unexpected','Unexpected scalar args remains unchanged.');
$assert(($sanitized[0]['args']['password']??null)==='******************','Password is masked.');
$assert(($sanitized[0]['args']['api_key']??null)==='******************','API key is masked.');
$assert(($sanitized[0]['args']['token']??null)==='******************','Token is masked.');
$assert(($sanitized[0]['args']['visible']??null)==='safe','Non-sensitive values remain available.');
$assert(($sanitized[4]['args']->password??null)==='******************','Sensitive object properties are masked.');
try{throw new RuntimeException('ORIGINAL_CONTROLLED_EXCEPTION');}catch(RuntimeException$original){set_error_handler(static function(int$s,string$m):never{throw new ErrorException($m,0,$s);});try{$vars=$handler->inspect($original);$processingError=null;}catch(Throwable$e){$vars=[];$processingError=$e;}finally{restore_error_handler();}}
$assert($processingError===null&&($vars['message']??null)==='ORIGINAL_CONTROLLED_EXCEPTION','Original exception remains processable.');
$assert((string)ini_get('zend.exception_ignore_args')==='1','Test process runs with zend.exception_ignore_args=On.');
$encoded=json_encode([$sanitized,$vars],JSON_THROW_ON_ERROR);$assert(!str_contains($encoded,'TEST_PASSWORD_SENTINEL')&&!str_contains($encoded,'TEST_API_KEY_SENTINEL')&&!str_contains($encoded,'TEST_TOKEN_SENTINEL')&&!str_contains($encoded,'OBJECT_PASSWORD_SENTINEL'),'No sensitive sentinel survives sanitized output.');
echo"\n{$passed} passed, {$failed} failed.\n";exit($failed?1:0);
