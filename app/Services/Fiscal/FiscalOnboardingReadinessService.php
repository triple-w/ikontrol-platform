<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Services\Baseline\IkontrolBaselineCheckService;
use App\Services\Fiscal\Signing\CsdOperationalStatusService;
use RuntimeException;
use Throwable;

final class FiscalOnboardingReadinessService
{
    public function __construct(
        private mixed $db = null,
        private ?object $fiscal = null,
        private ?object $pac = null
    ) {
        $this->db ??= db_connect();
        $this->fiscal ??= config('Fiscal');
        $this->pac ??= config('TimbradorXpress');
    }

    public function inspect(): array
    {
        $requiredTables = [
            'fiscal_profiles',
            'fiscal_issuer_certificates',
            'fiscal_series',
            'item_fiscal_settings',
            'fiscal_payment_method_mappings',
            'sat_product_service_keys',
            'sat_unit_keys',
            'sat_tax_codes',
            'sat_tax_factor_types',
            'sat_cfdi_uses',
            'sat_tax_regimes',
            'sat_tax_object_codes',
            'sat_payment_forms',
            'sat_payment_methods',
            'sat_currencies',
        ];
        $missingTables = array_values(array_filter(
            $requiredTables,
            fn (string $table): bool => ! $this->db->tableExists($table)
        ));

        if ($missingTables !== []) {
            return $this->result(
                'NOT_INSTALLED',
                ['Schema fiscal incompleto.'],
                ['missing_tables' => $missingTables]
            );
        }

        $environment = FiscalRuntimeContext::fiscalEnvironment($this->fiscal);
        $runtime = FiscalRuntimeContext::from($this->fiscal, $this->pac);
        $issuer = $this->db->table('fiscal_profiles')
            ->where([
                'profile_type' => 'issuer',
                'environment' => $environment,
                'is_default' => 1,
            ])
            ->whereIn('status', ['active', 'ready'])
            ->get(1)
            ->getRow();
        $certificate = $issuer
            ? $this->db->table('fiscal_issuer_certificates')
                ->where([
                    'issuer_profile_id' => $issuer->id,
                    'status' => 'valid',
                    'is_default' => 1,
                    'deleted' => 0,
                ])
                ->get(1)
                ->getRow()
            : null;
        $csd = ['ready' => false, 'label' => 'MISSING'];
        if ($certificate) {
            try {
                $csd = (new CsdOperationalStatusService($this->db))->forCertificate($certificate);
            } catch (Throwable) {
                // A malformed or unreadable certificate is reported as not ready.
            }
        }
        $series = $issuer
            ? (int) $this->db->table('fiscal_series')
                ->where([
                    'issuer_profile_id' => $issuer->id,
                    'environment' => $environment,
                    'is_active' => 1,
                    'deleted' => 0,
                ])
                ->countAllResults()
            : 0;
        $catalogs = (new IkontrolBaselineCheckService($this->db))->satCatalogHealth();
        $items = (int) $this->db->table('items')->where('deleted', 0)->countAllResults();
        $configuredItems = (int) $this->db->table('item_fiscal_settings ifs')
            ->select('ifs.item_id')
            ->distinct()
            ->join('items i', 'i.id = ifs.item_id')
            ->whereIn('ifs.status', ['active', 'ready'])
            ->where(['ifs.deleted' => 0, 'i.deleted' => 0])
            ->countAllResults();
        $clients = (int) $this->db->table('clients')->where('deleted', 0)->countAllResults();
        $configuredClients = (int) $this->db->table('fiscal_profiles fp')
            ->select('fp.client_id')
            ->distinct()
            ->join('clients c', 'c.id = fp.client_id')
            ->where([
                'fp.profile_type' => 'receiver',
                'fp.environment' => $environment,
                'c.deleted' => 0,
            ])
            ->whereIn('fp.status', ['active', 'ready'])
            ->countAllResults();
        $availablePaymentMethods = (int) $this->db->table('payment_methods')
            ->where(['deleted' => 0, 'available_on_invoice' => 1])
            ->countAllResults();
        $mappedPaymentMethods = (int) $this->db->table('fiscal_payment_method_mappings')
            ->where('is_active', 1)
            ->countAllResults();

        $blockers = [];
        if (empty($this->fiscal->enabled)) {
            $blockers[] = 'Fiscal no está habilitado para onboarding en la configuración del servidor.';
        }
        if (! $issuer) {
            $blockers[] = 'Emisor fiscal incompleto.';
        }
        if (empty($csd['ready'])) {
            $blockers[] = 'CSD faltante o no utilizable.';
        }
        if ($series < 1) {
            $blockers[] = 'Serie fiscal faltante.';
        }
        if (! $runtime['operational']) {
            $blockers[] = $runtime['errors'][0]
                ?? (! $runtime['pac_configured']
                    ? 'PAC no configurado.'
                    : 'Operación PAC real deshabilitada por configuración del servidor.');
        }
        if (array_filter($catalogs, static fn (array $catalog): bool => $catalog['status'] !== 'OK')) {
            $blockers[] = 'Catálogos SAT vacíos, parciales, no administrados o desactualizados.';
        }
        if ($configuredItems < $items) {
            $blockers[] = 'Hay productos sin configuración fiscal.';
        }
        if ($configuredClients < $clients) {
            $blockers[] = 'Hay clientes sin perfil fiscal.';
        }
        if ($availablePaymentMethods < 1 || $mappedPaymentMethods < $availablePaymentMethods) {
            $blockers[] = 'Métodos de pago sin mapping SAT completo.';
        }
        if (empty($this->fiscal->stampingEnabled)) {
            $blockers[] = 'Timbrado deshabilitado por configuración del servidor.';
        }

        $details = [
            'environment' => $environment,
            'issuer' => ['status' => $issuer ? 'READY' : 'INCOMPLETE', 'id' => $issuer?->id],
            'csd' => ['status' => ! empty($csd['ready']) ? 'READY' : 'MISSING'],
            'series' => ['status' => $series ? 'READY' : 'MISSING', 'active' => $series],
            'pac' => [
                'status' => $runtime['operational'] ? 'READY' : ($runtime['pac_configured'] ? 'TEST' : 'MISSING'),
                'environment' => $runtime['transport_environment'],
            ],
            'catalogs' => $catalogs,
            'products' => [
                'total' => $items,
                'configured' => $configuredItems,
                'incomplete' => max(0, $items - $configuredItems),
            ],
            'clients' => [
                'total' => $clients,
                'configured' => $configuredClients,
                'incomplete' => max(0, $clients - $configuredClients),
            ],
            'payment_methods' => [
                'available' => $availablePaymentMethods,
                'mapped' => $mappedPaymentMethods,
            ],
            'stamping' => ['status' => $blockers === [] ? 'READY' : 'BLOCKED'],
        ];

        return $this->result($blockers === [] ? 'READY' : 'ONBOARDING', $blockers, $details);
    }

    public function assertReadyForStamping(): void
    {
        $status = $this->inspect();
        if ($status['state'] !== 'READY') {
            throw new RuntimeException(
                'FISCAL_ONBOARDING_INCOMPLETE: '
                . ($status['blockers'][0] ?? 'Configuración fiscal incompleta.')
            );
        }
    }

    private function result(string $state, array $blockers, array $details): array
    {
        return [
            'state' => $state,
            'ready' => $state === 'READY',
            'blockers' => $blockers,
            'details' => $details,
        ];
    }
}
