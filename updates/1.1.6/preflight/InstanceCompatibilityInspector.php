<?php
declare(strict_types=1);

namespace Ikontrol\Compatibility;

use RuntimeException;
use Throwable;

/** Standalone, read-only pre-deployment inspector. It does not load Platform 1.1.6 into the target. */
final class InstanceCompatibilityInspector
{
    private array $tables=[];
    private string $prefix;
    private string $driver;

    public function __construct(private mixed $db,private string $targetRoot,private array $contract,private array $runtime=[])
    {
        $root=realpath($targetRoot);
        if($root===false||!is_dir($root)||is_link($targetRoot)||is_link($root))throw new RuntimeException('INSTANCE_ROOT_UNSAFE');
        $this->targetRoot=rtrim(str_replace('\\','/',$root),'/');
        $this->prefix=(string)($db->DBPrefix??'');
        if(!preg_match('/\A[A-Za-z0-9_]*\z/',$this->prefix))throw new RuntimeException('DATABASE_PREFIX_UNSAFE');
        $this->driver=strtolower((string)($db->DBDriver??''));
        $this->assertContract();
    }

    public function inspect(?int $invoiceId=null):array
    {
        $this->enableReadOnly();
        $versions=$this->versions();$files=$this->files();$schema=$this->schema();
        $data=['series'=>$this->series(),'issuer_wallets'=>$this->wallets(),'payment_mappings'=>$this->mappings(),'products'=>$this->products($invoiceId),'baseline'=>$this->baseline()];
        $sections=['versions'=>$versions,'files'=>$files,'schema'=>$schema,'runtime'=>$this->runtime,'data'=>$data];
        [$status,$blockers,$reviews,$unverified]=$this->decision($sections);
        return[
            'schema_version'=>1,'inspector_version'=>(string)$this->contract['inspector_version'],'target_version'=>(string)$this->contract['target_version'],
            'status'=>$status,'compatible'=>$status==='COMPATIBLE','read_only'=>true,'generated_at'=>gmdate('c'),
            'versions'=>$versions,'files'=>$files,'schema'=>$schema,'runtime'=>$this->runtime,'data'=>$data,
            'blockers'=>$blockers,'review_required'=>$reviews,'not_verified'=>$unverified,
            'privacy'=>['contains_secrets'=>false,'contains_rfc'=>false,'contains_names'=>false,'contains_xml'=>false,'contains_certificates'=>false],
        ];
    }

    private function versions():array
    {
        $source=(array)$this->contract['source'];$canonical=null;$path=$this->targetRoot.'/app/Config/Version.php';
        if(is_file($path)&&preg_match("/VERSION\s*=\s*['\"]([^'\"]+)['\"]/",(string)file_get_contents($path),$m))$canonical=$m[1];
        $registered=null;
        if($this->tableExists('app_schema_versions')&&$this->hasColumns('app_schema_versions',['version'])){
            $values=[];foreach($this->rows('SELECT version FROM '.$this->q('app_schema_versions')) as$row){$v=preg_replace('/^ikontrol-/i','',trim((string)$row['version']));if(is_string($v)&&preg_match('/^\d+\.\d+\.\d+$/',$v))$values[]=$v;}usort($values,'version_compare');if($values)$registered=end($values);
        }
        return['status'=>$canonical!==null&&$registered!==null?'VERIFIED':'NOT_VERIFIED','canonical'=>$canonical,'registered'=>$registered,'expected_source_version'=>(string)$source['version'],'expected_source_commit'=>(string)$source['commit'],'match'=>$canonical===$source['version']&&$registered===$source['version']];
    }

    private function files():array
    {
        $expected=(array)$this->contract['files'];$matched=[];$modified=[];$missing=[];
        foreach($expected as$path=>$definition){$file=$this->file($path);if(!is_file($file)){$missing[]=$path;continue;}$actual=$this->hashFile($file,$path,(string)$definition['hash_mode']);if(hash_equals((string)$definition['sha256'],$actual))$matched[]=$path;else$modified[]=['path'=>$path,'expected_sha256'=>(string)$definition['sha256'],'actual_sha256'=>$actual];}
        $extra=[];foreach((array)$this->contract['scopes']as$scope){$base=$this->file((string)$scope);if(!is_dir($base)||is_link($base))continue;$iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base,\FilesystemIterator::SKIP_DOTS));foreach($iterator as$item){if(!$item->isFile()||$item->isLink())continue;$logical=str_replace('\\','/',substr($item->getPathname(),strlen($this->targetRoot)+1));if(!isset($expected[$logical]))$extra[]=$logical;}}
        sort($matched);sort($missing);sort($extra);usort($modified,static fn($a,$b)=>strcmp($a['path'],$b['path']));
        return['status'=>$missing!==[]?'BLOCKED':(($modified!==[]||$extra!==[])?'REVIEW_REQUIRED':'VERIFIED'),'expected_count'=>count($expected),'matched_count'=>count($matched),'modified'=>$modified,'missing'=>$missing,'extra_in_scope'=>$extra,'origin_tree_sha256'=>$this->contract['source']['tree_sha256']??null];
    }

    private function schema():array
    {
        $required=(array)$this->contract['required_schema'];$result=[];$shape=[];$missing=[];$invalid=[];
        foreach((array)$required['tables']as$table=>$definition){$exists=$this->tableExists($table);$columns=$exists?$this->columns($table):[];$indexes=$exists?$this->indexes($table):[];$missingColumns=[];foreach((array)$definition['columns']as$column)if(!isset($columns[strtolower((string)$column)]))$missingColumns[]=$column;$invalidIndexes=[];foreach((array)($definition['indexes']??[])as$name=>$expected){$actual=$indexes[strtolower((string)$name)]??null;if($actual===null||$actual['columns']!==$expected['columns']||$actual['unique']!==(bool)$expected['unique'])$invalidIndexes[]=$name;}$result[$table]=['exists'=>$exists,'missing_columns'=>$missingColumns,'invalid_indexes'=>$invalidIndexes];if(!$exists||$missingColumns)$missing[]=$table;if($invalidIndexes)$invalid[]=$table;$shape[$table]=['columns'=>$columns,'indexes'=>$indexes];}
        return['status'=>$missing===[]&&$invalid===[]?'VERIFIED':'BLOCKED','fingerprint_sha256'=>hash('sha256',$this->stableJson($shape)),'tables'=>$result,'missing_or_incomplete_tables'=>$missing,'invalid_index_tables'=>$invalid];
    }

    private function series():array
    {
        if(!$this->hasColumns('fiscal_series',['issuer_profile_id','document_type','series','environment','current_folio','is_default','is_active','deleted']))return['status'=>'NOT_VERIFIED'];
        $rows=$this->rows('SELECT issuer_profile_id,document_type,series,environment,current_folio,is_default,is_active,deleted FROM '.$this->q('fiscal_series'));
        $summary=[];$legacy=0;$invalid=0;$active=0;$identity=[];foreach($rows as$row){$env=strtolower(trim((string)$row['environment']));$bucket=in_array($env,['development','production','legacy'],true)?$env:'other';$summary[$bucket]=($summary[$bucket]??0)+1;$legacy+=$bucket==='legacy'?1:0;$invalid+=$bucket==='other'?1:0;$active+=((int)$row['is_active']===1&&(int)$row['deleted']===0)?1:0;$identity[]=implode('|',[(int)$row['issuer_profile_id'],$row['document_type'],$row['series'],$env,(int)$row['current_folio'],(int)$row['is_default'],(int)$row['is_active'],(int)$row['deleted']]);}sort($identity);
        return['status'=>$invalid?'BLOCKED':($legacy?'REVIEW_REQUIRED':'VERIFIED'),'total'=>count($rows),'active'=>$active,'by_environment'=>$summary,'legacy_count'=>$legacy,'invalid_environment_count'=>$invalid,'identity_and_folio_fingerprint'=>hash('sha256',implode("\n",$identity))];
    }

    private function wallets():array
    {
        if(!$this->hasColumns('fiscal_profiles',['id','environment','profile_type'])||!$this->hasColumns('fiscal_stamp_accounts',['issuer_profile_id','environment','status']))return['status'=>'NOT_VERIFIED'];
        $issuerSql="SELECT id,environment FROM {$this->q('fiscal_profiles')} WHERE profile_type='issuer'";if($this->hasColumns('fiscal_profiles',['deleted']))$issuerSql.=' AND deleted=0';$issuers=$this->rows($issuerSql);$accounts=$this->rows('SELECT issuer_profile_id,environment,status FROM '.$this->q('fiscal_stamp_accounts'));
        $issuerEnv=[];foreach($issuers as$row)$issuerEnv[(string)$row['id']]=strtolower((string)$row['environment']);$groups=[];$wrong=0;foreach($accounts as$row){$key=(string)$row['issuer_profile_id'].'|'.strtolower((string)$row['environment']);$groups[$key]=($groups[$key]??0)+1;if(isset($issuerEnv[(string)$row['issuer_profile_id']])&&$issuerEnv[(string)$row['issuer_profile_id']]!==strtolower((string)$row['environment']))$wrong++;}$ambiguous=count(array_filter($groups,static fn($n)=>$n!==1));$without=0;foreach($issuerEnv as$id=>$env)if(($groups[$id.'|'.$env]??0)!==1)$without++;
        return['status'=>$ambiguous||$wrong?'BLOCKED':($without?'REVIEW_REQUIRED':'VERIFIED'),'issuer_count'=>count($issuers),'wallet_count'=>count($accounts),'ambiguous_identity_environment_groups'=>$ambiguous,'issuer_environment_mismatches'=>$wrong,'issuers_without_exact_wallet'=>$without];
    }

    private function mappings():array
    {
        if(!$this->hasColumns('payment_methods',['id','deleted','available_on_invoice'])||!$this->hasColumns('fiscal_payment_method_mappings',['payment_method_id','sat_payment_form_code','is_active'])||!$this->hasColumns('sat_payment_forms',['code','is_active']))return['status'=>'NOT_VERIFIED'];
        $methods=$this->rows('SELECT id FROM '.$this->q('payment_methods').' WHERE deleted=0 AND available_on_invoice=1');$maps=$this->rows('SELECT payment_method_id,sat_payment_form_code,is_active FROM '.$this->q('fiscal_payment_method_mappings'));$forms=[];foreach($this->rows('SELECT code,is_active FROM '.$this->q('sat_payment_forms'))as$row)$forms[(string)$row['code']]=(int)$row['is_active'];$by=[];foreach($maps as$row)if((int)$row['is_active']===1)$by[(string)$row['payment_method_id']][]=(string)$row['sat_payment_form_code'];$missing=$duplicate=$inactive=0;foreach($methods as$row){$m=$by[(string)$row['id']]??[];if(!$m)$missing++;elseif(count($m)!==1)$duplicate++;elseif(($forms[$m[0]]??0)!==1)$inactive++;}
        $issues=$missing+$duplicate+$inactive;return['status'=>$issues?'REVIEW_REQUIRED':'VERIFIED','eligible_methods'=>count($methods),'missing_mapping'=>$missing,'duplicate_active_mapping'=>$duplicate,'inactive_or_missing_sat_form'=>$inactive];
    }

    private function products(?int$invoiceId):array
    {
        if(!$this->hasColumns('item_fiscal_settings',['item_id','commercial_unit','fiscal_description','sat_product_service_key_id','sat_unit_key_id','tax_object_code_id','status','deleted','is_default']))return['status'=>'NOT_VERIFIED','invoice'=>['status'=>'NOT_VERIFIED']];
        $sql='SELECT item_id,commercial_unit,fiscal_description,sat_product_service_key_id,sat_unit_key_id,tax_object_code_id,status FROM '.$this->q('item_fiscal_settings').' WHERE deleted=0 AND is_default=1';$rows=$this->rows($sql);$issues=['description'=>0,'commercial_unit'=>0,'product_service_key'=>0,'unit_key'=>0,'tax_object'=>0,'inactive'=>0];$ready=[];foreach($rows as$row){$bad=false;foreach(['fiscal_description'=>'description','commercial_unit'=>'commercial_unit','sat_product_service_key_id'=>'product_service_key','sat_unit_key_id'=>'unit_key','tax_object_code_id'=>'tax_object']as$field=>$key)if(trim((string)($row[$field]??''))===''){$issues[$key]++;$bad=true;}if((string)$row['status']==='inactive'){$issues['inactive']++;$bad=true;}if(!$bad)$ready[(string)$row['item_id']]=true;}
        $invoice=['status'=>'NOT_REQUESTED'];if($invoiceId!==null){if($invoiceId<1||!$this->hasColumns('invoice_items',['invoice_id','item_id','deleted']))$invoice=['status'=>'NOT_VERIFIED'];else{$items=$this->rows('SELECT item_id FROM '.$this->q('invoice_items').' WHERE invoice_id=? AND deleted=0',[$invoiceId]);$missing=0;foreach($items as$row)if(!isset($ready[(string)$row['item_id']]))$missing++;$invoice=['status'=>$missing?'REVIEW_REQUIRED':'VERIFIED','line_count'=>count($items),'lines_without_complete_master'=>$missing,'invoice_id'=>$invoiceId];}}
        return['status'=>array_sum($issues)?'REVIEW_REQUIRED':'VERIFIED','default_configurations'=>count($rows),'field_issue_counts'=>$issues,'invoice'=>$invoice,'note'=>'Tax semantics and rounding remain NOT_VERIFIED until the isolated 1.1.6 preflight runner is executed.'];
    }

    private function baseline():array
    {
        if(!$this->hasColumns('project_status',['deleted']))return['status'=>'NOT_VERIFIED','project_status_catalog'=>'NOT_VERIFIED','deployment_blocker'=>false];$count=(int)($this->rows('SELECT COUNT(*) total FROM '.$this->q('project_status').' WHERE deleted=0')[0]['total']??0);return['status'=>$count>0?'VERIFIED':'REVIEW_REQUIRED','project_status_catalog'=>$count>0?'POPULATED':'EMPTY','deployment_blocker'=>false,'functional_approval_blocker'=>$count===0];
    }

    private function decision(array$sections):array
    {
        $block=[];$review=[];$unknown=[];$v=$sections['versions'];if($v['status']==='NOT_VERIFIED')$unknown[]='VERSION_NOT_VERIFIED';elseif(!$v['match'])$block[]='SOURCE_VERSION_MISMATCH';
        if($sections['files']['status']==='BLOCKED')$block[]='OFFICIAL_SOURCE_FILE_MISSING';elseif($sections['files']['status']==='REVIEW_REQUIRED')$review[]='LOCAL_CODE_DIFFERENCE';
        if($sections['schema']['status']==='BLOCKED')$block[]='FISCAL_SCHEMA_PREREQUISITE_FAILED';
        $runtimeStatus=$sections['runtime']['status']??'NOT_VERIFIED';if($runtimeStatus==='NOT_VERIFIED')$unknown[]='FISCAL_RUNTIME_NOT_VERIFIED';elseif($runtimeStatus==='BLOCKED'||($sections['runtime']['coherent']??false)!==true)$block[]='FISCAL_RUNTIME_INCOHERENT';elseif($runtimeStatus==='REVIEW_REQUIRED'||($sections['runtime']['operational']??true)!==true)$review[]='FISCAL_RUNTIME_NOT_OPERATIONAL';
        foreach($sections['data']as$name=>$result){$state=$result['status']??'NOT_VERIFIED';if($state==='BLOCKED')$block[]=strtoupper($name).'_BLOCKED';elseif($state==='REVIEW_REQUIRED')$review[]=strtoupper($name).'_REVIEW_REQUIRED';elseif($state==='NOT_VERIFIED')$unknown[]=strtoupper($name).'_NOT_VERIFIED';}
        $block=array_values(array_unique($block));$review=array_values(array_unique($review));$unknown=array_values(array_unique($unknown));$status=$block?'BLOCKED':($unknown?'NOT_VERIFIED':($review?'REVIEW_REQUIRED':'COMPATIBLE'));return[$status,$block,$review,$unknown];
    }

    private function enableReadOnly():void
    {
        if(str_contains($this->driver,'sqlite')){$this->db->query('PRAGMA query_only = ON');return;}
        if(str_contains($this->driver,'mysql')){$this->db->query('SET SESSION TRANSACTION READ ONLY');$this->db->query('START TRANSACTION READ ONLY');register_shutdown_function(function():void{try{$this->db->transRollback();}catch(Throwable){}});return;}
        throw new RuntimeException('DATABASE_DRIVER_NOT_SUPPORTED');
    }

    private function tableExists(string$table):bool
    {
        if(array_key_exists($table,$this->tables))return$this->tables[$table];if(str_contains($this->driver,'sqlite'))$exists=$this->rows("SELECT name FROM sqlite_master WHERE type='table' AND name=?",[$this->prefix.$table])!==[];else$exists=$this->rows('SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?',[$this->prefix.$table])!==[];return$this->tables[$table]=$exists;
    }
    private function columns(string$table):array
    {
        $result=[];if(str_contains($this->driver,'sqlite')){foreach($this->rows('PRAGMA table_info('.$this->qi($this->prefix.$table).')')as$row)$result[strtolower((string)$row['name'])]=['type'=>strtolower((string)$row['type']),'nullable'=>(int)$row['notnull']===0,'default'=>$row['dflt_value']];}else foreach($this->rows('SELECT column_name,column_type,is_nullable,column_default FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? ORDER BY ordinal_position',[$this->prefix.$table])as$row)$result[strtolower((string)$row['column_name'])]=['type'=>strtolower((string)$row['column_type']),'nullable'=>(string)$row['is_nullable']==='YES','default'=>$row['column_default']];return$result;
    }
    private function indexes(string$table):array
    {
        $result=[];if(str_contains($this->driver,'sqlite')){foreach($this->rows('PRAGMA index_list('.$this->qi($this->prefix.$table).')')as$idx){$columns=array_map(static fn($r)=>(string)$r['name'],$this->rows('PRAGMA index_info('.$this->qi((string)$idx['name']).')'));$result[strtolower((string)$idx['name'])]=['columns'=>$columns,'unique'=>(int)$idx['unique']===1];}}else{foreach($this->rows('SELECT index_name,column_name,non_unique FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? ORDER BY index_name,seq_in_index',[$this->prefix.$table])as$row){$key=strtolower((string)$row['index_name']);$result[$key]??=['columns'=>[],'unique'=>(int)$row['non_unique']===0];$result[$key]['columns'][]=(string)$row['column_name'];}}return$result;
    }
    private function hasColumns(string$table,array$columns):bool{if(!$this->tableExists($table))return false;$actual=$this->columns($table);foreach($columns as$column)if(!isset($actual[strtolower($column)]))return false;return true;}
    private function rows(string$sql,array$params=[]):array{return$this->db->query($sql,$params)->getResultArray();}
    private function q(string$table):string{return$this->qi($this->prefix.$table);}
    private function qi(string$value):string{if(!preg_match('/\A[A-Za-z0-9_]+\z/',$value))throw new RuntimeException('IDENTIFIER_UNSAFE');return str_contains($this->driver,'mysql')?'`'.$value.'`':'"'.$value.'"';}
    private function file(string$path):string{$path=str_replace('\\','/',trim($path,'/'));if($path===''||str_contains($path,'..')||str_contains($path,"\0"))throw new RuntimeException('FILE_PATH_UNSAFE');return$this->targetRoot.'/'.$path;}
    private function hashFile(string$file,string$logical,string$mode):string{$bytes=(string)file_get_contents($file);if($mode==='text-lf-sha256')$bytes=str_replace(["\r\n","\r"],["\n","\n"],$bytes);return hash('sha256',$bytes);}
    private function stableJson(array$value):string{$sort=function(&$item)use(&$sort):void{if(!is_array($item))return;foreach($item as&$child)$sort($child);unset($child);if(!array_is_list($item))ksort($item);};$sort($value);return json_encode($value,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
    private function assertContract():void{if(($this->contract['schema_version']??null)!==1||($this->contract['target_version']??null)!=='1.1.6'||!is_array($this->contract['files']??null)||!is_array($this->contract['required_schema']['tables']??null))throw new RuntimeException('INSPECTOR_CONTRACT_INVALID');}
}
