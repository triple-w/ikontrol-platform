<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Proposal_items_model;
use App\Services\Fiscal\FiscalDecimal;
use App\Services\Fiscal\FiscalItemOverrideContract;
use CodeIgniter\Database\BaseConnection;
use RuntimeException;

final class ProposalToInvoiceService
{
    public function __construct(
        private ?InvoiceCreationService $creator = null,
        private ?BaseConnection $db = null
    ) {
        $this->db ??= db_connect('default');
        $this->creator ??= new InvoiceCreationService($this->db);
    }

    public function createFromProposal(object $proposal, int $actorId): int
    {
        $this->validateRelations($proposal);
        $items = (new Proposal_items_model($this->db))->get_details(['proposal_id' => $proposal->id])->getResult();
        if (! $items) {
            throw new RuntimeException('La propuesta requiere al menos una partida activa.');
        }

        $rows = [];
        foreach ($items as $position => $item) {
            $productId = (int) ($item->item_id ?? 0);
            if ($productId > 0 && ! $this->db->table('items')->where(['id' => $productId, 'deleted' => 0])->countAllResults()) {
                throw new RuntimeException('Una partida de la propuesta referencia un producto inexistente o eliminado.');
            }
            $quantityText = trim((string) $item->quantity);
            $rateText = trim((string) $item->rate);
            if (! preg_match('/^(?:0|[1-9]\d*)(?:\.\d+)?$/', $quantityText)
                || ! preg_match('/^(?:0|[1-9]\d*)(?:\.\d+)?$/', $rateText)) {
                throw new RuntimeException('Las cantidades y precios deben ser decimales finitos no negativos.');
            }
            if (FiscalDecimal::micros($quantityText) <= 0) {
                throw new RuntimeException('Todas las cantidades de la propuesta deben ser mayores que cero.');
            }
            if (FiscalDecimal::micros($rateText) < 0) {
                throw new RuntimeException('Todos los precios de la propuesta deben ser valores no negativos válidos.');
            }
            $stored=json_decode((string)($item->fiscal_override_json??''),true);$contract=new FiscalItemOverrideContract();$normalized=$contract->normalizeStored(is_array($stored)?$stored:null,$productId);$overrideJson=$normalized?$contract->encode($normalized):null;
            $rows[] = [
                'item_id' => $productId,
                'title' => (string) $item->title,
                'description' => (string) ($item->description ?? ''),
                'quantity' => $item->quantity,
                'unit_type' => (string) $item->unit_type,
                'cost' => $item->cost ?? null,
                'profit_percentage' => $item->profit_percentage ?? null,
                'price_origin' => ($item->price_origin ?? null) === 'cost_margin' ? 'cost_margin' : 'manual',
                'supplier_id' => $item->supplier_id ?: null,
                'rate' => $item->rate,
                'total' => FiscalDecimal::multiply($quantityText,$rateText),
                'taxable' => 0,
                'fiscal_override_json' => $overrideJson,
                'sort' => (int) ($item->sort ?? $position),
            ];
        }

        $billDate = get_my_local_time('Y-m-d');
        $dueDate = date('Y-m-d', strtotime('+' . (int) get_setting('default_due_date_after_billing_date') . ' days', strtotime($billDate)));
        $header = [
            'type' => 'invoice',
            'client_id' => (int) $proposal->client_id,
            'project_id' => (int) ($proposal->project_id ?: 0),
            'bill_date' => $billDate,
            'due_date' => $dueDate,
            'status' => 'not_paid',
            'commercial_status' => 'open',
            'tax_id' => 0,
            'tax_id2' => 0,
            'tax_id3' => 0,
            'company_id' => (int) $proposal->company_id,
            'note' => $proposal->note,
            'labels' => '',
            'estimate_id' => 0,
            'proposal_id' => (int) $proposal->id,
            'discount_amount' => $proposal->discount_amount,
            'discount_amount_type' => $proposal->discount_amount_type,
            'discount_type' => $proposal->discount_type,
            'created_by' => $actorId,
            'files' => serialize([]),
            'recurring' => 0,
            'repeat_every' => 1,
            'repeat_type' => 'months',
            'no_of_cycles' => 0,
        ];

        return $this->creator->create($header, $rows, false);
    }

    private function validateRelations(object $proposal): void
    {
        $client = $this->db->table('clients')->where(['id' => $proposal->client_id, 'deleted' => 0])->get(1)->getRow();
        if (! $client || (int) $client->is_lead === 1) {
            throw new RuntimeException('El cliente de la propuesta no es válido para crear una venta.');
        }
        if (! $this->db->table('company')->where(['id' => $proposal->company_id, 'deleted' => 0])->countAllResults()) {
            throw new RuntimeException('La empresa de la propuesta no es válida.');
        }
        if ($proposal->project_id) {
            $project = $this->db->table('projects')->where(['id' => $proposal->project_id, 'deleted' => 0])->get(1)->getRow();
            if (! $project || (int) $project->client_id !== (int) $proposal->client_id) {
                throw new RuntimeException('El proyecto de la propuesta no corresponde al cliente.');
            }
        }
        foreach ([(int) $proposal->tax_id, (int) $proposal->tax_id2] as $taxId) {
            if ($taxId && ! $this->db->table('taxes')->where(['id' => $taxId, 'deleted' => 0])->countAllResults()) {
                throw new RuntimeException('La propuesta contiene un impuesto inexistente o eliminado.');
            }
        }
    }
}
