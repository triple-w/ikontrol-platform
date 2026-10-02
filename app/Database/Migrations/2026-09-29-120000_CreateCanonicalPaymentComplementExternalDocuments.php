<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * External CFDIs are fiscal references only. They deliberately have no invoice,
 * allocation, payment, or financial-movement relationship.
 */
final class CreateCanonicalPaymentComplementExternalDocuments extends Migration
{
    public function up(): void
    {
        if ($this->db->DBDriver !== 'MySQLi') {
            throw new RuntimeException('External payment document schema requires a dedicated MySQL fixture.');
        }
        foreach (['payment_complements', 'payment_complement_payments', 'fiscal_documents'] as $table) {
            if (! $this->db->tableExists($table)) {
                throw new RuntimeException('Required payment-complement table is missing: ' . $table);
            }
        }
        $this->assertInvoiceColumn();
        $external = $this->db->prefixTable('payment_complement_external_documents');
        $taxes = $this->db->prefixTable('payment_complement_external_taxes');
        if (! $this->db->tableExists('payment_complement_external_documents')) {
            $this->db->query("CREATE TABLE {$this->db->escapeIdentifiers($external)} (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                payment_complement_id INT UNSIGNED NOT NULL,
                payment_complement_payment_id INT UNSIGNED NOT NULL,
                uuid CHAR(36) NOT NULL,
                series VARCHAR(25) NOT NULL DEFAULT '',
                folio VARCHAR(40) NOT NULL DEFAULT '',
                currency_code CHAR(3) NOT NULL,
                equivalence_dr DECIMAL(28,10) NOT NULL,
                payment_method_code VARCHAR(3) NOT NULL DEFAULT 'PPD',
                tax_object_code CHAR(2) NOT NULL,
                installment_number INT UNSIGNED NOT NULL,
                previous_balance DECIMAL(18,6) NOT NULL,
                paid_amount DECIMAL(18,6) NOT NULL,
                remaining_balance DECIMAL(18,6) NOT NULL,
                created_by INT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                deleted TINYINT(1) NOT NULL DEFAULT 0,
                active_uuid CHAR(36) NULL,
                PRIMARY KEY (id),
                KEY idx_pc_external_complement_active (payment_complement_id, deleted),
                KEY idx_pc_external_payment_active (payment_complement_payment_id, deleted),
                KEY idx_pc_external_uuid (uuid),
                UNIQUE KEY uq_pc_external_active_uuid (payment_complement_id, active_uuid),
                CONSTRAINT fk_pc_external_complement FOREIGN KEY (payment_complement_id) REFERENCES {$this->db->escapeIdentifiers($this->db->prefixTable('payment_complements'))}(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                CONSTRAINT fk_pc_external_payment FOREIGN KEY (payment_complement_payment_id) REFERENCES {$this->db->escapeIdentifiers($this->db->prefixTable('payment_complement_payments'))}(id) ON DELETE RESTRICT ON UPDATE RESTRICT
            ) ENGINE=InnoDB");
        }
        if (! $this->db->tableExists('payment_complement_external_taxes')) {
            $this->db->query("CREATE TABLE {$this->db->escapeIdentifiers($taxes)} (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                external_document_id INT UNSIGNED NOT NULL,
                tax_type VARCHAR(15) NOT NULL,
                base DECIMAL(18,6) NOT NULL,
                tax_code CHAR(3) NOT NULL,
                factor_type VARCHAR(10) NOT NULL,
                rate_or_quota DECIMAL(18,6) NULL,
                amount DECIMAL(18,6) NULL,
                PRIMARY KEY (id),
                KEY idx_pc_external_tax_document (external_document_id),
                CONSTRAINT fk_pc_external_tax_document FOREIGN KEY (external_document_id) REFERENCES {$this->db->escapeIdentifiers($external)}(id) ON DELETE RESTRICT ON UPDATE RESTRICT
            ) ENGINE=InnoDB");
        }
        $this->assertExternalSchema();
        // This preserves every existing invoice reference while allowing P11 to create
        // a payment CFDI backed exclusively by external related documents.
        $invoice = $this->column('fiscal_documents', 'invoice_id');
        if ($invoice['IS_NULLABLE'] !== 'YES') {
            $this->db->query('ALTER TABLE ' . $this->db->escapeIdentifiers($this->db->prefixTable('fiscal_documents'))
                . ' MODIFY invoice_id INT UNSIGNED NULL DEFAULT NULL');
        }
        $this->db->resetDataCache();
    }

    public function down(): void
    {
        // Retain external fiscal history and nullable invoice references on rollback.
    }

    private function assertInvoiceColumn(): void
    {
        $column = $this->column('fiscal_documents', 'invoice_id');
        if (strtolower($column['COLUMN_TYPE']) !== 'int(10) unsigned') {
            throw new RuntimeException('fiscal_documents.invoice_id has an incompatible type.');
        }
    }

    private function assertExternalSchema(): void
    {
        foreach ([
            'payment_complement_external_documents' => ['payment_complement_id', 'payment_complement_payment_id', 'uuid', 'active_uuid', 'equivalence_dr', 'deleted'],
            'payment_complement_external_taxes' => ['external_document_id', 'tax_type', 'tax_code', 'factor_type'],
        ] as $table => $names) {
            foreach ($names as $name) {
                $this->column($table, $name);
            }
        }
        $index = $this->db->getIndexData('payment_complement_external_documents')['uq_pc_external_active_uuid'] ?? null;
        if (! $index || $index->type !== 'UNIQUE' || $index->fields !== ['payment_complement_id', 'active_uuid']) {
            throw new RuntimeException('External document active UUID identity is incompatible.');
        }
    }

    private function column(string $table, string $name): array
    {
        $row = $this->db->query(
            'SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',
            [$this->db->prefixTable($table), $name]
        )->getRowArray();
        if (! $row) {
            throw new RuntimeException('Missing schema column: ' . $table . '.' . $name);
        }
        return $row;
    }
}
