<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/** Normalizes internal and external CFDIs before snapshot/XML construction. */
final class PaymentComplementRelatedDocumentNormalizer
{
    public function __construct(private mixed $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function forPayment(int $complementId, int $paymentId): array
    {
        $documents = [];
        $historical = new FiscalDocumentHistoricalTaxResolver($this->db);
        $internal = $this->db->table('payment_complement_documents d')
            ->select('d.*,fd.series,fd.folio,fd.payment_method_code,fd.status fiscal_status,fd.deleted fiscal_deleted,fd.cancelled_at,s.uuid current_uuid')
            ->join('fiscal_documents fd', 'fd.id=d.fiscal_document_id')
            ->join('fiscal_document_stamps s','s.fiscal_document_id=fd.id')
            ->where(['d.payment_complement_payment_id' => $paymentId, 'd.deleted' => 0])
            ->get()->getResult();
        foreach ($internal as $row) {
            if ($row->fiscal_status !== 'stamped' || (int)$row->fiscal_deleted || $row->cancelled_at || $row->payment_method_code !== 'PPD' || strtoupper((string)$row->current_uuid) !== strtoupper((string)$row->document_uuid)) {
                throw new RuntimeException('El CFDI interno ya no esta timbrado, vigente o PPD.');
            }
            $source = $historical->resolve((int) $row->fiscal_document_id);
            $documents[] = $this->normalize([
                'source_type' => 'internal',
                'source_id' => (int) $row->id,
                'invoice_id' => (int) $row->invoice_id,
                'fiscal_document_id' => (int) $row->fiscal_document_id,
                'uuid' => $row->document_uuid,
                'series' => $row->series,
                'folio' => $row->folio,
                'currency_code' => $row->currency_dr ?: 'MXN',
                'equivalence_dr' => $row->equivalence_dr ?: '1.0000000000',
                'payment_method_code' => $row->payment_method_code ?: 'PPD',
                'tax_object_code' => $source['tax_object_code'],
                'installment_number' => (int) $row->installment_number,
                'previous_balance' => (string) $row->previous_balance,
                'paid_amount' => (string) $row->amount_paid,
                'remaining_balance' => (string) $row->remaining_balance,
                'taxes' => $historical->prorate($source, (string) $row->amount_paid, (string) $row->previous_balance),
            ]);
        }

        if ($this->db->tableExists('payment_complement_external_documents')) {
            $external = $this->db->table('payment_complement_external_documents')
                ->where(['payment_complement_id' => $complementId, 'payment_complement_payment_id' => $paymentId, 'deleted' => 0])
                ->get()->getResult();
            foreach ($external as $row) {
                $taxes = $this->db->table('payment_complement_external_taxes')
                    ->where('external_document_id', $row->id)->orderBy('id')->get()->getResultArray();
                $documents[] = $this->normalize([
                    'source_type' => 'external',
                    'source_id' => (int) $row->id,
                    'invoice_id' => null,
                    'fiscal_document_id' => null,
                    'uuid' => $row->uuid,
                    'series' => $row->series,
                    'folio' => $row->folio,
                    'currency_code' => $row->currency_code,
                    'equivalence_dr' => $row->equivalence_dr,
                    'payment_method_code' => $row->payment_method_code,
                    'tax_object_code' => $row->tax_object_code,
                    'installment_number' => (int) $row->installment_number,
                    'previous_balance' => (string) $row->previous_balance,
                    'paid_amount' => (string) $row->paid_amount,
                    'remaining_balance' => (string) $row->remaining_balance,
                    'taxes' => $taxes,
                ]);
            }
        }

        usort($documents, static fn(array $a, array $b): int => [$a['IdDocumento'], $a['source_type']] <=> [$b['IdDocumento'], $b['source_type']]);
        $seen = [];
        foreach ($documents as $document) {
            $uuid = strtoupper((string) $document['IdDocumento']);
            if (isset($seen[$uuid])) throw new RuntimeException('El UUID esta duplicado dentro del complemento.');
            $seen[$uuid] = true;
        }
        return $documents;
    }

    /** @param array<string,mixed> $source @return array<string,mixed> */
    private function normalize(array $source): array
    {
        $taxes = ['TrasladosDR' => [], 'RetencionesDR' => []];
        foreach ($source['taxes'] as $tax) {
            $node = [
                'BaseDR' => (string) $tax['base'],
                'ImpuestoDR' => (string) $tax['tax_code'],
                'TipoFactorDR' => (string) $tax['factor_type'],
            ];
            if ($node['TipoFactorDR'] !== 'Exento') {
                $node['TasaOCuotaDR'] = (string) $tax['rate_or_quota'];
                $node['ImporteDR'] = (string) $tax['amount'];
            }
            $taxes[$tax['tax_type'] === 'withholding' ? 'RetencionesDR' : 'TrasladosDR'][] = $node;
        }
        return [
            'source_type' => $source['source_type'],
            'source_id' => $source['source_id'],
            'invoice_id' => $source['invoice_id'],
            'fiscal_document_id' => $source['fiscal_document_id'],
            'IdDocumento' => strtoupper(trim((string) $source['uuid'])),
            'Serie' => trim((string) $source['series']),
            'Folio' => trim((string) $source['folio']),
            'MonedaDR' => strtoupper((string) $source['currency_code']),
            'EquivalenciaDR' => (string) $source['equivalence_dr'],
            'MetodoDePagoDR' => (string) $source['payment_method_code'],
            'ObjetoImpDR' => (string) $source['tax_object_code'],
            'NumParcialidad' => (int) $source['installment_number'],
            'ImpSaldoAnt' => (string) $source['previous_balance'],
            'ImpPagado' => (string) $source['paid_amount'],
            'ImpSaldoInsoluto' => (string) $source['remaining_balance'],
            'ImpuestosDR' => $taxes,
        ];
    }
}
