<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

final class ProposalsAuditAcceptedLegacy extends BaseCommand
{
    protected $group = 'Commercial';
    protected $name = 'proposals:audit-accepted-legacy';
    protected $description = 'Audita propuestas legacy aceptadas y sus posibles ventas sin modificar datos.';

    public function run(array $params): void
    {
        $db = db_connect();
        $proposalTable = $db->prefixTable('proposals');
        $invoiceTable = $db->prefixTable('invoices');
        $hasLinks = $db->fieldExists('converted_sale_id', $proposalTable)
            && $db->fieldExists('proposal_id', $invoiceTable);

        $counts = array_fill_keys([
            'accepted_without_sale',
            'accepted_with_single_possible_manual_sale',
            'accepted_with_multiple_possible_sales',
            'backlink_inconsistent',
            'consistent',
            'not_applicable',
        ], 0);

        $proposals = $db->table($proposalTable)->where('deleted', 0)->get()->getResult();
        foreach ($proposals as $proposal) {
            $classification = 'not_applicable';
            if ($proposal->status === 'accepted') {
                $classification = $this->classifyAccepted($db, $invoiceTable, $proposal, $hasLinks);
            }
            $counts[$classification]++;
            CLI::write(sprintf('proposal=%d classification=%s', (int) $proposal->id, $classification));
        }

        CLI::newLine();
        foreach ($counts as $classification => $count) {
            CLI::write($classification . '=' . $count);
        }
        CLI::write('read_only=1', 'green');
    }

    private function classifyAccepted(object $db, string $invoiceTable, object $proposal, bool $hasLinks): string
    {
        if ($hasLinks && !empty($proposal->converted_sale_id)) {
            $invoice = $db->table($invoiceTable)
                ->where('id', (int) $proposal->converted_sale_id)
                ->where('deleted', 0)
                ->get()->getRow();
            return $invoice && (int) $invoice->proposal_id === (int) $proposal->id
                ? 'consistent'
                : 'backlink_inconsistent';
        }

        if ($hasLinks) {
            $backlinks = $db->table($invoiceTable)
                ->where('proposal_id', (int) $proposal->id)
                ->where('deleted', 0)
                ->countAllResults();
            if ($backlinks > 0) {
                return 'backlink_inconsistent';
            }
        }

        // Legacy invoices have no proposal backlink. These are candidates only,
        // based on exact commercial header fields; this command never reconciles them.
        $builder = $db->table($invoiceTable)
            ->where('deleted', 0)
            ->where('client_id', (int) $proposal->client_id)
            ->where('company_id', (int) $proposal->company_id)
            ->where('tax_id', (int) $proposal->tax_id)
            ->where('tax_id2', (int) $proposal->tax_id2)
            ->where('discount_type', (string) $proposal->discount_type)
            ->where('discount_amount', $proposal->discount_amount)
            ->where('discount_amount_type', (string) $proposal->discount_amount_type);
        $candidates = $builder->countAllResults();

        if ($candidates === 0) {
            return 'accepted_without_sale';
        }
        return $candidates === 1
            ? 'accepted_with_single_possible_manual_sale'
            : 'accepted_with_multiple_possible_sales';
    }
}
