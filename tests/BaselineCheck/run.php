<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Services\Baseline\IkontrolBaselineCheckService;
use Config\Database;

final class BaselineCheckTestRunner
{
    private int $passed = 0;
    private int $failed = 0;
    private $db;

    public function run(): int
    {
        // Never inherit a writable connection or prefix from the instance .env.
        $db = $this->db = Database::connect([
            'DSN' => '', 'hostname' => '', 'username' => '', 'password' => '',
            'database' => ':memory:', 'DBDriver' => 'SQLite3', 'DBPrefix' => '',
            'pConnect' => false, 'DBDebug' => true, 'foreignKeys' => true,
        ], false);
        if ($db->DBDriver !== 'SQLite3' || $db->getDatabase() !== ':memory:') {
            throw new RuntimeException('Baseline tests require an isolated in-memory SQLite database.');
        }
        $this->testValidBaseline($db);
        $this->testMissingRequiredTable($db);
        $this->testMissingCompany($db);
        $this->testMissingAdmin($db);
        $this->testMissingPaymentMethod($db);
        $this->testUnavailablePaymentMethod($db);
        $this->testMissingMxnAccount($db);
        $this->testProjectStatusEmpty($db);
        $this->testSatCatalogEmpty($db);
        $this->testFiscalDisabledDoesNotFail($db);
        $this->testMigrationMismatchDetected();
        $this->testJsonOutputDoesNotLeakSecrets();
        $this->testInspectionDoesNotWrite($db);

        fwrite(STDOUT, sprintf("\n%d passed, %d failed.\n", $this->passed, $this->failed));

        return $this->failed === 0 ? 0 : 1;
    }

    private function testValidBaseline($db): void
    {
        $this->withFreshSchema($db, function () use ($db) {
            $this->seedBaseline($db);
            $service = new IkontrolBaselineCheckService($db, __DIR__ . '/fixtures/migrations_valid');
            $result = $service->run();
            $this->assert($result['status'] === 'PASS', 'Baseline validation passes without warnings for a complete baseline');
        });
    }

    private function testMissingRequiredTable($db): void
    {
        $this->withFreshSchema($db, function () use ($db) {
            $this->seedBaseline($db);
            $db->query('DROP TABLE settings');
            $db->resetDataCache();
            $service = new IkontrolBaselineCheckService($db, __DIR__ . '/fixtures/migrations_valid');
            $result = $service->run();
            $this->assert($this->findCheck($result['checks'], 'required_core_tables')['status'] === 'FAIL', 'Missing required table returns FAIL');
        });
    }

    private function testMissingCompany($db): void
    {
        $this->withFreshSchema($db, function () use ($db) {
            $this->seedBaseline($db, ['company' => false]);
            $service = new IkontrolBaselineCheckService($db, __DIR__ . '/fixtures/migrations_valid');
            $result = $service->run();
            $this->assert($this->findCheck($result['checks'], 'company_record')['status'] === 'FAIL', 'Missing company returns FAIL');
        });
    }

    private function testMissingAdmin($db): void
    {
        $this->withFreshSchema($db, function () use ($db) {
            $this->seedBaseline($db, ['users' => false]);
            $service = new IkontrolBaselineCheckService($db, __DIR__ . '/fixtures/migrations_valid');
            $result = $service->run();
            $this->assert($this->findCheck($result['checks'], 'admin_user')['status'] === 'FAIL', 'Missing admin user returns FAIL');
        });
    }

    private function testMissingPaymentMethod($db): void
    {
        $this->withFreshSchema($db, function () use ($db) {
            $this->seedBaseline($db, ['payment_methods' => false]);
            $service = new IkontrolBaselineCheckService($db, __DIR__ . '/fixtures/migrations_valid');
            $result = $service->run();
            $this->assert($this->findCheck($result['checks'], 'payment_method_required')['status'] === 'FAIL', 'Missing payment method returns FAIL');
        });
    }

    private function testMissingMxnAccount($db): void
    {
        $this->withFreshSchema($db, function () use ($db) {
            $this->seedBaseline($db, ['financial_accounts' => false]);
            $service = new IkontrolBaselineCheckService($db, __DIR__ . '/fixtures/migrations_valid');
            $result = $service->run();
            $this->assert($this->findCheck($result['checks'], 'mxn_financial_account')['status'] === 'FAIL', 'Missing MXN financial account returns FAIL');
        });
    }

    private function testUnavailablePaymentMethod($db): void
    {
        $this->withFreshSchema($db, function () use ($db) {
            $this->seedBaseline($db);
            $db->query('UPDATE payment_methods SET available_on_invoice=0');
            $result = (new IkontrolBaselineCheckService($db, __DIR__ . '/fixtures/migrations_valid'))->run();
            $this->assert($this->findCheck($result['checks'], 'payment_method_required')['status'] === 'FAIL', 'An unavailable payment method does not satisfy the baseline');
        });
    }

    private function testInspectionDoesNotWrite($db): void
    {
        $this->withFreshSchema($db, function () use ($db) {
            $this->seedBaseline($db);
            $snapshot = static function () use ($db): string {
                $data = [];
                foreach ($db->listTables() as $name) {
                    $data[$name] = $db->table($name)->get()->getResultArray();
                }
                return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            };
            $before = $snapshot();
            (new IkontrolBaselineCheckService($db, __DIR__ . '/fixtures/migrations_valid'))->run();
            $this->assert($before === $snapshot(), 'Baseline inspection preserves all fixture data');
        });
    }

    private function testProjectStatusEmpty($db): void
    {
        $this->withFreshSchema($db, function () use ($db) {
            $this->seedBaseline($db);
            $db->query('DELETE FROM project_status');
            $service = new IkontrolBaselineCheckService($db, __DIR__ . '/fixtures/migrations_valid');
            $result = $service->run();
            $this->assert($this->findCheck($result['checks'], 'project_status_catalog')['status'] === 'FAIL', 'Empty project status catalog returns FAIL');
        });
    }

    private function testSatCatalogEmpty($db): void
    {
        $this->withFreshSchema($db, function () use ($db) {
            $this->seedBaseline($db);
            $db->query('DELETE FROM sat_product_service_keys');
            $service = new IkontrolBaselineCheckService($db, __DIR__ . '/fixtures/migrations_valid');
            $result = $service->run();
            $this->assert($this->findCheck($result['checks'], 'sat_product_service_keys')['status'] === 'FAIL', 'Empty SAT product catalog returns FAIL');
        });
    }

    private function testFiscalDisabledDoesNotFail($db): void
    {
        $this->withFreshSchema($db, function () use ($db) {
            $this->seedBaseline($db, ['fiscal_disabled' => true]);
            $service = new IkontrolBaselineCheckService($db, __DIR__ . '/fixtures/migrations_valid');
            $result = $service->run();
            $this->assert($this->findCheck($result['checks'], 'fiscal_structure')['status'] !== 'FAIL', 'Fiscal disabled does not fail the core baseline');
        });
    }

    private function testMigrationMismatchDetected(): void
    {
        $db = $this->db;
        $this->withFreshSchema($db, function () use ($db) {
            $db->query("INSERT INTO migrations (version) VALUES ('2026-07-21-000000')");
            $db->query("INSERT INTO migrations (version) VALUES ('2026-08-13-190100')");
            $db->query("INSERT INTO migrations (version) VALUES ('2026-08-13-190100')");
            $service = new IkontrolBaselineCheckService($db, __DIR__ . '/fixtures/migrations_valid');
            $result = $service->run();
            $this->assert($this->findCheck($result['checks'], 'migration_history')['status'] === 'WARN', 'Migration history mismatch is reported as warning');
        });
    }

    private function testJsonOutputDoesNotLeakSecrets(): void
    {
        $service = new IkontrolBaselineCheckService($this->db, __DIR__ . '/fixtures/migrations_valid');
        $payload = $service->jsonPayload();
        $this->assert(! str_contains($payload, 'password') && ! str_contains($payload, 'secret') && ! str_contains($payload, 'token'), 'JSON payload omits secrets');
    }

    private function withFreshSchema($db, callable $fn): void
    {
        $this->resetTestDatabase($db);
        $this->createRequiredSchema($db);
        $db->resetDataCache();
        try {
            $fn();
        } finally {
            $this->resetTestDatabase($db);
        }
    }

    private function resetTestDatabase($db): void
    {
        foreach (['settings','company','users','roles','clients','items','item_categories','estimates','estimate_items','taxes','invoices','invoice_items','invoice_payments','payment_allocations','payment_methods','financial_accounts','financial_account_movements','project_status','task_status','task_priority','sat_product_service_keys','sat_payment_forms','sat_payment_methods','sat_currencies','fiscal_profiles','fiscal_series','fiscal_documents','fiscal_document_stamps','migrations'] as $table) {
            try {
                $db->query('DROP TABLE IF EXISTS ' . $table);
            } catch (Throwable $e) {
                // ignore if table did not exist
            }
        }
        $db->resetDataCache();
    }

    private function createRequiredSchema($db): void
    {
        $db->query('CREATE TABLE settings (setting_name VARCHAR(255), setting_value VARCHAR(255), deleted TINYINT DEFAULT 0)');
        $db->query('CREATE TABLE company (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(255), status VARCHAR(32), deleted TINYINT DEFAULT 0)');
        $db->query('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, email VARCHAR(255), status VARCHAR(32), user_type VARCHAR(32), is_admin TINYINT DEFAULT 0, role_id INTEGER, disable_login TINYINT DEFAULT 0, deleted TINYINT DEFAULT 0)');
        $db->query('CREATE TABLE roles (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(255), deleted TINYINT DEFAULT 0)');
        $db->query('CREATE TABLE clients (id INTEGER PRIMARY KEY AUTOINCREMENT, company_name VARCHAR(255), deleted TINYINT DEFAULT 0)');
        $db->query('CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(255), deleted TINYINT DEFAULT 0)');
        $db->query('CREATE TABLE item_categories (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(255), deleted TINYINT DEFAULT 0)');
        $db->query('CREATE TABLE estimates (id INTEGER PRIMARY KEY AUTOINCREMENT)');
        $db->query('CREATE TABLE estimate_items (id INTEGER PRIMARY KEY AUTOINCREMENT)');
        $db->query('CREATE TABLE taxes (id INTEGER PRIMARY KEY AUTOINCREMENT)');
        $db->query('CREATE TABLE invoices (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, invoice_total DECIMAL(18,2) DEFAULT 0, status VARCHAR(32), deleted TINYINT DEFAULT 0)');
        $db->query('CREATE TABLE invoice_items (id INTEGER PRIMARY KEY AUTOINCREMENT, invoice_id INTEGER, deleted TINYINT DEFAULT 0)');
        $db->query('CREATE TABLE invoice_payments (id INTEGER PRIMARY KEY AUTOINCREMENT, invoice_id INTEGER, amount DECIMAL(18,2), deleted TINYINT DEFAULT 0)');
        $db->query('CREATE TABLE payment_allocations (id INTEGER PRIMARY KEY AUTOINCREMENT, invoice_payment_id INTEGER, invoice_id INTEGER, deleted TINYINT DEFAULT 0)');
        $db->query('CREATE TABLE payment_methods (id INTEGER PRIMARY KEY AUTOINCREMENT, title VARCHAR(255), available_on_invoice TINYINT DEFAULT 1, deleted TINYINT DEFAULT 0)');
        $db->query('CREATE TABLE financial_accounts (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(255), currency VARCHAR(3), is_active TINYINT DEFAULT 1, deleted TINYINT DEFAULT 0)');
        $db->query('CREATE TABLE financial_account_movements (id INTEGER PRIMARY KEY AUTOINCREMENT, financial_account_id INTEGER, amount DECIMAL(18,2), deleted TINYINT DEFAULT 0)');
        $db->query('CREATE TABLE project_status (id INTEGER PRIMARY KEY AUTOINCREMENT, title VARCHAR(255), deleted TINYINT DEFAULT 0)');
        $db->query('CREATE TABLE task_status (id INTEGER PRIMARY KEY AUTOINCREMENT, title VARCHAR(255), deleted TINYINT DEFAULT 0)');
        $db->query('CREATE TABLE task_priority (id INTEGER PRIMARY KEY AUTOINCREMENT, title VARCHAR(255), deleted TINYINT DEFAULT 0)');
        $db->query('CREATE TABLE sat_product_service_keys (id INTEGER PRIMARY KEY AUTOINCREMENT, code VARCHAR(255), is_active TINYINT DEFAULT 1, description VARCHAR(255))');
        $db->query('CREATE TABLE sat_payment_forms (id INTEGER PRIMARY KEY AUTOINCREMENT, code VARCHAR(255), is_active TINYINT DEFAULT 1)');
        $db->query('CREATE TABLE sat_payment_methods (id INTEGER PRIMARY KEY AUTOINCREMENT, code VARCHAR(255), is_active TINYINT DEFAULT 1)');
        $db->query('CREATE TABLE sat_currencies (id INTEGER PRIMARY KEY AUTOINCREMENT, code VARCHAR(255), is_active TINYINT DEFAULT 1)');
        $db->query('CREATE TABLE fiscal_profiles (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, is_default TINYINT DEFAULT 0, status VARCHAR(32), deleted TINYINT DEFAULT 0)');
        $db->query('CREATE TABLE fiscal_series (id INTEGER PRIMARY KEY AUTOINCREMENT, series VARCHAR(255), deleted TINYINT DEFAULT 0)');
        $db->query('CREATE TABLE fiscal_documents (id INTEGER PRIMARY KEY AUTOINCREMENT, series VARCHAR(255), total DECIMAL(18,2), deleted TINYINT DEFAULT 0)');
        $db->query('CREATE TABLE fiscal_document_stamps (id INTEGER PRIMARY KEY AUTOINCREMENT, fiscal_document_id INTEGER, uuid VARCHAR(255), deleted TINYINT DEFAULT 0)');
        $db->query('CREATE TABLE migrations (version VARCHAR(255), applied_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
    }

    private function seedBaseline($db, array $options = []): void
    {
        $db->query("INSERT INTO migrations (version) VALUES ('2026-01-01-000000')");
        $includeSettings = $options['settings'] ?? true;
        $includeCompany = $options['company'] ?? true;
        $includeUsers = $options['users'] ?? true;
        $includePaymentMethods = $options['payment_methods'] ?? true;
        $includeFinancialAccounts = $options['financial_accounts'] ?? true;
        $includeProjectStatuses = $options['project_status'] ?? true;
        $includeSat = $options['sat'] ?? true;
        $fiscalDisabled = $options['fiscal_disabled'] ?? false;

        if ($includeSettings) {
            $db->query("INSERT INTO settings (setting_name, setting_value, deleted) VALUES ('language', 'spanish', 0)");
            $db->query("INSERT INTO settings (setting_name, setting_value, deleted) VALUES ('default_currency', 'MXN', 0)");
            $db->query("INSERT INTO settings (setting_name, setting_value, deleted) VALUES ('timezone', 'America/Mexico_City', 0)");
            $db->query("INSERT INTO settings (setting_name, setting_value, deleted) VALUES ('module_invoice', '1', 0)");
            $db->query("INSERT INTO settings (setting_name, setting_value, deleted) VALUES ('module_proposal', '1', 0)");
        }

        if ($includeCompany) {
            $db->query("INSERT INTO company (name, status, deleted) VALUES ('Acme Demo', 'active', 0)");
        }

        if ($includeUsers) {
            $db->query("INSERT INTO users (email, status, user_type, is_admin, role_id, disable_login, deleted) VALUES ('admin@example.com', 'active', 'staff', 1, 0, 0, 0)");
        }

        if ($includePaymentMethods) {
            $db->query("INSERT INTO payment_methods (title, available_on_invoice, deleted) VALUES ('Efectivo', 1, 0)");
        }

        if ($includeFinancialAccounts) {
            $db->query("INSERT INTO financial_accounts (name, currency, is_active, deleted) VALUES ('Caja general', 'MXN', 1, 0)");
        }

        if ($includeProjectStatuses) {
            $db->query("INSERT INTO project_status (title, deleted) VALUES ('open', 0)");
            $db->query("INSERT INTO project_status (title, deleted) VALUES ('completed', 0)");
            $db->query("INSERT INTO project_status (title, deleted) VALUES ('hold', 0)");
            $db->query("INSERT INTO task_status (title, deleted) VALUES ('pending', 0)");
            $db->query("INSERT INTO task_status (title, deleted) VALUES ('completed', 0)");
            $db->query("INSERT INTO task_priority (title, deleted) VALUES ('low', 0)");
            $db->query("INSERT INTO task_priority (title, deleted) VALUES ('medium', 0)");
            $db->query("INSERT INTO task_priority (title, deleted) VALUES ('high', 0)");
        }

        if ($includeSat) {
            $db->query("INSERT INTO sat_product_service_keys (code, is_active, description) VALUES ('01010101', 1, 'test')");
            $db->query("INSERT INTO sat_payment_forms (code, is_active) VALUES ('01', 1)");
            $db->query("INSERT INTO sat_payment_methods (code, is_active) VALUES ('PUE', 1)");
            $db->query("INSERT INTO sat_currencies (code, is_active) VALUES ('MXN', 1)");
        }

        if ($fiscalDisabled) {
            $db->query("INSERT INTO settings (setting_name, setting_value, deleted) VALUES ('fiscal_enabled', '0', 0)");
            return;
        }

        $db->query("INSERT INTO fiscal_profiles (client_id, is_default, status, deleted) VALUES (1, 1, 'active', 0)");
        $db->query("INSERT INTO fiscal_series (series, deleted) VALUES ('A', 0)");
        $db->query("INSERT INTO fiscal_documents (series, total, deleted) VALUES ('A', 100.00, 0)");
        $db->query("INSERT INTO fiscal_document_stamps (fiscal_document_id, uuid, deleted) VALUES (1, 'abc', 0)");
    }

    private function findCheck(array $checks, string $key): array
    {
        foreach ($checks as $check) {
            if (($check['key'] ?? null) === $key) {
                return $check;
            }
        }

        return ['status' => 'FAIL', 'key' => $key, 'message' => 'Check not found'];
    }

    private function assert(bool $condition, string $description): void
    {
        if ($condition) {
            $this->passed++;
            fwrite(STDOUT, "[PASS] {$description}\n");
            return;
        }

        $this->failed++;
        fwrite(STDERR, "[FAIL] {$description}\n");
    }
}

exit((new BaselineCheckTestRunner())->run());
