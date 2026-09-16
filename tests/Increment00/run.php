<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

final class Increment00TestRunner
{
    private int $passed = 0;
    private int $failed = 0;

    public function run(): int
    {
        $this->testFiscalConfigurationLoadsDisabledAndOffline();
        $this->testNoPublicFiscalStampRouteExists();
        $this->testBaselineDoesNotRecreateOrDropRiseTables();
        $this->testSensitiveFilesAreIgnored();

        fwrite(STDOUT, sprintf("\n%d passed, %d failed.\n", $this->passed, $this->failed));

        return $this->failed === 0 ? 0 : 1;
    }

    private function testFiscalConfigurationLoadsDisabledAndOffline(): void
    {
        $config = config(Config\Fiscal::class);

        $this->assert($config instanceof Config\Fiscal, 'Fiscal configuration loads through CodeIgniter');
        $this->assert(is_bool($config->enabled), 'Fiscal module enablement is an explicit server boolean');
        $this->assert(in_array($config->runtimeMode, ['integration','production','automated_test'], true), 'Fiscal runtime mode is explicit');
        $this->assert($config->runtimeMode === 'automated_test' || $config->pacAdapter !== 'fake', 'Visible runtime never defaults to fake');
    }

    private function testNoPublicFiscalStampRouteExists(): void
    {
        $fiscalRoutes = $this->read('app/Config/FiscalRoutes.php');
        $routes = $this->read('app/Config/Routes.php');

        $this->assert(! str_contains($fiscalRoutes, '(:any)') && ! str_contains($fiscalRoutes, '(.*)'), 'FiscalRoutes keeps fiscal operations explicit without catch-all endpoints');
        $this->assert(! preg_match('/(?:stamp|timbrar|cancelar|pac)[^\r\n]*\$routes->/i', $fiscalRoutes), 'No fiscal operation is routed');
        $this->assert(str_contains($routes, "require APPPATH . 'Config/FiscalRoutes.php';"), 'Explicit fiscal route file is integrated');
        $this->assert(str_contains($routes, 'is_file($dir . $file)'), 'Dynamic RISE routing excludes controller directories');
    }

    private function testBaselineDoesNotRecreateOrDropRiseTables(): void
    {
        $migration = $this->read('app/Database/Migrations/2026-07-21-000000_RiseAdministrativeBaseline.php');
        $required = [
            'settings', 'users', 'roles', 'clients', 'items', 'estimates',
            'estimate_items', 'invoices', 'invoice_items', 'invoice_payments',
            'payment_methods', 'taxes', 'company',
        ];

        foreach ($required as $table) {
            $this->assert(str_contains($migration, "'{$table}'"), "Baseline verifies {$table}");
            $this->assert(! preg_match("/createTable\\(\\s*['\"]{$table}['\"]/", $migration), "Baseline does not create {$table}");
            $this->assert(! preg_match("/dropTable\\(\\s*['\"]{$table}['\"]/", $migration), "Baseline does not drop {$table}");
        }

        $this->assert(str_contains($migration, "createTable('app_schema_versions')"), 'Baseline creates only its diagnostic version table');
        $this->assert(! str_contains($migration, 'dropTable('), 'Baseline rollback drops no table');
        $this->assert(str_contains($migration, 'RISE baseline aborted'), 'Baseline has a clear failure message');
    }

    private function testSensitiveFilesAreIgnored(): void
    {
        $ignore = $this->read('.gitignore');
        $patterns = [
            '.env',
            'writable/cache/**',
            'writable/logs/**',
            'writable/session/**',
            'writable/fiscal-private/**',
            '*.cer',
            '*.key',
            '*.pem',
        ];

        foreach ($patterns as $pattern) {
            $this->assert(str_contains($ignore, $pattern), ".gitignore includes {$pattern}");
        }
    }

    private function read(string $relativePath): string
    {
        $contents = file_get_contents(ROOTPATH . $relativePath);
        if ($contents === false) {
            throw new RuntimeException("Unable to read {$relativePath}");
        }

        return $contents;
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

exit((new Increment00TestRunner())->run());
