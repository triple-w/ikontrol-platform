<?php

namespace App\Services\Baseline;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

final class IkontrolBaselineCheckService
{
    /** @var list<string> */
    private const REQUIRED_CORE_TABLES = [
        'settings',
        'users',
        'roles',
        'clients',
        'items',
        'estimates',
        'estimate_items',
        'invoices',
        'invoice_items',
        'invoice_payments',
        'payment_methods',
        'taxes',
        'company',
    ];

    private BaseConnection $db;

    private string $migrationDirectory;

    public function __construct(?BaseConnection $db = null, ?string $migrationDirectory = null)
    {
        $this->db = $db ?? Database::connect();
        $this->migrationDirectory = $migrationDirectory ?? ROOTPATH . 'app/Database/Migrations';
    }

    public function run(): array
    {
        $checks = [];

        $checks[] = $this->requiredCoreTablesCheck();
        $checks[] = $this->companyCheck();
        $checks[] = $this->adminUserCheck();
        $checks[] = $this->settingsCheck();
        $checks[] = $this->paymentMethodCheck();
        $checks[] = $this->financialAccountCheck();
        $checks[] = $this->projectStatusCheck();
        $checks[] = $this->satCatalogCheck();
        $checks[] = $this->fiscalDependencyCheck();
        $checks[] = $this->migrationHistoryCheck();

        $summary = [
            'total' => count($checks),
            'pass' => 0,
            'warn' => 0,
            'fail' => 0,
        ];

        foreach ($checks as $check) {
            $status = strtoupper((string) ($check['status'] ?? 'FAIL'));
            if ($status === 'PASS') {
                $summary['pass']++;
                continue;
            }

            if ($status === 'WARN') {
                $summary['warn']++;
                continue;
            }

            $summary['fail']++;
        }

        $status = $summary['fail'] > 0 ? 'FAIL' : ($summary['warn'] > 0 ? 'WARN' : 'PASS');

        return [
            'status' => $status,
            'summary' => $summary,
            'checks' => $checks,
        ];
    }

    public function jsonPayload(): string
    {
        return json_encode($this->run(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function requiredCoreTablesCheck(): array
    {
        $tables = $this->fetchTableMap();
        $missing = [];

        foreach (self::REQUIRED_CORE_TABLES as $table) {
            if (! isset($tables[$this->db->prefixTable($table)])) {
                $missing[] = $table;
            }
        }

        return $this->asArray(new BaselineCheckResult(
            key: 'required_core_tables',
            group: 'core',
            status: $missing === [] ? 'PASS' : 'FAIL',
            message: $missing === [] ? 'Core schema required by RISE baseline is present.' : 'Core baseline tables are missing.',
            details: ['missing' => $missing],
            required: true,
            remediationHint: 'Restore the required RISE core schema before continuing with business operations.'
        ));
    }

    private function companyCheck(): array
    {
        if (! $this->tableExists('company')) {
            return $this->asArray(new BaselineCheckResult(
                key: 'company_record',
                group: 'identity',
                status: 'FAIL',
                message: 'The company table is missing.',
                details: ['table' => 'company'],
                required: true,
                remediationHint: 'Create or restore the company record before validating the operational baseline.'
            ));
        }

        $count = (int) $this->db->table('company')->where('deleted', 0)->countAllResults();
        $status = $count > 0 ? 'PASS' : 'FAIL';

        return $this->asArray(new BaselineCheckResult(
            key: 'company_record',
            group: 'identity',
            status: $status,
            message: $count > 0 ? 'A live company record exists.' : 'No active company record is present.',
            details: ['count' => $count],
            required: true,
            remediationHint: 'Create the company baseline record in the current instance before continuing.'
        ));
    }

    private function adminUserCheck(): array
    {
        if (! $this->tableExists('users')) {
            return $this->asArray(new BaselineCheckResult(
                key: 'admin_user',
                group: 'identity',
                status: 'FAIL',
                message: 'The users table is missing.',
                details: ['table' => 'users'],
                required: true,
                remediationHint: 'Restore the users table and define at least one active administrator.'
            ));
        }

        $count = (int) $this->db->table('users')
            ->where('user_type', 'staff')
            ->where('deleted', 0)
            ->where('status', 'active')
            ->where('is_admin', 1)
            ->countAllResults();

        return $this->asArray(new BaselineCheckResult(
            key: 'admin_user',
            group: 'identity',
            status: $count > 0 ? 'PASS' : 'FAIL',
            message: $count > 0 ? 'At least one active staff administrator exists.' : 'No active administrator is configured.',
            details: ['count' => $count],
            required: true,
            remediationHint: 'Create an active staff administrator with role_id 0 and login enabled.'
        ));
    }

    private function settingsCheck(): array
    {
        if (! $this->tableExists('settings')) {
            return $this->asArray(new BaselineCheckResult(
                key: 'required_settings',
                group: 'configuration',
                status: 'FAIL',
                message: 'The settings table is missing.',
                details: ['table' => 'settings'],
                required: true,
                remediationHint: 'Restore the settings table and at least the minimal configuration values required by the baseline.'
            ));
        }

        $expected = [
            'language',
            'default_currency',
            'timezone',
            'module_invoice',
        ];

        $values = $this->fetchSettings();
        $missing = [];

        foreach ($expected as $key) {
            $value = trim((string) ($values[$key] ?? ''));
            if ($value === '') {
                $missing[] = $key;
            }
        }

        return $this->asArray(new BaselineCheckResult(
            key: 'required_settings',
            group: 'configuration',
            status: $missing === [] ? 'PASS' : 'FAIL',
            message: $missing === [] ? 'Required settings are present and populated.' : 'Required settings are missing or empty.',
            details: ['missing' => $missing],
            required: true,
            remediationHint: 'Populate the minimal settings values required to boot the installation in a safe state.'
        ));
    }

    private function paymentMethodCheck(): array
    {
        if (! $this->tableExists('payment_methods')) {
            return $this->asArray(new BaselineCheckResult(
                key: 'payment_method_required',
                group: 'payments',
                status: 'FAIL',
                message: 'The payment_methods table is missing.',
                details: ['table' => 'payment_methods'],
                required: true,
                remediationHint: 'Restoring the payment method catalog is required before any sales or payment workflow can be trusted.'
            ));
        }

        // RISE payment_methods has no status column: availability is explicit.
        $count = (int) $this->db->table('payment_methods')->where('deleted', 0)->where('available_on_invoice', 1)->countAllResults();

        return $this->asArray(new BaselineCheckResult(
            key: 'payment_method_required',
            group: 'payments',
            status: $count > 0 ? 'PASS' : 'FAIL',
            message: $count > 0 ? 'At least one active payment method exists.' : 'No active payment method is configured.',
            details: ['count' => $count],
            required: true,
            remediationHint: 'Create an active cash or transfer payment method before validating payment processing.'
        ));
    }

    private function financialAccountCheck(): array
    {
        if (! $this->tableExists('financial_accounts')) {
            return $this->asArray(new BaselineCheckResult(
                key: 'mxn_financial_account',
                group: 'payments',
                status: 'FAIL',
                message: 'The financial_accounts table is missing.',
                details: ['table' => 'financial_accounts'],
                required: true,
                remediationHint: 'Create the financial_accounts table and add at least one active MXN account.'
            ));
        }

        $count = (int) $this->db->table('financial_accounts')
            ->where('deleted', 0)
            ->where('is_active', 1)
            ->where('currency', 'MXN')
            ->countAllResults();

        return $this->asArray(new BaselineCheckResult(
            key: 'mxn_financial_account',
            group: 'payments',
            status: $count > 0 ? 'PASS' : 'FAIL',
            message: $count > 0 ? 'An active MXN financial account exists.' : 'No active MXN financial account exists.',
            details: ['count' => $count],
            required: true,
            remediationHint: 'Create a minimum active MXN account for cash and payment flows to be operational.'
        ));
    }

    private function projectStatusCheck(): array
    {
        if (! $this->tableExists('project_status')) {
            return $this->asArray(new BaselineCheckResult(
                key: 'project_status_catalog',
                group: 'catalogs',
                status: 'FAIL',
                message: 'The project_status table is missing.',
                details: ['table' => 'project_status'],
                required: true,
                remediationHint: 'Restore the default project states before enabling workflow and dashboard operations.'
            ));
        }

        $count = (int) $this->db->table('project_status')->where('deleted', 0)->countAllResults();

        return $this->asArray(new BaselineCheckResult(
            key: 'project_status_catalog',
            group: 'catalogs',
            status: $count > 0 ? 'PASS' : 'FAIL',
            message: $count > 0 ? 'Project status catalog is populated.' : 'Project status catalog is empty.',
            details: ['count' => $count],
            required: true,
            remediationHint: 'Add at least the default project states used by the application workflows.'
        ));
    }

    private function satCatalogCheck(): array
    {
        if (! $this->tableExists('sat_product_service_keys')) {
            return $this->asArray(new BaselineCheckResult(
                key: 'sat_product_service_keys',
                group: 'fiscal',
                status: 'FAIL',
                message: 'The SAT product catalog is missing.',
                details: ['table' => 'sat_product_service_keys'],
                required: true,
                remediationHint: 'Restore the SAT catalog tables required for fiscal document validation.'
            ));
        }

        $count = (int) $this->db->table('sat_product_service_keys')->where('is_active', 1)->countAllResults();

        return $this->asArray(new BaselineCheckResult(
            key: 'sat_product_service_keys',
            group: 'fiscal',
            status: $count > 0 ? 'PASS' : 'FAIL',
            message: $count > 0 ? 'SAT product service keys are available.' : 'The SAT product-service catalog is empty.',
            details: ['count' => $count, 'sat_catalogs' => $this->satCatalogHealth()],
            required: true,
            remediationHint: 'Load the required SAT catalog entries before enabling fiscal workflows.'
        ));
    }

    private function fiscalDependencyCheck(): array
    {
        $fiscalEnabled = $this->settingValue('fiscal_enabled');
        if ($fiscalEnabled === '0' || $fiscalEnabled === 'false') {
            return $this->asArray(new BaselineCheckResult(
                key: 'fiscal_structure',
                group: 'fiscal',
                status: 'PASS',
                message: 'Fiscal modules are disabled in this environment, so the service skips fiscal-specific assertions.',
                details: ['fiscal_enabled' => false],
                required: false,
                remediationHint: 'If fiscal mode is intended, enable the module after validating the required SAT and document tables.'
            ));
        }

        $tables = ['fiscal_profiles', 'fiscal_series', 'fiscal_documents', 'fiscal_document_stamps'];
        $missing = [];
        foreach ($tables as $table) {
            if (! $this->tableExists($table)) {
                $missing[] = $table;
            }
        }

        return $this->asArray(new BaselineCheckResult(
            key: 'fiscal_structure',
            group: 'fiscal',
            status: $missing === [] ? 'PASS' : 'FAIL',
            message: $missing === [] ? 'Fiscal tables are present.' : 'Fiscal tables are missing.',
            details: ['missing' => $missing],
            required: false,
            remediationHint: 'Validate the fiscal document and series tables before enabling fiscal issuance.'
        ));
    }

    /**
     * Exposes the canonical SAT catalog health calculation to fiscal onboarding.
     */
    public function satCatalogHealth(): array
    {
        $names = ['product-service'=>'sat_product_service_keys','units'=>'sat_unit_keys','tax-codes'=>'sat_tax_codes','tax-factor-types'=>'sat_tax_factor_types','cfdi-uses'=>'sat_cfdi_uses','tax-regimes'=>'sat_tax_regimes','tax-object-codes'=>'sat_tax_object_codes','payment-forms'=>'sat_payment_forms','payment-methods'=>'sat_payment_methods','currencies'=>'sat_currencies'];
        $manifest = [];
        $raw = @file_get_contents(ROOTPATH . 'resources/fiscal/catalogs/sat/manifest.json');
        $decoded = json_decode((string) $raw, true);
        foreach (($decoded['catalogs'] ?? []) as $entry) if (is_array($entry) && isset($entry['catalog_name'])) $manifest[$entry['catalog_name']]=$entry;
        $result=[];
        foreach ($names as $name=>$table) {
            if (! $this->tableExists($table)) { $result[$name]=['status'=>'EMPTY','total'=>0,'active'=>0]; continue; }
            $total=(int)$this->db->table($table)->countAllResults();$active=(int)$this->db->table($table)->where('is_active',1)->countAllResults();
            $installed=null;if($this->tableExists('sat_catalog_installations'))$installed=$this->db->table('sat_catalog_installations')->where('catalog_name',$name)->get(1)->getRowArray();
            $declared=$manifest[$name]??null;
            $status=$total===0?'EMPTY':($installed===null?'UNMANAGED':($declared===null?'UNMANAGED':(($installed['source_checksum']??'')===str_replace('sha256:','',(string)($declared['checksum']??''))&&($installed['row_count']??-1)===$total?'OK':'OUTDATED')));
            if($status==='OK'&&$active===0)$status='PARTIAL';
            $result[$name]=['status'=>$status,'total'=>$total,'active'=>$active,'installed_source_version'=>$installed['source_version']??null,'installed_checksum'=>$installed['source_checksum']??null,'manifest_source_version'=>$declared['source_version']??null,'manifest_checksum'=>$declared['checksum']??null];
        }
        return $result;
    }

    private function migrationHistoryCheck(): array
    {
        $fileVersions = $this->collectMigrationVersions();

        if (! $this->tableExists('migrations')) {
            return $this->asArray(new BaselineCheckResult(
                key: 'migration_history',
                group: 'integrity',
                status: 'WARN',
                message: 'The migration registry table is missing; the current check cannot compare the actual history to the migration files.',
                details: ['files' => $fileVersions],
                required: true,
                remediationHint: 'Create the migration registry before trusting schema parity checks or running future migrations.'
            ));
        }

        $rows = $this->db->table('migrations')->select('version')->get()->getResultArray();
        $registered = [];
        foreach ($rows as $row) {
            if (isset($row['version']) && is_string($row['version']) && trim($row['version']) !== '') {
                $registered[] = trim($row['version']);
            }
        }

        $duplicates = array_keys(array_filter(array_count_values($registered), static fn ($count) => $count > 1));
        $missingFromRegistry = array_values(array_diff($fileVersions, $registered));
        $missingFromFiles = array_values(array_diff($registered, $fileVersions));

        $status = ($duplicates === [] && $missingFromFiles === [] && $missingFromRegistry === []) ? 'PASS' : 'WARN';

        return $this->asArray(new BaselineCheckResult(
            key: 'migration_history',
            group: 'integrity',
            status: $status,
            message: $status === 'PASS' ? 'Migration registry and file history are consistent.' : 'Migration history is not fully aligned with the declared schema baseline.',
            details: [
                'registered' => $registered,
                'files' => $fileVersions,
                'duplicates' => $duplicates,
                'missing_from_registry' => $missingFromRegistry,
                'missing_from_files' => $missingFromFiles,
            ],
            required: true,
            remediationHint: 'Inspect duplicated versions, unregistered schema files, and backfills before performing any migration or repair.'
        ));
    }

    private function fetchTableMap(): array
    {
        $tables = $this->db->listTables();

        $map = [];
        foreach ($tables as $table) {
            $map[(string) $table] = true;
        }

        return $map;
    }

    private function tableExists(string $table): bool
    {
        return $this->db->tableExists($table);
    }

    private function fetchSettings(): array
    {
        $rows = $this->db->table('settings')
            ->select('setting_name, setting_value')
            ->where('deleted', 0)
            ->get()
            ->getResultArray();

        $values = [];
        foreach ($rows as $row) {
            $name = (string) ($row['setting_name'] ?? '');
            if ($name !== '') {
                $values[$name] = $row['setting_value'] ?? '';
            }
        }

        return $values;
    }

    private function settingValue(string $name): string
    {
        if (! $this->tableExists('settings')) {
            return '';
        }

        $row = $this->db->table('settings')
            ->select('setting_value')
            ->where('setting_name', $name)
            ->where('deleted', 0)
            ->get(1)
            ->getRowArray();

        return is_array($row) ? (string) ($row['setting_value'] ?? '') : '';
    }

    /** @return list<string> */
    private function collectMigrationVersions(): array
    {
        if (! is_dir($this->migrationDirectory)) {
            return [];
        }

        $versions = [];
        $entries = scandir($this->migrationDirectory);
        if ($entries === false) {
            return [];
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $this->migrationDirectory . DIRECTORY_SEPARATOR . $entry;
            if (! is_file($path) || strtolower((string) pathinfo($path, PATHINFO_EXTENSION)) !== 'php') {
                continue;
            }

            if (preg_match('/^(\d{4}-\d{2}-\d{2}-\d{6})/', $entry, $matches) === 1) {
                $versions[] = $matches[1];
            }
        }

        sort($versions, SORT_STRING);

        return array_values(array_unique($versions));
    }

    private function asArray(BaselineCheckResult $result): array
    {
        return $result->toArray();
    }
}
