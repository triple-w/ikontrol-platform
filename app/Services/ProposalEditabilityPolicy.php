<?php

declare(strict_types=1);

namespace App\Services;

use CodeIgniter\Database\BaseConnection;

final class ProposalEditabilityPolicy
{
    public function __construct(private ?BaseConnection $db = null)
    {
    }

    public function isEditable(object|int $proposal): bool
    {
        // ID 0 represents creation, not an existing commercial document.
        // Module-level creation authorization remains in Security_Controller.
        if (is_int($proposal) && $proposal <= 0) {
            return true;
        }

        if (is_int($proposal)) {
            $this->db ??= db_connect('default');
            $proposal = $this->db->table('proposals')->where('id', $proposal)->get(1)->getRow();
        }
        if (! $proposal || ! $proposal->id || (int) $proposal->deleted === 1 || $proposal->status === 'accepted' || ! empty($proposal->converted_sale_id)) {
            return false;
        }

        $this->db ??= db_connect('default');
        if ($this->db->fieldExists('proposal_id', 'invoices')) {
            return ! $this->db->table('invoices')->where(['proposal_id' => $proposal->id, 'deleted' => 0])->countAllResults();
        }
        return true;
    }
}
