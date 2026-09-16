<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
helper(['plugin']);
require_once APPPATH . 'ThirdParty/PHP-Hooks/php-hooks.php';

$db = require __DIR__ . '/isolated_database.php';
session()->set('user_id', 1); // Existing local global administrator.

$runModal = static function (array $post): string {
    $request=Config\Services::incomingrequest(null,false);$request->setMethod('POST');$request->setGlobal('post',$post);$request->setGlobal('request',$post);
    $controller = new App\Controllers\Taxes();
    $controller->initController($request, service('response'), service('logger'));
    return $controller->modal_form();
};
$runSave = static function (array $post): array {
    $request=Config\Services::incomingrequest(null,false);$request->setMethod('POST');$request->setGlobal('post',$post);$request->setGlobal('request',$post);service('validation')->reset();$controller=new App\Controllers\Taxes();
    $controller->initController($request,service('response'),service('logger'));
    ob_start();$controller->save();$json=ob_get_clean();return json_decode($json,true,512,JSON_THROW_ON_ERROR);
};

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    if (! $condition) $failures++;
};

try {
    $newHtml = $runModal([]);
    $assert(str_contains($newHtml, 'id="tax-form"') && str_contains($newHtml, 'name="sat_tax_code_id"'), 'POST modal_form without id returns complete HTML');

    $validId = (int) ($db->table('taxes')->select('id')->where('deleted', 0)->orderBy('id')->get(1)->getRow()->id ?? 0);
    if ($validId) {
        $editHtml = $runModal(['id' => $validId]);
        $assert(str_contains($editHtml, 'name="id" value="' . $validId . '"'), 'POST modal_form with valid id returns edit HTML');
    } else {
        echo "[SKIP] no existing tax available for edit modal\n";
    }

    $missingHtml = $runModal(['id' => 99999999]);
    $assert(str_contains($missingHtml, 'id="tax-form"'), 'POST modal_form with missing id returns safe blank HTML');

    $created=[];
    try{
        $admin=$runSave(['title'=>'HTTP administrativo fixture','percentage'=>'10','use_for_administrative'=>'1']);
        $assert(($admin['success']??false)===true,'POST taxes/save creates an administrative tax and returns JSON success');
        if(!empty($admin['id']))$created[]=(int)$admin['id'];
        $edited=$runSave(['id'=>$admin['id'],'title'=>'HTTP administrativo editado','percentage'=>'10','use_for_administrative'=>'1']);
        $assert(($edited['success']??false)===true,'POST taxes/save edits an administrative tax');
        $code=$db->table('sat_tax_codes')->where('code','002')->get()->getRow();$factor=$db->table('sat_tax_factor_types')->where('code','Tasa')->get()->getRow();
        $iva=$runSave(['title'=>'HTTP IVA fixture','percentage'=>'16','use_for_administrative'=>'1','use_for_fiscal'=>'1','sat_tax_code_id'=>$code->id,'fiscal_tax_type'=>'transfer','factor_type_id'=>$factor->id,'xml_rate'=>'0.160000']);
        $assert(($iva['success']??false)===true,'POST taxes/save creates fiscal IVA and returns JSON success');
        if(!empty($iva['id']))$created[]=(int)$iva['id'];
        $stored=$db->table('taxes')->where('id',$iva['id'])->get()->getRow();
        $assert($stored&&$stored->xml_rate==='0.160000'&&(string)$stored->percentage==='16','saved fiscal IVA reopens with exact percentage and XML rate');
    }finally{if(!empty($created))$db->table('taxes')->whereIn('id',$created)->delete();}
} catch (Throwable $e) {
    log_message('error', 'Taxes HTTP characterization failed: {exception}', ['exception' => $e]);
    echo '[FAIL] ' . get_class($e) . ': ' . $e->getMessage() . PHP_EOL;
    $failures++;
}

exit($failures === 0 ? 0 : 1);
