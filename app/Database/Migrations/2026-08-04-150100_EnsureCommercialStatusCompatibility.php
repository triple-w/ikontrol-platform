<?php
declare(strict_types=1);
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
final class EnsureCommercialStatusCompatibility extends Migration
{
 public function up():void
 {
  $e=$this->db->protectIdentifiers($this->db->prefixTable('estimates'));$i=$this->db->protectIdentifiers($this->db->prefixTable('invoices'));
  $this->db->query("ALTER TABLE $e MODIFY `status` VARCHAR(20) NOT NULL DEFAULT 'draft'");
  $this->db->query("UPDATE $e e LEFT JOIN $i i ON i.estimate_id=e.id AND i.deleted=0 SET e.status=CASE WHEN i.id IS NOT NULL THEN 'converted' ELSE 'accepted' END,e.converted_sale_id=COALESCE(e.converted_sale_id,i.id),e.converted_at=CASE WHEN i.id IS NOT NULL THEN COALESCE(e.converted_at,NOW()) ELSE e.converted_at END,e.converted_by=CASE WHEN i.id IS NOT NULL THEN COALESCE(e.converted_by,i.created_by) ELSE e.converted_by END WHERE e.status=''");
 }
 public function down():void{}
}
