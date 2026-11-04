<?php
declare(strict_types=1);

namespace App\Services\Projects;

use CodeIgniter\Database\BaseConnection;
use RuntimeException;

/** Restores the canonical RISE project status IDs without rewriting instance data. */
final class ProjectStatusInitializerService
{
    private const STATUSES = [
        1 => ['Abierto', 'open', 'open', 'grid'],
        2 => ['Completado', 'completed', 'completed', 'check-circle'],
        3 => ['Espera', 'hold', '', 'pause-circle'],
        4 => ['Cancelado', 'canceled', '', 'x-circle'],
    ];

    public function __construct(private BaseConnection $db) {}

    public function initialize(bool $dryRun = true): array
    {
        if (!$this->db->tableExists('project_status')) {
            throw new RuntimeException('Required initialization table missing: project_status');
        }
        $changes = [];
        foreach (self::STATUSES as $id => [$title, $languageKey, $keyName, $icon]) {
            $row = $this->db->table('project_status')->where('id', $id)->get(1)->getRowArray();
            if ($row) {
                if (!$this->compatible($row, $languageKey, $keyName)) {
                    throw new RuntimeException("Project status ID {$id} is occupied by a custom/incompatible value; manual review is required.");
                }
                $changes[] = ['id'=>$id, 'key'=>$languageKey, 'action'=>'unchanged'];
                continue;
            }
            $equivalent = $this->db->table('project_status')
                ->groupStart()->where('title_language_key', $languageKey)
                ->orWhere('key_name', $keyName !== '' ? $keyName : '__none__')->groupEnd()
                ->get(1)->getRowArray();
            if ($equivalent) {
                throw new RuntimeException("Canonical project status {$languageKey} exists under another ID; automatic duplication is unsafe.");
            }
            $changes[] = ['id'=>$id, 'key'=>$languageKey, 'action'=>'insert'];
            if (!$dryRun) {
                $this->db->table('project_status')->insert([
                    'id'=>$id, 'title'=>$title, 'title_language_key'=>$languageKey,
                    'key_name'=>$keyName, 'icon'=>$icon, 'deleted'=>0,
                ]);
            }
        }
        return ['ready'=>true, 'changes'=>$changes];
    }

    private function compatible(array $row, string $languageKey, string $keyName): bool
    {
        return (int)($row['deleted'] ?? 0) === 0
            && (string)($row['title_language_key'] ?? '') === $languageKey
            && (string)($row['key_name'] ?? '') === $keyName;
    }
}
