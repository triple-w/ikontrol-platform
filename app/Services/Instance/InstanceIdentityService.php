<?php

declare(strict_types=1);

namespace App\Services\Instance;

use CodeIgniter\Database\BaseConnection;

final class InstanceIdentityService
{
    public function __construct(private BaseConnection $db)
    {
    }

    public function get(): ?string
    {
        $row = $this->db->table('settings')->select('setting_value')->where(['setting_name' => 'ikontrol_instance_uuid', 'deleted' => 0])->get(1)->getRowArray();
        return $row ? (string) $row['setting_value'] : null;
    }

    public function ensure(): string
    {
        $existing = $this->get();
        if ($existing !== null && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $existing)) return $existing;
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
        $settings = $this->db->table('settings');
        $stored = $settings->where('setting_name', 'ikontrol_instance_uuid')->get(1)->getRowArray();
        $values = ['setting_value' => $uuid, 'type' => 'app', 'deleted' => 0];
        if ($stored) {
            $settings->where('setting_name', 'ikontrol_instance_uuid')->update($values);
        } else {
            $settings->insert(['setting_name' => 'ikontrol_instance_uuid'] + $values);
        }
        return $uuid;
    }
}
