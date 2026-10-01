# SAT catalogs v1.0.1 — implementation

## Architecture

`sat_catalog_installations` stores one installed manifest record per catalog. It centralizes source version, checksum, counts and metadata; individual catalog rows retain their existing IDs. `AddSatCatalogInfrastructure` is additive: it creates the metadata table and adds `normalized_description` plus active/code and active/description indexes only to product/service and unit catalogs.

`SatCatalogImporterService` streams CSV files declared by `resources/fiscal/catalogs/sat/manifest.json`, validates manifest schema, file path, SHA-256, headers, row count and duplicate codes, and commits one catalog transaction at a time. It maps all ten supported catalogs. Its stable identity is `code`; it updates only mutable fields and never truncates, deletes, replaces IDs or writes fiscal snapshots/documents.

The shipped manifest is intentionally empty infrastructure. It is not an official SAT catalog and test fixtures live only under `tests/Fixtures/SatCatalogs`.

## Command

`php spark fiscal:catalogs:update` imports all valid manifest entries. Options: `--dry-run`, `--catalog=product-service`, `--force` and `--json`. Exit code `0` means success; `2` means validation/import failure. `--dry-run` performs the same validation and change calculation then rolls back. `--force` may deactivate missing codes only when the manifest entry sets `complete_authoritative=true`; no option deletes rows.

With the infrastructure-only manifest currently shipped, a safe example is `php spark fiscal:catalogs:update --dry-run --json`; it returns an empty `catalogs` array because no source artifact has been approved. Once an official artifact is reviewed, run the same command for one catalog first, inspect `inserted`, `updated`, `unchanged`, `missing_from_source`, `missing_references` and potential `deactivated`, then run without `--dry-run`. JSON is the command's stdout and contains the same per-catalog statistics.

## Health check

`ikontrol:baseline-check --json` includes `sat_catalogs` under the fiscal catalog check. Each catalog reports total, active, installed and manifest source metadata, and `EMPTY`, `PARTIAL`, `OK`, `OUTDATED` or `UNMANAGED`. It reports local manifest parity, never SAT regulatory validity.

## Official source later

Obtain a verifiable official artifact, retain source/version/date/coverage, generate each CSV and checksum, and update the global manifest in the same reviewed change. Run dry-run first, inspect missing/reference effects, then import one catalog at a time. Do not use test fixtures or third-party databases as the source.

## Existing instances and DOLD

Back up first. Apply the additive migration through a directed upgrade, run dry-run, verify existing `code` to `id` references, then import. Existing descriptions may change, but IDs, item settings, UUID, XML, payments and historical fiscal documents must remain intact. If rollback is needed, restore the backup or stop before commit; the importer has no destructive rollback.

## Test coverage

`php -d extension=sqlite3 tests/SatCatalogInfrastructure/run.php` uses only an in-memory SQLite schema and test-only CSV fixtures. It verifies code-based ID preservation through `item_fiscal_settings`, updates, insertion, repeated imports, dry-run, inactive handling, checksum/row-count/header/duplicate rejection, authoritative-force protection, JSON encoding and the Select2 ordering contract. `tests/BaselineCheck/run.php` verifies that the expanded baseline diagnostics preserve the existing baseline result.
