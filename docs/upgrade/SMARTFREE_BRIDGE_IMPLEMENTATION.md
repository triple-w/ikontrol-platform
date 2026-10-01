# Smartfree prefiscal bridge

`ikontrol:legacy-upgrade:smartfree` is a directed, local-only bridge from `smartfree-rise-3.9.4-ci4.6.1-prefiscal-v1` to `ikontrol-1.0.0`. It does not invoke `spark migrate`, replay Platform history, delete legacy rows, renumber IDs, or create fiscal documents from historical invoices.

## Commands

```powershell
php spark ikontrol:legacy-upgrade:smartfree --database=<local_copy> --template-database=ikontrol20_clean --dry-run --json
php spark ikontrol:legacy-upgrade:smartfree --database=<local_copy> --template-database=ikontrol20_clean --execute --yes --json
```

`--database` is mandatory. `--execute` additionally requires `--yes`. Use a restored local dump only. The configured production database, remote hosts, DOLD and the original dump are out of scope.

## Steps

- **B000** reads the fingerprint, dynamic prefix, counts, monetary types, sums and approved orphan counts. It aborts for a profile mismatch.
- **B010** creates `app_schema_versions`, `legacy_bridge_runs`, and `legacy_bridge_steps`.
- **B020–B040** add nullable administrative columns from the canonical local template, gate DOUBLE-to-DECIMAL conversion at `0.0000005`, and map invoice lifecycle conservatively.
- **B050/B080** add missing fiscal, SAT, supplier and warehouse structures without FKs. Fiscal remains disabled and no historical CFDI, RFC, SAT identity, inventory or supplier history is invented.
- **B060/B070** create four technical MXN accounts, map methods 1/6/7/8, create one idempotent incoming movement for each positive active payment, and allocate only against an existing invoice balance.
- **B090–B110** preserve settings/roles, deny platform superadmin, record the constraint decision, and mark `ikontrol-1.0.0` only after the before/after legacy snapshot matches.

## Preserved anomalies

The bridge preserves all orphan invoice items and payments, including deleted and non-positive payments. Orphan payments receive no allocation, client, invoice or invented parent. Non-positive payments receive no financial movement. Payment allocations are capped by each non-negative invoice balance; any payment remainder remains unallocated.

## Checkpoints and recovery

Each step is `pending`, `running`, `completed` or `failed` in `legacy_bridge_steps`. Completed steps are skipped on a repeated `--execute --yes`; this is the only supported resume behavior. Restore the database backup to roll back an execution. There is intentionally no `down()` path.

## Local validation recorded

The certified dump was restored only to `ikontrol_test_smartfree_bridge_20261001`. B000 reported prefix `sf_`, 315 clients, 273 items, 1,301 invoices, 2,296 invoice items, 1,418 payments, 18 orphan payments, 85 orphan items, and preserved monetary snapshots of `7977784.550000`, `7704623.022300`, and `7656309.022300`. A second execute reused run 1 without duplicating its 1,391 legacy movements or 1,371 allocations. No fiscal documents and no platform superadmin were created.

## Future iKontrolAdmin operation

iKontrolAdmin must restore/choose a local backup, invoke dry-run, display blockers and warnings, require an explicit operator confirmation, execute with `--yes`, then archive the JSON result and backup reference. It must never call the bridge against a remote or production connection.
