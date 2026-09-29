# P07 — Flujo de costos de proveedor

**Estado:** implementado y probado con fixtures aislados; pendiente de despliegue individual de P03.

## Flujo canónico

`Suppliers` expone la captura manual sólo a quien tiene `supplier_costs_edit`. El formulario solicita producto, proveedor, costo unitario MXN, fecha opcional y notas opcionales; entrega el payload a `SupplierCostHistoryService::saveManual()`, que delega a `ManualSupplierCostService`. No crea ni modifica Proposal, Estimate, Invoice, venta, cobro ni movimiento financiero.

El costo manual permanece append-only. P03 no agregó un estado de borrado ni contrato de edición: por ello P07 no expone endpoints de editar/eliminar y el servicio continúa rechazando un ID existente. Esta decisión conserva la evidencia, la idempotencia y la separación con snapshots formales. Una futura reversión requerirá una migración y una política de auditoría separadas.

## Historial y comparador

El comparador incorpora filas `manual` y formales mediante cliente opcional, sin modificar historial. Muestra proveedor, último/mejor costo, fecha, origen y referencia; las filas manuales se identifican como manuales. Es información de referencia: no aplica costo a productos ni a partidas comerciales y no altera `price_origin` (`manual`/`cost_margin`).

## Acceso

- Consulta: `suppliers_view` y, para historia/comparador, `supplier_costs_view`.
- Captura manual: `supplier_costs_edit`.
- Las rutas nuevas son POST y tienen filtro CSRF.
- El quick-create existente conserva su endpoint CSRF y retorno al selector padre; P07 no lo duplica ni cierra el modal padre.

## Validación

`C:\xampp\php\php.exe -d extension=sqlite3 tests\SupplierWorkflowCanonical\run.php`: **18 aserciones aprobadas**. Cubre alta manual sin cliente, notas, coexistencia manual/formal, comparador sin escritura, repetición idempotente, conflicto de token, rutas, permisos, CSRF, formulario y política append-only.

Regresiones P03: `ManualSupplierCostsCanonical/run.php` obtuvo 47 aserciones SQLite y 57 MySQL temporal; `views.php`, 8 aserciones. No se ejecutó PAC ni se usaron datos fuente.

## Pendientes

- Desplegar P03 de forma individual y cerrar gates de datos P02 antes de habilitar la UI.
- La política append-only debe revisarse explícitamente antes de introducir edición o eliminación lógica.
- P08 queda fuera de este paquete.
