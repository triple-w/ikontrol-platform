<?php

declare(strict_types=1);

namespace App\Services\Upgrade\Legacy;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;

/** Directed bridge; it never invokes CodeIgniter's migration runner. */
final class SmartfreePrefiscalBridgeService
{
    private const PROFILE = 'smartfree-rise-3.9.4-ci4.6.1-prefiscal-v1';
    private const TARGET = 'ikontrol-1.0.0';
    private BaseConnection $db;
    private BaseConnection $template;
    private string $prefix;

    public function __construct(string $database, string $templateDatabase, ?string $prefix = null)
    {
        foreach ([$database, $templateDatabase] as $name) if (! preg_match('/^[A-Za-z0-9_]+$/', $name)) throw new RuntimeException('Invalid database identifier.');
        $base = config('Database')->default;
        $config = is_array($base) ? $base : get_object_vars($base);
        $this->db = Database::connect(array_replace($config, ['database' => $database]), false);
        $this->template = Database::connect(array_replace($config, ['database' => $templateDatabase]), false);
        $physicalTables = $this->db->listTables();
        $detected = null;
        foreach ($physicalTables as $physical) if (preg_match('/^(.*)clients$/', (string) $physical, $matches)) { $detected = $matches[1]; break; }
        $this->prefix = $prefix ?? ($detected ?? (string) $this->db->DBPrefix);
        // All bridge SQL supplies the detected prefix explicitly.
        $this->db->setPrefix('');
    }

    public function dryRun(): array { if($this->db->tableExists($this->table('legacy_bridge_runs'))){$done=$this->db->table($this->table('legacy_bridge_runs'))->where('profile',self::PROFILE)->where('status','completed')->orderBy('id','DESC')->get(1)->getRowArray();if($done)return ['mode'=>'dry-run','already_completed'=>true,'run_id'=>(int)$done['id'],'before'=>json_decode((string)$done['before_json'],true),'after'=>$this->snapshot()];} return ['mode'=>'dry-run','before'=>$this->preflight(),'steps'=>$this->steps()]; }

    public function execute(): array
    {
        $resume = null;
        if ($this->db->tableExists($this->table('legacy_bridge_runs'))) $resume = $this->db->table($this->table('legacy_bridge_runs'))->where('profile', self::PROFILE)->whereIn('status', ['running', 'completed'])->orderBy('id', 'DESC')->get(1)->getRowArray();
        $before = $resume ? (json_decode((string) $resume['before_json'], true) ?: []) : $this->preflight();
        $this->registry();
        $run = $resume ? (int) $resume['id'] : $this->startRun($before);
        foreach ($this->steps() as $step) {
            if ($this->completed($run, $step)) continue;
            $this->markStep($run, $step, 'running');
            try { $metadata = match ($step) {
                'B010' => ['registry'=>true], 'B020' => $this->copyCanonicalColumns(), 'B030' => $this->exactMoney(),
                'B040' => $this->commercialLifecycle(), 'B050' => $this->copyCanonicalTables('fiscal'),
                'B060' => $this->financialFoundation(), 'B070' => $this->backfillPayments(),
                'B080' => $this->copyCanonicalTables('logistics'), 'B090' => $this->safeSettings(),
                'B100' => $this->constraintsReport(), 'B110' => $this->markBaseline($before), default => [],
            }; $this->markStep($run, $step, 'completed', $metadata);
            } catch (\Throwable $e) { $this->markStep($run, $step, 'failed', ['error'=>$e->getMessage()]); throw $e; }
        }
        $onboarding=$this->fiscalOnboardingSettings();
        $this->db->table($this->table('legacy_bridge_runs'))->where('id', $run)->update(['status'=>'completed','completed_at'=>date('Y-m-d H:i:s')]);
        return ['mode'=>'execute','run_id'=>$run,'before'=>$before,'after'=>$this->snapshot(),'fiscal_onboarding'=>$onboarding];
    }

    private function q(string $table): string { if (! preg_match('/^[A-Za-z0-9_]+$/', $table)) throw new RuntimeException('Invalid table.'); return $this->db->protectIdentifiers($this->prefix . $table); }
    private function table(string $table): string { return $this->prefix . $table; }
    private function steps(): array { return ['B000','B010','B020','B030','B040','B050','B060','B070','B080','B090','B100','B110']; }

    private function preflight(): array
    {
        $required=['clients'=>315,'items'=>273,'item_categories'=>19,'invoices'=>1301,'invoice_items'=>2296,'invoice_payments'=>1418,'projects'=>339,'tasks'=>313,'users'=>5,'roles'=>3,'estimates'=>1,'proposals'=>0,'settings'=>102,'company'=>1];
        $observed=[]; foreach ($required as $table=>$expected) { if (! $this->db->tableExists($this->table($table))) throw new RuntimeException("B000 missing {$table}"); $observed[$table]=(int)$this->db->table($this->table($table))->countAllResults(); if($observed[$table]!==$expected) throw new RuntimeException("B000 fingerprint count mismatch {$table}: {$observed[$table]}"); }
        foreach (['items.rate','invoices.invoice_total','invoice_payments.amount'] as $field) { [$table,$column]=explode('.',$field); $type=(string)($this->db->getFieldData($this->table($table))[array_search($column,array_column($this->db->getFieldData($this->table($table)),'name'))]->type ?? ''); if (stripos($type,'double')===false) throw new RuntimeException("B000 type mismatch {$field}"); }
        $orphans=['payments'=>(int)$this->db->query("SELECT COUNT(*) c FROM {$this->q('invoice_payments')} p LEFT JOIN {$this->q('invoices')} i ON i.id=p.invoice_id WHERE i.id IS NULL")->getRow()->c,'invoice_items'=>(int)$this->db->query("SELECT COUNT(*) c FROM {$this->q('invoice_items')} x LEFT JOIN {$this->q('invoices')} i ON i.id=x.invoice_id WHERE i.id IS NULL")->getRow()->c];
        if($orphans['payments']!==18||$orphans['invoice_items']!==85) throw new RuntimeException('B000 unapproved orphan profile.');
        return ['profile'=>self::PROFILE,'prefix'=>$this->prefix,'counts'=>$observed,'orphans'=>$orphans,'snapshot'=>$this->snapshot()];
    }

    private function snapshot(): array
    {
        $sum=fn(string $sql)=>(string)$this->db->query($sql)->getRow()->v;
        return ['max_ids'=>array_map(fn($t)=>(int)$this->db->query("SELECT COALESCE(MAX(id),0) v FROM {$this->q($t)}")->getRow()->v,['clients','items','invoices','invoice_items','invoice_payments']), 'invoice_total'=>$sum("SELECT CAST(SUM(CAST(invoice_total AS DECIMAL(18,6))) AS CHAR) v FROM {$this->q('invoices')}"), 'payment_total'=>$sum("SELECT CAST(SUM(CAST(amount AS DECIMAL(18,6))) AS CHAR) v FROM {$this->q('invoice_payments')}"), 'active_payment_total'=>$sum("SELECT CAST(SUM(CAST(amount AS DECIMAL(18,6))) AS CHAR) v FROM {$this->q('invoice_payments')} WHERE deleted=0")];
    }

    private function registry(): void
    {
        $this->db->query("CREATE TABLE IF NOT EXISTS {$this->q('app_schema_versions')} (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, version VARCHAR(100) NOT NULL UNIQUE, description VARCHAR(255) NOT NULL, applied_at DATETIME NOT NULL)");
        $this->db->query("CREATE TABLE IF NOT EXISTS {$this->q('legacy_bridge_runs')} (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, profile VARCHAR(120) NOT NULL, target_version VARCHAR(40) NOT NULL, status VARCHAR(20) NOT NULL, before_json LONGTEXT NOT NULL, started_at DATETIME NOT NULL, completed_at DATETIME NULL)");
        $this->db->query("CREATE TABLE IF NOT EXISTS {$this->q('legacy_bridge_steps')} (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, bridge_run_id BIGINT UNSIGNED NOT NULL, step VARCHAR(10) NOT NULL, status VARCHAR(20) NOT NULL, metadata_json LONGTEXT NULL, started_at DATETIME NULL, completed_at DATETIME NULL, UNIQUE KEY uq_legacy_bridge_step (bridge_run_id,step))");
    }
    private function startRun(array $before): int { $old=$this->db->table($this->table('legacy_bridge_runs'))->where('profile',self::PROFILE)->whereIn('status',['running','completed'])->orderBy('id','DESC')->get(1)->getRowArray(); if($old) return (int)$old['id']; $this->db->table($this->table('legacy_bridge_runs'))->insert(['profile'=>self::PROFILE,'target_version'=>self::TARGET,'status'=>'running','before_json'=>json_encode($before),'started_at'=>date('Y-m-d H:i:s')]); return (int)$this->db->insertID(); }
    private function completed(int $run,string $step): bool { return (int)$this->db->table($this->table('legacy_bridge_steps'))->where(['bridge_run_id'=>$run,'step'=>$step,'status'=>'completed'])->countAllResults()>0; }
    private function markStep(int $run,string $step,string $status,array $metadata=[]): void { $table=$this->db->table($this->table('legacy_bridge_steps')); $old=$table->where(['bridge_run_id'=>$run,'step'=>$step])->get(1)->getRowArray(); $data=['status'=>$status,'metadata_json'=>json_encode($metadata),'started_at'=>date('Y-m-d H:i:s'),'completed_at'=>$status==='completed'?date('Y-m-d H:i:s'):null]; $old?$table->where('id',$old['id'])->update($data):$table->insert($data+['bridge_run_id'=>$run,'step'=>$step]); }

    private function copyCanonicalColumns(): array { $n=0; foreach(['items','estimate_items','invoice_items','proposal_items','invoices','invoice_payments','payment_methods','users','taxes'] as $name) { if(!$this->db->tableExists($this->table($name))) continue; $source=array_flip(array_column($this->db->getFieldData($this->table($name)),'name')); $target=$this->template->getFieldData($this->template->DBPrefix.$name); foreach($target as $field) if(!isset($source[$field->name])) { $this->db->query("ALTER TABLE {$this->q($name)} ADD COLUMN ".$this->columnSql($field));$n++; } } return ['added_columns'=>$n]; }
    private function columnSql(object $f): string { $name=$this->db->protectIdentifiers($f->name); $type=$f->type.($f->max_length?"({$f->max_length})":''); return "$name $type NULL"; }
    private function exactMoney(): array { $changed=[]; foreach(['items.rate','invoices.invoice_total','invoice_payments.amount'] as $field){[$t,$c]=explode('.',$field);$bad=(int)$this->db->query("SELECT COUNT(*) c FROM {$this->q($t)} WHERE ABS(CAST(CAST({$this->db->protectIdentifiers($c)} AS DECIMAL(30,12)) AS DECIMAL(18,6))-{$this->db->protectIdentifiers($c)})>0.0000005")->getRow()->c;if($bad)throw new RuntimeException("B030 unsafe {$field}");$this->db->query("ALTER TABLE {$this->q($t)} MODIFY {$this->db->protectIdentifiers($c)} DECIMAL(18,6) NOT NULL");$changed[]=$field;}return ['converted'=>$changed]; }
    private function commercialLifecycle(): array { if(!$this->db->fieldExists('commercial_status',$this->table('invoices'))) return ['skipped'=>true]; $this->db->query("UPDATE {$this->q('invoices')} SET commercial_status=CASE WHEN status='draft' THEN 'draft' WHEN status='cancelled' THEN 'cancelled' ELSE 'open' END WHERE commercial_status IS NULL OR commercial_status=''");return ['mapped'=>true]; }
    private function copyCanonicalTables(string $group): array { $all=$this->template->listTables(); $copied=[]; $financial=['financial_accounts','financial_account_movements','financial_account_transfers','payment_allocations']; foreach($all as $source){$name=preg_replace('/^'.preg_quote((string)$this->template->DBPrefix,'/').'/', '',$source);$fiscal=str_starts_with($name,'fiscal_')||str_starts_with($name,'sat_')||str_starts_with($name,'payment_complement')||str_starts_with($name,'credit_note')||str_starts_with($name,'issuer_')||str_starts_with($name,'item_fiscal_');$logistics=in_array($name,['suppliers','product_suppliers','product_supplier_cost_history','warehouses','warehouse_products','warehouse_movements','warehouse_transfers','warehouse_transfer_items'],true);$wanted=$group==='fiscal'?$fiscal:($group==='logistics'?$logistics:in_array($name,$financial,true));if(!$wanted||$this->db->tableExists($this->table($name)))continue;$ddl=$this->template->query('SHOW CREATE TABLE '.$this->template->protectIdentifiers($source))->getRowArray()['Create Table'];$ddl=preg_replace('/,?\s*CONSTRAINT [^\n]+/m','',$ddl)??$ddl;$ddl=preg_replace('/,\s*\)/',"\n)",$ddl)??$ddl;$ddl=str_replace([$this->template->protectIdentifiers($source),$this->template->DBPrefix],[$this->q($name),$this->prefix],$ddl);$this->db->query($ddl);$copied[]=$name;}return ['created'=>$copied]; }
    private function financialFoundation(): array { $this->db->query("CREATE TABLE IF NOT EXISTS {$this->q('financial_accounts')} (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(150) NOT NULL,type VARCHAR(30) NOT NULL,description TEXT NULL,currency VARCHAR(3) NOT NULL DEFAULT 'MXN',opening_balance DECIMAL(18,6) NOT NULL DEFAULT 0,is_active TINYINT NOT NULL DEFAULT 1,deleted TINYINT NOT NULL DEFAULT 0,created_by INT NULL,created_at DATETIME NULL,updated_at DATETIME NULL,UNIQUE KEY uq_financial_account_name(name))");$this->db->query("CREATE TABLE IF NOT EXISTS {$this->q('financial_account_movements')} (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,financial_account_id INT UNSIGNED NOT NULL,direction VARCHAR(3) NOT NULL,amount DECIMAL(18,6) NOT NULL,movement_date DATE NOT NULL,reference_type VARCHAR(50) NOT NULL,reference_id INT NOT NULL,description TEXT NULL,is_active TINYINT NOT NULL DEFAULT 1,created_by INT NULL,created_at DATETIME NULL,UNIQUE KEY uq_legacy_movement(reference_type,reference_id))");$this->db->query("CREATE TABLE IF NOT EXISTS {$this->q('financial_account_transfers')} (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,source_account_id INT UNSIGNED NOT NULL,destination_account_id INT UNSIGNED NOT NULL,amount DECIMAL(18,6) NOT NULL,transfer_date DATE NOT NULL,note TEXT NULL,deleted TINYINT NOT NULL DEFAULT 0,created_by INT NULL,created_at DATETIME NULL)");$this->db->query("CREATE TABLE IF NOT EXISTS {$this->q('payment_allocations')} (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,invoice_payment_id INT NOT NULL,invoice_id INT NOT NULL,amount_applied DECIMAL(18,6) NOT NULL,allocation_date DATE NOT NULL,status VARCHAR(20) NOT NULL DEFAULT 'active',created_by INT NULL,created_at DATETIME NULL,updated_at DATETIME NULL,deleted TINYINT NOT NULL DEFAULT 0,UNIQUE KEY uq_legacy_payment_allocation(invoice_payment_id,invoice_id))");$map=[1=>'LEGACY - Efectivo',6=>'LEGACY - TPV Mercado Pago',7=>'LEGACY - TPV BBVA',8=>'LEGACY - Transferencia Cuenta Fiscal'];foreach($map as $method=>$name){$row=$this->db->table($this->table('financial_accounts'))->where('name',$name)->get(1)->getRowArray();if(!$row){$this->db->table($this->table('financial_accounts'))->insert(['name'=>$name,'type'=>'legacy_import','description'=>'Technical legacy imported account; not a bank account.','currency'=>'MXN','opening_balance'=>0,'is_active'=>1,'deleted'=>0,'created_at'=>date('Y-m-d H:i:s')]);$row=['id'=>$this->db->insertID()];}$this->db->table($this->table('payment_methods'))->where('id',$method)->update(['default_financial_account_id'=>$row['id']]);}return ['accounts'=>4]; }
    private function backfillPayments(): array { $payments=$this->db->query("SELECT p.*, i.client_id invoice_client, i.invoice_total FROM {$this->q('invoice_payments')} p LEFT JOIN {$this->q('invoices')} i ON i.id=p.invoice_id ORDER BY p.id")->getResultArray();$movement=0;$allocation=0;foreach($payments as $p){$status=(int)$p['deleted']===0?'active':'cancelled';$data=['client_id'=>$p['invoice_client']??null,'status'=>$status];$this->db->table($this->table('invoice_payments'))->where('id',$p['id'])->update($data);if($status!=='active'||(float)$p['amount']<=0)continue;$account=$this->db->table($this->table('payment_methods'))->select('default_financial_account_id')->where('id',$p['payment_method_id'])->get(1)->getRowArray()['default_financial_account_id']??null;if(!$account)continue;$exists=$this->db->table($this->table('financial_account_movements'))->where(['reference_type'=>'legacy_invoice_payment','reference_id'=>$p['id']])->countAllResults();if(!$exists){$this->db->table($this->table('financial_account_movements'))->insert(['financial_account_id'=>$account,'direction'=>'IN','amount'=>$p['amount'],'movement_date'=>$p['payment_date'],'reference_type'=>'legacy_invoice_payment','reference_id'=>$p['id'],'description'=>'Imported legacy payment','is_active'=>1,'created_at'=>date('Y-m-d H:i:s')]);$movement++;}if(!$p['invoice_client'])continue;$allocated=(float)($this->db->query("SELECT COALESCE(SUM(amount_applied),0) v FROM {$this->q('payment_allocations')} WHERE invoice_id=? AND deleted=0",[$p['invoice_id']])->getRow()->v);$remaining=max(0,(float)$p['invoice_total']-$allocated);$apply=min((float)$p['amount'],$remaining);if($apply>0&&!$this->db->table($this->table('payment_allocations'))->where(['invoice_payment_id'=>$p['id'],'invoice_id'=>$p['invoice_id']])->countAllResults()){$this->db->table($this->table('payment_allocations'))->insert(['invoice_payment_id'=>$p['id'],'invoice_id'=>$p['invoice_id'],'amount_applied'=>$apply,'allocation_date'=>$p['payment_date'],'status'=>'active','created_at'=>date('Y-m-d H:i:s'),'deleted'=>0]);$allocation++;}}return ['movements'=>$movement,'allocations'=>$allocation]; }
    private function safeSettings(): array { $this->db->query("UPDATE {$this->q('users')} SET is_platform_superadmin=0 WHERE is_platform_superadmin IS NULL"); return ['settings_preserved'=>true]+$this->fiscalOnboardingSettings(); }
    private function fiscalOnboardingSettings(): array { if($this->db->fieldExists('setting_name',$this->table('settings'))){$settings=$this->db->table($this->table('settings'));$existing=$settings->where('setting_name','fiscal_enabled')->get(1)->getRowArray();$data=['setting_value'=>'1','deleted'=>0];$existing?$settings->where('setting_name','fiscal_enabled')->update($data):$settings->insert($data+['setting_name'=>'fiscal_enabled','type'=>'text']);}if($this->db->fieldExists('available_on_invoice',$this->table('payment_methods')))$this->db->table($this->table('payment_methods'))->whereIn('id',[1,6,7,8])->where('deleted',0)->update(['available_on_invoice'=>1]);return ['fiscal_visible'=>true,'stamping_requires_server_readiness'=>true,'legacy_payment_methods_available'=>[1,6,7,8]]; }
    private function constraintsReport(): array{return ['orphans_preserved'=>true,'foreign_keys_added'=>0];}
    private function markBaseline(array $before): array {$after=$this->snapshot();if($before['snapshot']!==$after)throw new RuntimeException('B110 legacy financial snapshot mismatch.');$this->db->table($this->table('app_schema_versions'))->ignore(true)->insert(['version'=>self::TARGET,'description'=>'Smartfree prefiscal bridge baseline','applied_at'=>date('Y-m-d H:i:s')]);return ['version'=>self::TARGET];}
}
