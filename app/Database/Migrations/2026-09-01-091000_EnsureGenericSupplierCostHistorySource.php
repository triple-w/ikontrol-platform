<?php
declare(strict_types=1);
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;

/** Idempotent repair for deployments where generic supplier-history columns are missing. */
final class EnsureGenericSupplierCostHistorySource extends Migration
{
    private const TABLE = 'product_supplier_cost_history';

    public function up()
    {
        if (!$this->db->tableExists(self::TABLE)) return;
        $columns=[
            'source_type'=>['type'=>'VARCHAR','constraint'=>20,'null'=>true,'after'=>'id'],
            'source_id'=>['type'=>'INT','null'=>true,'after'=>'source_type'],
            'source_item_id'=>['type'=>'INT','null'=>true,'after'=>'source_id'],
            'source_folio'=>['type'=>'VARCHAR','constraint'=>80,'null'=>true,'after'=>'source_item_id'],
        ];
        foreach ($columns as $name => $definition) {
            if (!$this->columnExists($name)) {
                $this->forge->addColumn(self::TABLE, [$name => $definition]);
                $this->db->resetDataCache();
            }
        }
        $table=$this->db->prefixTable(self::TABLE);
        $this->db->query(
            "UPDATE {$table} SET "
            . "source_type=CASE WHEN source_type IS NULL OR source_type='' THEN 'proposal' ELSE source_type END, "
            . 'source_id=COALESCE(source_id, proposal_id), '
            . 'source_item_id=COALESCE(source_item_id, proposal_item_id) '
            . "WHERE (source_type IS NULL OR source_type='' OR source_type='proposal') "
            . 'AND proposal_id IS NOT NULL AND proposal_item_id IS NOT NULL '
            . "AND (source_type IS NULL OR source_type='' OR source_id IS NULL OR source_item_id IS NULL)"
        );
        $this->dropForeignKeyIfPresent('fk_cost_history_proposal_item');
        $this->dropForeignKeyIfPresent('fk_cost_history_proposal');
        $this->db->query("ALTER TABLE {$table} MODIFY proposal_id INT NULL, MODIFY proposal_item_id INT NULL");
        if ($this->indexExists('uq_supplier_cost_item_economic')) {
            $this->db->query("ALTER TABLE {$table} DROP INDEX uq_supplier_cost_item_economic");
        }
        if (!$this->indexExists('uq_cost_history_source_economic')) {
            $this->db->query("ALTER TABLE {$table} ADD UNIQUE KEY uq_cost_history_source_economic(source_type,source_item_id,economic_hash)");
        }
        if (!$this->indexExists('idx_cost_history_source')) {
            $this->db->query("ALTER TABLE {$table} ADD INDEX idx_cost_history_source(source_type,source_id,source_item_id)");
        }
    }

    public function down(){/* Non-destructive: Estimate/Invoice origins cannot be represented by legacy columns. */}

    private function columnExists(string $name): bool
    {
        // CI 4.6 caches getFieldNames(). The preceding 090000 migration can
        // therefore leave fieldExists() with a stale pre-ALTER column list.
        $this->db->resetDataCache();

        return $this->db->fieldExists($name, self::TABLE);
    }

    private function indexExists(string $name): bool
    {
        $this->db->resetDataCache();

        return isset($this->db->getIndexData(self::TABLE)[$name]);
    }

    private function dropForeignKeyIfPresent(string$name):void
    {
        $row=$this->db->query('SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=? AND CONSTRAINT_TYPE=?',[$this->db->prefixTable(self::TABLE),$name,'FOREIGN KEY'])->getRow();
        if($row){$table=$this->db->prefixTable(self::TABLE);$this->db->query("ALTER TABLE {$table} DROP FOREIGN KEY {$name}");}
    }
}
