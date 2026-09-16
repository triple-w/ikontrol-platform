<?php
declare(strict_types=1);
namespace App\Controllers\Fiscal;
use App\Controllers\Security_Controller;
use App\Services\Fiscal\FiscalIssuerResolver;
use App\Services\Fiscal\Stamps\FiscalStampAccountService;
final class StampBalance extends Security_Controller{
 public function index(){if(!$this->canView())app_redirect('forbidden');$db=db_connect();$companyId=function_exists('get_default_company_id')?(int)get_default_company_id():null;$issuer=(new FiscalIssuerResolver($db))->resolve($companyId?:null);$service=new FiscalStampAccountService($db);$balance=$issuer?$service->getBalance((int)$issuer->id):['available'=>0,'reserved'=>0,'status'=>'missing'];$movements=$issuer?$service->recentForIssuer((int)$issuer->id,20):[];$pacCredits=$this->login_user->is_admin&&$db->tableExists('fiscal_pac_credit_snapshots')?$db->table('fiscal_pac_credit_snapshots')->where(['provider'=>'timbradorxpress','environment'=>'development'])->orderBy('consulted_at','DESC')->get(1)->getRow():null;return$this->template->rander('fiscal/stamps/balance',compact('balance','movements','pacCredits'));}
 private function canView():bool{if($this->login_user->is_admin)return true;$p=is_array($this->login_user->permissions)?$this->login_user->permissions:(@unserialize((string)$this->login_user->permissions)?:[]);return!empty($p['fiscal.stamps.view_balance']);}
}
