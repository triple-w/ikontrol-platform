<?php
declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

class NormalizeEstimateSaleAutomationSetting extends Migration
{
    private const SETTING = 'create_new_invoices_automatically_when_estimates_gets_accepted';

    public function up(): void
    {
        if (!$this->db->tableExists('settings')) {
            throw new RuntimeException('RISE settings table is required.');
        }

        $settings = $this->db->table('settings');
        $existing = $settings->where('setting_name', self::SETTING)->get()->getRow();

        if (!$existing) {
            $settings->insert([
                'setting_name' => self::SETTING,
                'setting_value' => '0',
                'type' => 'app',
                'deleted' => 0,
            ]);
            return;
        }

        if ($existing->setting_value === '' || $existing->setting_value === null) {
            $settings->where('setting_name', self::SETTING)->update(['setting_value' => '0']);
        }
    }

    public function down(): void
    {
        // "0" may be an intentional administrator choice, so rollback must not guess its prior value.
    }
}
