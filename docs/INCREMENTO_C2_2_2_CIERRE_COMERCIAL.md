# Incremento C2.2.2 — Estados y cierre comercial

## Respaldo

`writable/backups/c2_2_2_closure_20260729_200113`

Dump `ikontrol_new.sql`: 9,148,157 bytes; SHA-256
`7B1AF527D581B17C640273E9763D3BDB876E641705902F973CAA73D45E9BD0D3`.
El inventario contiene 515 archivos y cubre configuración, controladores,
modelos, servicios, vistas, rutas, permisos, migraciones y almacenamiento
fiscal privado. No contiene credenciales expuestas.

## Auditoría y compatibilidad

La base efectiva es `ikontrol_new`, prefijo `ikontrol_`.

- `estimates`: 8 registros, todos `accepted` antes de migrar; `status` era un
  `ENUM` legacy. Una relación comprobable: cotización 53 → venta 49.
- `invoices`: 8 registros; un `draft` y siete `not_paid`.
- `invoice_payments`: 0 registros.
- `estimate_items`: 8; `invoice_items`: 8.
- `invoices.status` es estado de cobro y se conserva.
- `estimates.status` sí representa el ciclo de la cotización.

No se infirieron conversiones por cliente, fecha o total. Sólo se utilizó
`invoices.estimate_id`.

## Migraciones

- `2026-08-04-150000_CreateCommercialLifecycle`
- `2026-08-04-150100_EnsureCommercialStatusCompatibility`

Se agregaron a ventas `commercial_status`, `closed_at`, `closed_by` y
`closure_reason`. Se agregaron a cotizaciones los campos de conversión y
cancelación. `commercial_lifecycle_audit` conserva los cambios.

La migración correctiva fue necesaria porque el `ENUM` legacy convertía los
nuevos estados en cadena vacía. Se amplió a `VARCHAR(20)` antes de normalizar.
La evidencia previa permitió reparar los valores vacíos: la cotización con
relación explícita quedó `converted`; las demás conservaron `accepted`.

Histórico de ventas:

- `draft` comercial cuando el estado de cobro era `draft`;
- `cancelled` cuando ya estaba cancelada;
- las demás quedaron `open`; no se inventaron cierres.

## Estados

Cotizaciones: `draft`, `sent`, `accepted`, `rejected`, `expired`, `converted`,
`cancelled`.

Ventas: `draft`, `open`, `closed`, `cancelled`.

Pago y fiscalidad siguen siendo proyecciones independientes. Una venta
`closed` mantiene saldo fiscal disponible y puede crear borradores; una
`cancelled` queda bloqueada.

## Servicios y reglas

`QuotationLifecycleService` centraliza edición, envío, aceptación, rechazo,
expiración, conversión y cancelación. La conversión bloquea la cotización,
trabaja en transacción, copia las partidas mediante el servicio existente,
registra la venta y evita duplicados.

`SaleLifecycleService` centraliza edición, cierre y cancelación. Para cerrar
valida estado, cliente, partidas, total y pago. Una venta de contado exige pago
completo; una venta a crédito puede cerrar con saldo. Toda aritmética monetaria
usa `FiscalDecimal`.

Una venta cerrada no admite edición estructural. La cancelación reutiliza
`FiscalSaleCancellationPolicy`, por lo que facturas vigentes y borradores
activos siguen bloqueándola. Nunca se elimina físicamente una venta o
cotización.

## Interfaz, seguridad y permisos

El detalle de venta muestra estado comercial, estado de pago y datos de
cierre. El cierre es POST con CSRF. El detalle de cotización traduce los siete
estados y enlaza la venta convertida.

Permisos incorporados: `quotations.*`, `sales.view`, `sales.create`,
`sales.edit`, `sales.close`, `sales.cancel`, `sales.edit_open` y
`sales.override_close_validation`. `sales.reopen` se reserva y no se persiste
ni se muestra como acción.

Las validaciones permanecen en servicios y los controladores mantienen control
de acceso por entidad. Los eventos auditados incluyen conversión, cierre y
cancelación, con usuario, estados, motivo y fecha.

## Reportes

No se alteraron consultas históricas de importes en este incremento. El nuevo
estado comercial permite excluir `draft/cancelled` en una revisión posterior
sin mezclarlo con el estado de cobro. No se modificaron cifras históricas.

## Pruebas

`tests/IncrementC222/run.php`: 39 aprobadas, 0 fallidas.

Suite completa Increment00–IncrementC222: 19 suites aprobadas, 0 fallidas.
Conversión, cierre, pagos y cancelaciones de prueba se ejecutaron en copias
aisladas de la base. La ruta y las vistas se validaron sin invocar PAC.

## Integridad

- Llamadas PAC: 0.
- Timbres consumidos: 0.
- XML modificados: 0.
- PDF modificados: 0.
- UUID modificados: 0.
- Cotizaciones, ventas, pagos y borradores eliminados: 0.
- Commit: no realizado.
- Push: no realizado.

## Pendiente para C2.3

No se implementó reapertura. Cualquier reapertura futura deberá validar
facturas, cortes y movimientos irreversibles, exigir motivo y dejar auditoría.
