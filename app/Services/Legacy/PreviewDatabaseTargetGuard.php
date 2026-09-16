<?php
declare(strict_types=1);
namespace App\Services\Legacy;

use RuntimeException;

final class PreviewDatabaseTargetGuard
{
    private const EXPECTED = 'ikontrol20_dold_preview';
    private const FORBIDDEN = ['ikontrol20_clean', 'ikontrol_new', 'fc2_migration_source', 'tws001_factucare'];

    public function __construct(private $db, private string $group = 'dold_preview') {}

    public function verify(string $confirmedDatabase): array
    {
        $actual = (string) ($this->db->query('SELECT DATABASE() AS db')->getRow()->db ?? '');
        $configured = (string) $this->db->getDatabase();
        if ($this->group !== 'dold_preview' || $confirmedDatabase !== self::EXPECTED || $actual !== self::EXPECTED || $configured !== self::EXPECTED) {
            throw new RuntimeException('Preview database target mismatch; no writes were performed.');
        }
        foreach (self::FORBIDDEN as $forbidden) {
            if (strcasecmp($actual, $forbidden) === 0) throw new RuntimeException('Protected database rejected.');
        }
        if ((string) $this->db->DBPrefix !== 'ikontrol_') throw new RuntimeException('Unexpected preview database prefix.');
        return ['database' => $actual, 'host' => (string) $this->db->hostname, 'port' => (int) $this->db->port, 'prefix' => (string) $this->db->DBPrefix];
    }
}
