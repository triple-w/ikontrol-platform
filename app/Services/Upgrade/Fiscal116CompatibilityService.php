<?php
declare(strict_types=1);

namespace App\Services\Upgrade;

use App\Services\Fiscal\FiscalRuntimeDiagnosticService;
use App\Services\Instance\InstanceVersionService;
use App\Services\Upgrade\Legacy\PhysicalSchemaInspector;
use CodeIgniter\Database\BaseConnection;
use Throwable;

/** Read-only, non-secret evidence for per-instance 1.1.6 compatibility checks. */
final class Fiscal116CompatibilityService
{
    private const FISCAL_FILES = [
        'app/Config/Fiscal.php','app/Config/FiscalRoutes.php','app/Config/Version.php','app/Controllers/Fiscal/InvoiceModule.php','app/Controllers/Fiscal/SatStatus.php',
        'app/Services/Fiscal/FiscalRuntimeContext.php','app/Services/Fiscal/ProductFiscalReadinessService.php','app/Services/Fiscal/FiscalPaymentMethodReadinessService.php',
        'app/Services/Fiscal/FiscalSeriesEnvironmentService.php','app/Services/Fiscal/SatStatus/FiscalSatStatusService.php',
        'app/Services/Fiscal/Cancellation/FiscalCancellationService.php','app/Services/Fiscal/Cancellation/FiscalCancellationStatusMapper.php',
        'app/Services/Fiscal/Stamps/FiscalStampWalletResolver.php','app/Services/Upgrade/InstanceUpgradeService.php',
    ];

    public function __construct(private BaseConnection $db, private string $root = ROOTPATH) {}

    public function inspect(): array
    {
        $versions=new InstanceVersionService($this->db);$current=$versions->current();$canonical=$versions->canonical();$blockers=[];$schema=[];$inspector=new PhysicalSchemaInspector($this->db,(string)$this->db->DBPrefix);
        foreach(Fiscal116SchemaContract::NEW_TABLES as$table=>$definition){$columns=$definition['columns'];$exists=$inspector->tableExists($table);$actual=$exists?array_map(static fn(array$row):string=>strtolower((string)$row['column_name']),$this->db->query('SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? ORDER BY ordinal_position',[$this->db->prefixTable($table)])->getResultArray()):[];$missing=array_values(array_diff($columns,$actual));$schema[$table]=['exists'=>$exists,'missing_columns'=>$missing];if(!$exists)$blockers[]='SCHEMA_REQUIRED:'.$table;elseif($missing!==[])$blockers[]='SCHEMA_REQUIRED:'.$table.':'.implode(',',$missing);}
        foreach(Fiscal116SchemaContract::PREREQUISITE_COLUMNS as$table=>$columns){$exists=$inspector->tableExists($table);$actual=$exists?array_map(static fn(array$row):string=>strtolower((string)$row['column_name']),$this->db->query('SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? ORDER BY ordinal_position',[$this->db->prefixTable($table)])->getResultArray()):[];$missing=array_values(array_diff($columns,$actual));$invalidIndexes=[];if($exists)foreach(Fiscal116SchemaContract::PREREQUISITE_INDEXES[$table]??[]as$name=>$expected){$rows=$this->db->query('SELECT column_name,non_unique FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=? ORDER BY seq_in_index',[$this->db->prefixTable($table),$name])->getResultArray();$observed=['columns'=>array_map(static fn(array$row):string=>(string)$row['column_name'],$rows),'unique'=>$rows!==[]&&(int)$rows[0]['non_unique']===0];if($observed!==$expected)$invalidIndexes[]=$name;}$schema[$table]=['exists'=>$exists,'missing_columns'=>$missing,'invalid_indexes'=>$invalidIndexes,'prerequisite'=>true];if(!$exists)$blockers[]='SCHEMA_PREREQUISITE:'.$table;elseif($missing!==[])$blockers[]='SCHEMA_PREREQUISITE:'.$table.':'.implode(',',$missing);elseif($invalidIndexes!==[])$blockers[]='SCHEMA_PREREQUISITE_INDEX:'.$table.':'.implode(',',$invalidIndexes);}
        $schemaFingerprint=$this->schemaFingerprint(array_merge(array_keys(Fiscal116SchemaContract::NEW_TABLES),array_keys(Fiscal116SchemaContract::PREREQUISITE_COLUMNS)));
        $plan=(new InstanceUpgradeService($this->db))->plan('1.1.6');if(!$plan['compatible']&&$current!=='1.1.6')$blockers=array_merge($blockers,$plan['blockers']);
        $files=[];foreach(self::FISCAL_FILES as$path){$full=rtrim($this->root,'/\\').DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$path);$files[$path]=is_file($full)?hash_file('sha256',$full):null;if($files[$path]===null)$blockers[]='FILE_MISSING:'.$path;}
        $runtime=['available'=>false,'blocking_reasons'=>['Runtime fiscal no evaluable.']];try{$runtime=(new FiscalRuntimeDiagnosticService())->inspect(config('Fiscal'),config('TimbradorXpress'));}catch(Throwable$e){$runtime=['available'=>false,'blocking_reasons'=>['Runtime fiscal no evaluable: '.get_class($e)]];}
        $data=['series'=>$this->seriesSummary(),'wallets'=>$this->walletSummary(),'payment_mappings'=>$this->mappingSummary()];
        foreach($data['wallets']['ambiguous']??[]as$row)$blockers[]='AMBIGUOUS_WALLET:issuer_'.$row['issuer_profile_id'].':'.$row['environment'];
        $upgradeOnly=$current==='1.1.5'&&$plan['compatible'];foreach($blockers as$blocker)if(!preg_match('/^SCHEMA_REQUIRED:[^:]+$/',$blocker)){$upgradeOnly=false;break;}
        return ['status'=>$blockers===[]?'READY':($upgradeOnly?'UPGRADE_REQUIRED':'BLOCKED'),'current_version'=>$current,'canonical_version'=>$canonical,
            'upgrade_plan'=>$plan,'file_hashes'=>$files,'schema_fingerprint'=>$schemaFingerprint,'required_schema'=>$schema,
            'runtime'=>$this->sanitizeRuntime($runtime),'data_preflight'=>$data,'blockers'=>array_values(array_unique($blockers))];
    }

    private function schemaFingerprint(array$tables):array
    {
        $inspector=new PhysicalSchemaInspector($this->db,(string)$this->db->DBPrefix);$shape=[];foreach($tables as$table){if(!$inspector->tableExists($table)){$shape[$table]=null;continue;}$physical=$this->db->prefixTable($table);$columns=$this->db->query('SELECT column_name,column_type,is_nullable,column_default FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? ORDER BY ordinal_position',[$physical])->getResultArray();$indexes=$this->db->query('SELECT index_name,non_unique,seq_in_index,column_name FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? ORDER BY index_name,seq_in_index',[$physical])->getResultArray();$shape[$table]=['columns'=>$columns,'indexes'=>$indexes];}
        return ['sha256'=>hash('sha256',json_encode($shape,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),'tables'=>$shape];
    }
    private function seriesSummary():array
    {
        if(!$this->hasColumns('fiscal_series',['environment','is_active','deleted']))return['available'=>false,'reason'=>'required_columns_missing'];$rows=$this->db->query('SELECT COALESCE(environment,\'legacy\') environment,COUNT(*) total,SUM(CASE WHEN is_active=1 AND deleted=0 THEN 1 ELSE 0 END) active FROM '.$this->q('fiscal_series').' GROUP BY COALESCE(environment,\'legacy\') ORDER BY environment')->getResultArray();return['available'=>true,'by_environment'=>$rows];
    }
    private function walletSummary():array
    {
        if(!$this->hasColumns('fiscal_stamp_accounts',['issuer_profile_id','environment']))return['available'=>false,'ambiguous'=>[],'reason'=>'required_columns_missing'];$rows=$this->db->query('SELECT issuer_profile_id,environment,COUNT(*) account_count FROM '.$this->q('fiscal_stamp_accounts').' GROUP BY issuer_profile_id,environment ORDER BY issuer_profile_id,environment')->getResultArray();return['available'=>true,'relationships'=>$rows,'ambiguous'=>array_values(array_filter($rows,static fn(array$r):bool=>(int)$r['account_count']!==1))];
    }
    private function mappingSummary():array
    {
        if(!$this->hasColumns('fiscal_payment_method_mappings',['payment_method_id','is_active']))return['available'=>false,'reason'=>'required_columns_missing'];$table=$this->q('fiscal_payment_method_mappings');$total=(int)$this->db->table('fiscal_payment_method_mappings')->countAllResults();$duplicates=$this->db->query('SELECT payment_method_id,COUNT(*) mapping_count FROM '.$table.' WHERE is_active=1 GROUP BY payment_method_id HAVING COUNT(*)<>1')->getResultArray();return['available'=>true,'total'=>$total,'invalid_active_cardinality'=>$duplicates];
    }
    private function hasColumns(string$table,array$columns):bool{$inspector=new PhysicalSchemaInspector($this->db,(string)$this->db->DBPrefix);if(!$inspector->tableExists($table))return false;$actual=array_map(static fn(array$row):string=>strtolower((string)$row['column_name']),$this->db->query('SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=?',[$this->db->prefixTable($table)])->getResultArray());foreach($columns as$column)if(!in_array(strtolower($column),$actual,true))return false;return true;}
    private function sanitizeRuntime(array$r):array{$keys=['runtime_mode','fiscal_environment','transport_environment','adapter','pac_provider','endpoint_allowed','credential_present','fiscal_enabled','stamping_enabled','real_pac_allowed','coherent','pac_operational','context_id','blocking_reasons'];return array_intersect_key($r,array_flip($keys));}
    private function q(string$table):string{return$this->db->protectIdentifiers($this->db->prefixTable($table));}
}
