# iKontrol 1.1: baseline, upgrades and instance control

## Canonical baseline

iKontrol 1.1.0 is the first baseline with a directed instance updater. Historical customer databases must never be advanced with `php spark migrate`. A clean installation may run the isolated clean installer; a Smartfree RISE 3.9.4 prefiscal instance must first run its certified bridge and then the directed release updater.

## Self-contained Smartfree bridge

`SmartfreePrefiscalBridgeService` opens only the selected Smartfree database. B020 declares the 41 modern columns required by shared legacy tables. B050 and B080 execute repository-owned SQL resources:

- `ikontrol-1.0.0-fiscal.sql`: 59 fiscal/SAT/payment-complement tables.
- `ikontrol-1.0.0-logistics.sql`: 6 supplier/warehouse tables.

The fiscal resource includes `sale_fiscal_pricing_preparations`, `sat_catalog_installations`, and `normalized_description VARCHAR(600)` for product/service and unit keys. `active_uuid` is a nullable physical `CHAR(36)` with the existing unique index; the application writes the UUID for active rows and clears it on logical deletion. This preserves uniqueness without MariaDB 10.6 generated-column expressions.

B000 fingerprints tables, column types, approved orphan profiles and dynamic counts. It does not require frozen business counts. `PhysicalSchemaInspector` reads `information_schema` directly after DDL and avoids CodeIgniter metadata-cache false negatives. B030, payment reconciliation, orphan policy and B110 snapshots are unchanged.

## SAT catalogs

`SatCatalogInfrastructureService` is the additive directed prerequisite. It creates catalog installation metadata, adds normalized descriptions, backfills them deterministically and creates the active/code and active/description indexes. `SatCatalogImporterService` then validates manifest version, headers, SHA-256, row count and duplicate codes; it upserts by `code`, preserves IDs and never deletes references.

The import point is:

- clean install: after canonical schema and minimal seed prerequisites;
- existing 1.0.0 or bridged Smartfree: the `sat_catalog_import` step of `ikontrol:upgrade`.

The repository currently contains only the empty infrastructure manifest. The ten official CSV artifacts described for this release are not present in this checkout. Clean installation and the real 1.0.0 → 1.1.0 upgrade therefore fail closed until those verified artifacts and their populated manifest are supplied. Test fixtures remain under `tests/Fixtures` and are never treated as official data.

## Fiscal modes

`FiscalInstanceModeService` projects configuration and readiness into:

- `DISABLED`: `fiscal.enabled=false`; PAC and stamping unavailable.
- `ONBOARDING`: fiscal administration is visible, but one or more production guards/readiness checks are missing.
- `PRODUCTION`: runtime mode and fiscal environment are production, a real adapter is selected, real PAC and stamping are explicitly enabled, and readiness is complete.

`ikontrol:fiscal:status --json` reports booleans and blockers only. It never returns CSD/PAC keys, passwords or credentials. The existing stamping and cancellation guard remains the enforcement boundary.

## Directed versioning

`InstanceVersionService` reads `app_schema_versions`. `InstanceUpgradeService` currently declares one package, 1.0.0 → 1.1.0, with a deterministic release checksum and these resumable steps:

1. SAT catalog schema;
2. SAT catalog import;
3. stable instance UUID;
4. feature defaults;
5. version record.

`instance_upgrade_runs` and `instance_upgrade_steps` record start, completion/failure, versions, release identifier/checksum, step results and errors. A failed run resumes completed steps; a completed package is not executed again. Planning is read-only.

## Feature flags

`InstanceFeaturesService` is the canonical adapter over existing settings. Clients, sales and payments are mandatory. Optional features include suppliers, warehouses, proposals, advanced estimates, tasks/kanban, calendar, reports, banks, cash, inventory and fiscal. Disabling a feature changes availability only; it never removes schema or data. `InstanceFeatureFilter` provides the route boundary `instancefeature:<feature>` for gradual route adoption, while menus should consume the same service rather than add new setting checks.

## Future iKontrolAdmin contract

`InstanceControlSnapshotService` is the safe internal read model: instance UUID, current/target version, upgrade plan, features, fiscal mode/readiness and last upgrade. A future adapter may add health and stamp balance. It must use TLS plus a per-instance API token to sign `timestamp + nonce + method + path + SHA-256(body)` with HMAC-SHA256, reject replay/expired timestamps and audit every write.

The contract must never expose CSD/PAC encryption keys, PAC credentials, CSD passwords, private certificate material, database credentials or contingency payloads. Remote upgrade execution remains out of scope.

## Commands

```powershell
php spark ikontrol:legacy-upgrade:smartfree --database=LOCAL_COPY --dry-run --json
php spark ikontrol:legacy-upgrade:smartfree --database=LOCAL_COPY --execute --yes --json
php spark ikontrol:version --json
php spark ikontrol:upgrade:plan --target=1.1.0 --json
php spark ikontrol:upgrade --target=1.1.0 --yes --json
php spark fiscal:catalogs:update --dry-run --json
php spark ikontrol:fiscal:status --json
```

Back up and test restore before every bridge or upgrade. No directed package invokes the global migration runner.
