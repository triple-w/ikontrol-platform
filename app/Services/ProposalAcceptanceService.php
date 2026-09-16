<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Users_model;
use CodeIgniter\Database\BaseConnection;
use RuntimeException;

final class ProposalAcceptanceService
{
    public function __construct(
        private ?ProposalToInvoiceService $converter = null,
        private ?BaseConnection $db = null
    ) {
        $this->db ??= db_connect('default');
        $this->converter ??= new ProposalToInvoiceService(null, $this->db);
    }

    public function acceptAndConvert(int $proposalId, int $actorId, ?string $publicKey = null, array $acceptanceData = []): array
    {
        $this->db->transBegin();
        try {
            $table = $this->db->prefixTable('proposals');
            $proposal = $this->db->query("SELECT * FROM {$table} WHERE id=? AND deleted=0 FOR UPDATE", [$proposalId])->getRow();
            if (! $proposal) {
                throw new RuntimeException('La propuesta no existe o fue eliminada.');
            }
            $conversionActorId = $this->authorizedConversionActor($actorId, $proposal, $publicKey);

            if ($proposal->converted_sale_id) {
                $invoice = $this->db->table('invoices')->where(['id' => $proposal->converted_sale_id, 'proposal_id' => $proposalId, 'deleted' => 0])->get(1)->getRow();
                if (! $invoice) {
                    throw new RuntimeException('El vínculo de conversión de la propuesta es inconsistente.');
                }
                $this->db->transCommit();
                return ['proposal_id' => $proposalId, 'invoice_id' => (int) $invoice->id, 'invoice_action' => 'existing'];
            }

            $linked = $this->db->table('invoices')->where(['proposal_id' => $proposalId, 'deleted' => 0])->get()->getResult();
            if ($linked) {
                throw new RuntimeException('Existe una venta vinculada sin backlink consistente; se requiere revisión manual.');
            }
            if (! in_array($proposal->status, ['draft', 'sent', 'accepted'], true)) {
                throw new RuntimeException('El estado actual de la propuesta no permite aceptarla.');
            }

            $invoiceId = $this->converter->createFromProposal($proposal, $conversionActorId);
            $now = get_current_utc_time();
            $proposalUpdate = [
                'status' => 'accepted',
                'accepted_by' => $actorId,
                'accepted_at' => $now,
                'converted_sale_id' => $invoiceId,
                'converted_at' => $now,
                'converted_by' => $conversionActorId,
            ];
            if (array_key_exists('meta_data', $acceptanceData)) {
                $proposalUpdate['meta_data'] = (string) $acceptanceData['meta_data'];
            }
            $updated = $this->db->table('proposals')->where(['id' => $proposalId, 'deleted' => 0])->update($proposalUpdate);
            if (! $updated) {
                throw new RuntimeException('No fue posible cerrar la propuesta.');
            }
            if ($this->db->tableExists('commercial_lifecycle_audit')) {
                $this->db->table('commercial_lifecycle_audit')->insert([
                    'entity_type' => 'proposal', 'entity_id' => $proposalId,
                    'event' => 'proposal_converted', 'old_status' => $proposal->status,
                    'new_status' => 'accepted', 'reason' => null,
                    'user_id' => $conversionActorId, 'created_at' => $now,
                ]);
            }
            (new SupplierCostHistoryService($this->db))->snapshotProposal($proposalId,'accepted',$conversionActorId);
            if (! $this->db->transStatus()) {
                throw new RuntimeException('La transacción de aceptación no pudo completarse.');
            }
            $this->db->transCommit();
            return ['proposal_id' => $proposalId, 'invoice_id' => $invoiceId, 'invoice_action' => 'created'];
        } catch (\Throwable $e) {
            $this->db->transRollback();
            $this->db->resetTransStatus();
            throw $e;
        }
    }

    private function authorizedConversionActor(int $actorId, object $proposal, ?string $publicKey): int
    {
        if ($publicKey !== null) {
            if (! in_array($proposal->status, ['sent', 'accepted'], true)
                || ! hash_equals((string) $proposal->public_key, $publicKey)) {
                throw new RuntimeException('El enlace no permite aceptar esta propuesta.');
            }
            if ($actorId > 0) {
                $actor = (new Users_model($this->db))->get_access_info($actorId);
                if ($actor && $actor->user_type === 'client' && (int) $actor->client_id !== (int) $proposal->client_id) {
                    throw new RuntimeException('El cliente no puede aceptar esta propuesta.');
                }
            }
            return $this->proposalOwner($proposal);
        }
        $actor = (new Users_model($this->db))->get_access_info($actorId);
        if (! $actor || ! $actor->id) {
            throw new RuntimeException('El actor no puede aceptar propuestas.');
        }
        if ($actor->user_type === 'client') {
            if ((int) $actor->client_id !== (int) $proposal->client_id
                || ! in_array($proposal->status, ['sent', 'accepted'], true)) {
                throw new RuntimeException('El cliente no puede aceptar esta propuesta.');
            }
            return $this->proposalOwner($proposal);
        }
        if ($actor->user_type !== 'staff') {
            throw new RuntimeException('El actor no puede aceptar propuestas.');
        }
        if ((int) $actor->is_admin === 1) {
            return $actorId;
        }
        $permissions = @unserialize((string) $actor->permissions) ?: [];
        $proposalPermission = $permissions['proposal'] ?? '';
        $invoicePermission = $permissions['invoice'] ?? '';
        $canAccessProposal = $proposalPermission === 'all'
            || ($proposalPermission === 'own' && (int) $proposal->created_by === $actorId);
        $canCreateSale = in_array($invoicePermission, [
            'all', 'manage_own_client_invoices', 'manage_own_client_invoices_except_delete',
            'manage_only_own_created_invoices', 'manage_only_own_created_invoices_except_delete',
        ], true);
        if (($permissions['proposal.accept_and_convert'] ?? '') !== '1' || ! $canAccessProposal || ! $canCreateSale) {
            throw new RuntimeException('El actor no tiene permisos para aceptar y convertir esta propuesta.');
        }
        return $actorId;
    }

    private function proposalOwner(object $proposal): int
    {
        $ownerId = (int) ($proposal->created_by ?? 0);
        $owner = $ownerId ? (new Users_model($this->db))->get_access_info($ownerId) : null;
        if (! $owner || ! $owner->id || $owner->user_type !== 'staff') {
            throw new RuntimeException('La propuesta no tiene un responsable válido para crear la venta.');
        }
        return $ownerId;
    }
}
