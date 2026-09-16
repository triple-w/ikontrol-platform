<?php
declare(strict_types=1);
namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

class AddEstimateSaleAutomationSetting extends Migration
{
    public function up(): void
    {
        if(!$this->db->tableExists('settings'))throw new RuntimeException('RISE settings table is required.');
        $settings=$this->db->table('settings');
        if(!$settings->where('setting_name','create_new_invoices_automatically_when_estimates_gets_accepted')->countAllResults()){
            $settings->insert(['setting_name'=>'create_new_invoices_automatically_when_estimates_gets_accepted','setting_value'=>'0','type'=>'app','deleted'=>0]);
        }
    }
    public function down(): void
    {
        // The key may have existed before this migration; rollback must not delete administrator configuration.
    }
}
