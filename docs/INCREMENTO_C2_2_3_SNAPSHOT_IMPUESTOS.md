# Incremento C2.2.3 — Snapshot durable de impuestos

## Respaldo y bloqueo

Respaldo: `writable/backups/c2_2_3_tax_snapshot_20260729_212009`.
Dump SQL: 9,149,508 bytes; SHA-256:
`c96799b55d9614a762467301a787282a3238bf4ea3d71ada99e2cf8dbbdede76`.

C2.3 detectó que `fiscal_draft_items.tax` era un importe agregado y que el
JSON no conservaba código SAT, naturaleza, factor, tasa, base e importe. No era
posible reproducir un CFDI sin consultar configuración mutable.

## Origen fiscal auditado

La relación producto-impuesto efectiva es
`item_fiscal_settings → item_fiscal_taxes → taxes`. `taxes` aporta naturaleza
fiscal, tasa/cuota y factor; los catálogos SAT aportan `tax_code` y
`factor_type`. El motor existente reconoce traslados, retenciones, Tasa, Cuota
y Exento, y usa `FiscalDecimal`.

El snapshot nuevo toma esos datos únicamente durante el guardado explícito.
Después, `FiscalDraftSnapshotService` no consulta productos, impuestos ni
catálogos mutables.

## Esquema

Migración: `2026-08-04-150200_CreateFiscalDraftItemTaxes`.

La tabla `fiscal_draft_item_taxes` contiene identificadores del borrador,
concepto, venta y partida; naturaleza, código SAT, factor, tasa/cuota, base,
importe, exento, orden, fuente y fechas. Usa `DECIMAL(18,6)` e índices
individuales y compuesto.

`fiscal_drafts` incorpora:

- `snapshot_version`;
- `requires_snapshot_refresh`;
- `snapshot_completed_at`.

## Snapshot versión 2

Cada concepto guarda, tanto en JSON como relacionalmente:

- objeto de impuesto;
- subtotal, descuento y base;
- traslados y retenciones;
- total;
- lista detallada;
- `snapshot_version=2`.

El guardado recalcula con `FiscalDecimal`, inserta conceptos e impuestos,
reserva asignaciones y confirma todo en una transacción. Una edición elimina
el conjunto anterior únicamente para borradores editables y lo reemplaza sin
mezclar versiones. Cada reemplazo queda auditado.

## Validación y legacy

Un concepto gravable sin impuestos, con factor/código inválido, tasa ausente,
importe inconsistente o versión menor a 2 no puede quedar `ready`.

Los borradores legacy `draft/ready` se marcan para refresco; un `ready` legacy
se degrada a `draft`. Los `stamped` no se modifican. El borrador 1 continúa
`discarded`, versión 1, sin impuestos reconstruidos.

## Prefactura

Mantiene `PREFACTURA — DOCUMENTO SIN VALIDEZ FISCAL` y muestra objeto de
impuesto, naturaleza, código, factor, base, tasa/cuota e importe, además de
totales separados de traslados y retenciones. No genera XML ni llama al PAC.

## Servicio para C2.3

`FiscalDraftSnapshotService::getCompleteFiscalSnapshot()` devuelve borrador,
conceptos, impuestos, totales, snapshots de emisor/receptor/serie y
asignaciones exclusivamente persistidos. Ante datos incompletos lanza
`FISCAL_DRAFT_SNAPSHOT_INCOMPLETE`.

## Pruebas e integridad

- IncrementC223: 49 aprobadas, 0 fallidas.
- Suite completa Increment00–IncrementC223: 20 suites, 0 fallidas.
- La prueba funcional de creación, edición, reemplazo, lectura y liberación se
  realizó sobre una copia aislada; no se creó una venta operativa en la base
  local.
- Llamadas PAC: 0.
- Timbres consumidos: 0.
- XML, PDF y UUID modificados: 0.
- Commit y push: no realizados.

## Pendiente

C2.3 puede retomarse usando exclusivamente
`getCompleteFiscalSnapshot()`. Un nuevo borrador controlado deberá guardarse
con versión 2 antes del preflight de timbrado; ningún borrador legacy podrá
usarse directamente.
