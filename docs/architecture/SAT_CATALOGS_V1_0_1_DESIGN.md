# SAT catalogs v1.0.1 — design

## 1. Current state

iKontrol has ten SAT catalogs, but its repository currently seeds a minimal operational subset. Product/service keys and unit keys have models, remote Select2 searches, `source_version`, validity columns and a legacy importer. The remaining catalogs use `Sat_catalog_model` subclasses or direct queries. No source file, manifest, generalized importer or catalog-level version metadata exists.

`SatItemCatalogImporter` imports only product/service keys and units from an external MySQL schema. It groups by `code`, updates an existing row by `id`, inserts new codes in batches and never truncates or deletes. It is useful evidence for the desired identity contract, but its FactuCare-specific source and per-table mapping do not make it the canonical v1.0.1 importer.

## 2. Tables, ownership and consumers

| Table | Model / migration | Seeder | Main consumers and relationship form | UI search |
| --- | --- | --- | --- | --- |
| `sat_product_service_keys` | `Sat_product_service_keys_model`; `2026-07-23-030000` | `SatProductServiceKeysSeeder` | `item_fiscal_settings.sat_product_service_key_id`; fiscal item/document snapshots | POST `fiscal/catalogs/product-service/search` |
| `sat_unit_keys` | `Sat_unit_keys_model`; `2026-07-23-030100` | `SatUnitKeysSeeder` | `item_fiscal_settings.sat_unit_key_id`; fiscal item/document snapshots | POST `fiscal/catalogs/units/search` |
| `sat_tax_codes` | `Sat_tax_codes_model`; `2026-07-21-010000` | `SatTaxCodesSeeder` | `taxes.sat_tax_code_id`, fiscal tax snapshots | none |
| `sat_tax_factor_types` | `Sat_tax_factor_types_model`; `2026-07-21-010000` | `SatTaxFactorTypesSeeder` | `taxes.factor_type_id`, fiscal tax snapshots | none |
| `sat_cfdi_uses` | `Sat_cfdi_uses_model`; `2026-07-21-010000` | `SatCfdiUsesSeeder` | `fiscal_profiles.default_cfdi_use_id`; receiver snapshots | dropdown/direct code lookup |
| `sat_tax_regimes` | `Sat_tax_regimes_model`; `2026-07-21-010000` | `SatTaxRegimesSeeder` | `fiscal_profiles.tax_regime_id`; issuer/receiver snapshots | dropdown/direct ID lookup |
| `sat_tax_object_codes` | `Sat_tax_object_codes_model`; `2026-07-23-030200` | `SatTaxObjectCodesSeeder` | `item_fiscal_settings.tax_object_code_id`; fiscal item snapshots | inferred by `ItemSettings` |
| `sat_payment_forms` | direct table; `2026-07-26-060000` | migration minimal rows | `fiscal_payment_method_mappings.sat_payment_form_code`; draft/payment validation | dropdown/direct code lookup |
| `sat_payment_methods` | direct table; `2026-07-26-060000` | migration minimal rows | fiscal draft and credit-note `payment_method_code` snapshots | dropdown/direct code lookup |
| `sat_currencies` | direct table; `2026-07-26-060000` | migration minimal rows | draft, complement and credit-note `currency_code` validation | dropdown/direct code lookup |

The item settings UI rejects inactive product/service and unit rows. Fiscal readiness also rejects inactive product, unit, regime and CFDI-use rows. Historical fiscal documents persist codes and snapshots, so a later catalog description change must never rewrite them.

## 3. Risks

- Recreating rows by code would change IDs and break existing foreign-key-like references.
- Deleting or immediately deactivating a referenced key can make an already configured item fail readiness.
- The current `LIKE '%term%'` Select2 query will degrade against a full product/service catalog despite indexes on `description`.
- Existing migration seed rows have inconsistent metadata coverage: some catalogs lack `source_version`, validity columns or a description field.
- The FactuCare importer is an import aid, not an authoritative SAT source.

## 4. Chosen strategy

Use a complete, repository-versioned SAT source and load every supported catalog into the local database. This is preferred over external-only/on-demand rows: Select2 remains local and deterministic, validation works offline, audit/health checks are straightforward, and upgrades do not need an external service. The storage cost is acceptable for a business application; product/service keys are the large catalog and the others are small.

Use UTF-8 CSV files, one catalog per file, with a global JSON manifest. CSV is streamable, reviewable as a diff, portable and avoids loading a full catalog into PHP memory. Do not use CSV.GZ in the initial design: compression reduces Git readability and makes review harder; it can be added later if repository size proves material. JSON is reserved for manifest metadata, not rows. SQL is rejected because it couples source data to a database engine and invites destructive import patterns.

## 5. Identity model

For all ten tables, stable catalog identity is `code`; `id` remains the immutable local surrogate identity. Every current catalog migration defines `UNIQUE(code)`, so this is viable without changing existing IDs.

The importer must normalize source codes only according to the official file specification, preserve leading zeroes as strings, and reject duplicate normalized codes before writes. It must update the row selected by `code`, never by a supplied source ID. For an existing instance, `id=35, code=43211503` remains `id=35, code=43211503`; mutable description and source metadata may change.

## 6. Upsert policy

For every valid source row:

1. locate by `code` under a transaction;
2. insert only when absent;
3. update mutable descriptive, applicability, validity and source metadata when present;
4. retain `id`, `created_at` and all relationships;
5. report inserted, updated, unchanged, deactivated and errors.

The importer must never run `TRUNCATE`, mass `DELETE`, `DROP`, replace IDs or update fiscal snapshots/documents. A catalog update is idempotent for the same manifest checksum.

## 7. Validity policy

Use a combination of official dates and activity state:

- persist official `valid_from` and `valid_to` when supplied;
- a row missing from a newer complete source is retained, assigned a source-derived `valid_to` only when that date is authoritative, and marked `is_active=0` after the review policy confirms its withdrawal;
- rows that remain valid have `is_active=1`, even if referenced historically;
- historical snapshots remain valid evidence regardless of current catalog activity.

Before deactivation, the future command must report reference counts. The first v1.0.1 implementation should default to report-only for disappeared codes unless the manifest explicitly declares a complete authoritative catalog and the operator uses `--force`.

## 8. Versioned source and manifest

Proposed layout:

```text
resources/fiscal/catalogs/sat/
  manifest.json
  product_service.csv
  unit_keys.csv
  tax_codes.csv
  tax_factor_types.csv
  cfdi_uses.csv
  tax_regimes.csv
  tax_object_codes.csv
  payment_forms.csv
  payment_methods.csv
  currencies.csv
```

Use one global manifest so a release can describe an atomic catalog set, while each entry has an independent checksum and row count:

```json
{
  "schema_version": 1,
  "generated_at": "YYYY-MM-DDTHH:MM:SSZ",
  "catalogs": [{
    "catalog_name": "product-service",
    "source": "official SAT publication",
    "source_version": "official-version-or-date",
    "generated_at": "YYYY-MM-DDTHH:MM:SSZ",
    "file": "product_service.csv",
    "checksum": "sha256:...",
    "row_count": 0
  }]
}
```

The implementation must retain the original official artifact outside the runtime source when licensing/provenance permits, record its URL/publication/version/date in the manifest, and reject a file whose SHA-256 or row count differs. This phase does not download or fabricate any SAT values.

## 9. General importer

Replace the narrow importer with `App\\Services\\Fiscal\\SatCatalogImporterService`. Keep `SatItemCatalogImporter` temporarily as a compatibility wrapper that delegates only after its legacy-source contract is explicitly retained or retired in a later migration.

The general service will have per-catalog schema maps for columns, code validation, source fields and validity semantics. It streams CSV, validates headers, checksum and duplicate codes, performs batched upserts by `code`, and returns per-catalog statistics. It covers product/service keys, units, tax codes, factor types, CFDI uses, regimes, tax object codes, payment forms, payment methods and currencies.

## 10. Future update command

Design `php spark fiscal:catalogs:update` with `--dry-run`, `--catalog=product-service`, `--force` and `--json`. It reads only repository files and manifest, validates integrity before database writes, summarizes all changes and returns non-zero on errors. `--dry-run` writes nothing. `--force` permits an explicitly reviewed deactivation phase; it never enables deletion.

Implementation is deferred until source format, missing-row policy and required schema additions receive approval.

## 11. Existing-instance and DOLD upgrade

1. back up the instance and record table counts/checksums;
2. run the new schema migration additively and validate every `UNIQUE(code)` constraint;
3. run importer dry-run and inspect duplicate, invalid and disappearance reports;
4. apply catalog-by-catalog upsert in a transaction;
5. verify existing product settings still resolve to the same IDs/codes and historical XML/UUID are unchanged;
6. defer deactivation until reference-count review; never import a clean database over the instance.

This works for DOLD-derived instances because the canonical key is code, not origin ID. Legacy descriptions may be refreshed while their local IDs and product references remain intact.

## 12. Indexes and performance

Existing indexes are adequate for exact `code`, activity and prefix validity filters. They are not adequate for `%term%` description search at full-catalog scale: leading-wildcard `LIKE` bypasses normal B-tree prefix benefits.

Keep a minimum three-character query and 20/50-row pagination. Change the product/unit search contract to exact code, code prefix, then normalized-description prefix; do not add FULLTEXT initially because it changes linguistic behavior and needs production-volume evidence. Evaluate an additive `normalized_description` column plus composite indexes `(is_active, code)` and `(is_active, normalized_description)` for the two large searchable catalogs. The smaller catalogs need only `UNIQUE(code)` plus activity filters.

## 13. Health check

Extend `ikontrol:baseline-check` with a non-normative `SAT Catalogs` section. For each catalog show active/total counts, latest local `source_version`, checksum, and status:

- `EMPTY`: table absent or zero rows;
- `PARTIAL`: present below manifest row count or missing metadata;
- `OK`: row count/checksum metadata match the installed manifest;
- `OUTDATED`: installed source version/checksum differs from repository manifest.

It must say only that the local data matches a named manifest; it must not claim SAT regulatory validity without a verified official source version.

## 14. Required migrations

No migration is justified in this audit. Reuse existing `source_version`, `valid_from`, `valid_to` and `is_active` for product/service and units. Before implementation, create one additive canonical migration only if the schema inventory confirms a need for:

- `source_version`, `source_checksum`, `source_updated_at` on every catalog, preferably through a separate `sat_catalog_installations` metadata table keyed by catalog name rather than repeated per row;
- validity fields on tax codes, factors, payment forms, methods and currencies if official sources provide them;
- `normalized_description` and safe search indexes on the large searchable tables.

Do not modify historic migrations or add redundant row metadata to every table without the source contract.

## 15. Implementation plan

1. obtain and verify official artifacts, version/date/coverage and licensing;
2. approve CSV header schemas and global manifest;
3. inspect target schemas and add only necessary metadata/index migration;
4. implement parser, manifest verifier and code-based upsert service;
5. implement dry-run command and health-check output;
6. add Select2 search changes only after benchmark fixtures;
7. seed only a minimal bootstrap subset for fresh installation, then install complete catalog through the versioned command.

## 16. Test plan

- parser/header/checksum/row-count failures write nothing;
- repeated manifest import is idempotent;
- existing `id=35/code=43211503` retains ID while description/version update;
- new code inserts once; duplicate source code rejects the catalog;
- disappeared key is retained and report-only without `--force`;
- referenced inactive key remains resolvable historically and is rejected for new selection where policy requires;
- all ten catalog maps, dry-run JSON and per-catalog filtering;
- Select2 exact/prefix search, pagination and inactive filtering on large synthetic fixtures;
- baseline health statuses `EMPTY`, `PARTIAL`, `OK`, `OUTDATED`.

## 17. Exact implementation files

Expected additions: `resources/fiscal/catalogs/sat/manifest.json`, ten CSV files, `app/Services/Fiscal/SatCatalogImporterService.php`, `app/Commands/FiscalCatalogsUpdate.php`, a focused catalog metadata/index migration if justified, and tests for importer/health/search.

Expected adaptations: `app/Services/Fiscal/SatItemCatalogImporter.php`, `app/Commands/ImportSatItemCatalogs.php`, `app/Models/Fiscal/Sat_catalog_model.php`, product/unit models, `app/Controllers/Fiscal/ItemSettings.php`, `app/Config/FiscalRoutes.php`, `app/Services/Baseline/IkontrolBaselineCheckService.php`, minimal SAT seeders, and `app/Services/Database/ExplicitConnectionSeederOrchestrator.php` only where fresh-install bootstrap needs alignment.

No code, migrations, catalog files, seeders or data were changed in this design phase.
