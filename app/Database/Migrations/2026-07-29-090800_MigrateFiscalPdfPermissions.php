<?php
declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class MigrateFiscalPdfPermissions extends Migration
{
    public function up():void{$this->rename('fiscal_stamped_pdf_view','fiscal_pdf_view');$this->rename('fiscal_stamped_pdf_download','fiscal_pdf_download');}
    public function down():void{$this->rename('fiscal_pdf_view','fiscal_stamped_pdf_view');$this->rename('fiscal_pdf_download','fiscal_stamped_pdf_download');}
    private function rename(string$from,string$to):void
    {
        if(!$this->db->tableExists('roles')||!$this->db->fieldExists('permissions','roles'))return;
        foreach($this->db->table('roles')->select('id,permissions')->get()->getResult()as$role){
            $permissions=@unserialize((string)$role->permissions);if(!is_array($permissions)||!array_key_exists($from,$permissions))continue;
            if(!array_key_exists($to,$permissions))$permissions[$to]=$permissions[$from];unset($permissions[$from]);
            $this->db->table('roles')->where('id',$role->id)->update(['permissions'=>serialize($permissions)]);
        }
    }
}
