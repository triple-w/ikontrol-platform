<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

/**
 * Translates readiness results into navigation hints for the UI.
 * It does not decide readiness and never changes fiscal state.
 */
final class FiscalReadinessActionService
{
    /** @return list<array<string, mixed>> */
    public function onboardingChecklist(array $readiness): array
    {
        if (($readiness['state'] ?? '') === 'NOT_INSTALLED') {
            return [[
                'key' => 'schema', 'label' => 'Infraestructura fiscal', 'status' => 'PENDIENTE',
                'detail' => 'Falta completar la instalación fiscal dirigida.', 'action' => null,
            ]];
        }

        $details = $readiness['details'] ?? [];
        $issuerId = (int) ($details['issuer']['id'] ?? 0);
        $catalogs = $details['catalogs'] ?? [];
        $catalogsReady = $catalogs !== [] && ! array_filter(
            $catalogs,
            static fn (array $catalog): bool => ($catalog['status'] ?? '') !== 'OK'
        );
        $products = $details['products'] ?? [];
        $clients = $details['clients'] ?? [];
        $payments = $details['payment_methods'] ?? [];

        return [
            $this->row('catalogs', 'Catálogos SAT', $catalogsReady, $catalogsReady ? 'Catálogos administrados.' : 'Catálogos vacíos, parciales, no administrados o desactualizados.'),
            $this->row('issuer', 'Emisor', ($details['issuer']['status'] ?? '') === 'READY', 'Configura RFC, razón social, régimen y domicilios fiscales.', 'Configurar emisor', 'fiscal/issuers'),
            $this->row('csd', 'CSD', ($details['csd']['status'] ?? '') === 'READY', 'Carga y valida el certificado de sello digital.', 'Configurar CSD', $issuerId > 0 ? "fiscal/issuers/{$issuerId}/certificates" : 'fiscal/issuers'),
            $this->row('series', 'Serie', ($details['series']['status'] ?? '') === 'READY', 'Crea una serie después de completar el emisor.', 'Configurar serie', 'fiscal/series'),
            $this->row('pac', 'PAC', ($details['pac']['status'] ?? '') === 'READY', 'Revisa adaptador, ambiente y credenciales locales.', 'Configurar PAC', 'fiscal/pac/status'),
            $this->row('products', 'Productos', (int) ($products['incomplete'] ?? 0) === 0, (int) ($products['incomplete'] ?? 0) . ' producto(s) pendientes.', 'Configurar productos', 'items'),
            $this->row('clients', 'Clientes', (int) ($clients['incomplete'] ?? 0) === 0, (int) ($clients['incomplete'] ?? 0) . ' cliente(s) pendientes.', 'Configurar clientes', 'clients'),
            $this->row('payment_methods', 'Métodos de pago', (int) ($payments['available'] ?? 0) > 0 && (int) ($payments['mapped'] ?? 0) >= (int) ($payments['available'] ?? 0), (int) ($payments['mapped'] ?? 0) . ' / ' . (int) ($payments['available'] ?? 0) . ' mapeados.', 'Configurar métodos de pago', 'payment_methods'),
            $this->row('stamping', 'Timbrado', ($details['stamping']['status'] ?? '') === 'READY', 'Permanece bloqueado hasta completar todos los requisitos.'),
        ];
    }

    /** @return array<string, array{label:string,items:list<array<string, mixed>>}> */
    public function saleBlockerGroups(array $saleReview, array $onboarding, int $clientId): array
    {
        $definitions = [
            'issuer' => ['label' => 'Emisor', 'action' => 'Configurar emisor', 'path' => 'fiscal/issuers'],
            'series' => ['label' => 'Serie', 'action' => 'Configurar serie', 'path' => 'fiscal/series'],
            'receiver' => ['label' => 'Cliente', 'action' => 'Configurar cliente', 'path' => $clientId > 0 ? "clients/view/{$clientId}" : 'clients'],
            'items' => ['label' => 'Productos', 'action' => 'Configurar productos', 'path' => 'items'],
            'sale' => ['label' => 'Venta', 'action' => null, 'path' => null],
        ];
        $groups = [];
        foreach ($definitions as $key => $definition) {
            $messages = array_values(array_unique(array_filter(array_map('strval', $saleReview['errors'][$key] ?? []))));
            if ($messages === []) continue;
            $groups[$key] = ['label' => $definition['label'], 'items' => array_map(
                static fn (string $message): array => ['message' => $message, 'action' => $definition['action'], 'path' => $definition['path']],
                $messages
            )];
        }

        foreach ($this->onboardingChecklist($onboarding) as $row) {
            if ($row['status'] === 'OK' || ! in_array($row['key'], ['csd', 'pac', 'payment_methods', 'catalogs'], true)) continue;
            $groups[$row['key']] = ['label' => $row['label'], 'items' => [[
                'message' => $row['detail'],
                'action' => $row['action']['label'] ?? null,
                'path' => $row['action']['path'] ?? null,
            ]]];
        }
        return $groups;
    }

    /** @return array<string, array{label:string,items:list<array<string, mixed>>}> */
    public function draftBlockerGroups(array $blockers, array $onboarding, int $clientId): array
    {
        $definitions = [
            'issuer' => ['label' => 'Emisor', 'action' => 'Configurar emisor', 'path' => 'fiscal/issuers'],
            'receiver' => ['label' => 'Cliente', 'action' => 'Configurar cliente', 'path' => $clientId > 0 ? "clients/view/{$clientId}" : 'clients'],
            'concepts' => ['label' => 'Productos', 'action' => 'Configurar productos', 'path' => 'items'],
            'series' => ['label' => 'Serie', 'action' => 'Configurar serie', 'path' => 'fiscal/series'],
            'document' => ['label' => 'Documento', 'action' => null, 'path' => null],
        ];
        $groups = [];
        foreach ($blockers as $blocker) {
            $section = (string) ($blocker['section'] ?? 'document');
            $definition = $definitions[$section] ?? $definitions['document'];
            $groups[$section] ??= ['label' => $definition['label'], 'items' => []];
            $groups[$section]['items'][] = [
                'message' => (string) ($blocker['message'] ?? 'Revisa la configuración fiscal.'),
                'action' => $definition['action'],
                'path' => $definition['path'],
            ];
        }
        foreach ($this->onboardingChecklist($onboarding) as $row) {
            if ($row['status'] === 'OK' || ! in_array($row['key'], ['csd', 'pac', 'payment_methods', 'catalogs', 'series'], true)) continue;
            $groups[$row['key']] = ['label' => $row['label'], 'items' => [[
                'message' => $row['detail'],
                'action' => $row['action']['label'] ?? null,
                'path' => $row['action']['path'] ?? null,
            ]]];
        }
        return $groups;
    }

    /** @return array<string, mixed> */
    private function row(string $key, string $label, bool $ready, string $detail, ?string $action = null, ?string $path = null): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'status' => $ready ? 'OK' : 'PENDIENTE',
            'detail' => $detail,
            'action' => ! $ready && $action !== null && $path !== null ? ['label' => $action, 'path' => $path] : null,
        ];
    }
}
