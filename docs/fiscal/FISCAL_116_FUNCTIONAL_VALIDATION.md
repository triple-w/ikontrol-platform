# iKontrol Platform 1.1.6 — validación funcional integrada

Fecha de corte: 2026-10-30
Estado: **NO LISTO para producción**

## Alcance y garantías

Esta validación cubre únicamente las interacciones necesarias entre las entregas fiscales A, B y C y los flujos comerciales que pueden afectarlas. No sustituye la validación de una instancia ni autoriza una publicación.

- No se contactó Golden, Smartfree, Navika/DOLD, SAT ni PAC.
- Los runners aceptados usan dobles, SQLite en memoria cuando está disponible, o esquemas MySQL locales aleatorios que crean y eliminan ellos mismos.
- No se ejecutó `spark migrate`, no se restauró ni copió una base externa y no se modificaron credenciales.
- No se ejecutaron los runners que usan `tests/Increment02/isolated_database.php`: copian filas de la base configurada y no demuestran aislamiento.
- Durante la clasificación inicial se ejecutaron una vez `StructuralMoneyFiscal` e `IncrementC241`. Ambos alcanzan `db_connect()` sobre la base local configurada; sólo realizaron lecturas, no escribieron. Quedan clasificados como **BLOCKED** y no deben volver a ejecutarse hasta aislarlos.

## Matriz de validación

`PASS` significa evidencia automatizada sintética del contrato indicado; no equivale a aprobación de una instancia.

| Área | Estado | Evidencia actual | Falta para aceptación funcional |
| --- | --- | --- | --- |
| 1. Alta/modificación de clientes y productos | NOT_TESTED | Readiness de producto, formulario/listado/preflight y catálogos pasan en `FiscalPreflight116`, `PlatformDefectsCanonical` y `SatCatalogInfrastructure` sólo parcialmente | Prueba HTTP/BD aislada de alta y edición de cliente y producto, persistencia de todos los campos fiscales y compatibilidad con registros 1.1.5 |
| 2. Ventas, facturas y borradores | PASS | Lifecycle, bloqueo de edición timbrada, reintento de borrador y conversión de propuesta pasan con fixtures | Recorrido de navegador/controlador: crear, editar, cerrar y reabrir vistas; no reutilizar runners que copian base origen |
| 3. Impuestos y ObjetoImp | PASS | ObjetoImp, traslados, retenciones, exentos, redondeos y snapshots pasan en suites A, lifecycle, complementos y PDF | Caso funcional completo con captura UI y comparación XML/PDF |
| 4. Series fiscales y folios | PASS | Ambiente/emisor, rechazo cruzado, locks, rollback y folios independientes pasan en `FiscalEnvironment116` y `FiscalSeriesPdfCanonical` | Ensayo de concurrencia mediante dos procesos reales sobre el laboratorio; validar series legacy de cada instancia en modo read-only |
| 5. Preparación fiscal y timbrado CFDI 4.0 | PASS | Preflight unificado, runtime, wallet, frontera de timbrado y adaptadores fake pasan | Ensayo funcional con paquete instalado y PAC fake; PAC real queda prohibido |
| 6. XML y PDF | PASS | Identidad congelada, XML timbrado sintético, PDF, hashes, idempotencia e invariancia histórica pasan | Comparación read-only de muestras anonimizadas de 1.1.5; no regenerar documentos productivos |
| 7. Consulta de estado SAT | PASS | Estados, idempotencia, lock, auditoría, artifacts y permisos pasan en `FiscalSatStatus116` con fake | Prueba HTTP completa de botón/listado/detalle y cooldown; confirmar contractualmente costo PAC |
| 8. Cancelación fiscal y estados | PASS | `Fiscal116FunctionalDefects`, `FiscalSatStatus116` e `IncrementC242` prueban aceptación, rechazo, pendiente, aceptación pendiente, timeout y `unknown` sin sustituir el estado autoritativo | Prueba HTTP completa sobre laboratorio y confirmación contractual del costo PAC |
| 9. Cancelación administrativa, CFDI, inventario y CxC | PASS | Una transacción y lock cubren ambos estados y auditoría; pago activo bloquea antes de escribir; rollback, idempotencia y contención concurrente pasan | Validación HTTP/controlador en laboratorio. El flujo existente no contiene contrato venta→almacén, por lo que no inventa reversión de inventario |
| 10. Notas de crédito y complementos de pago | BLOCKED | Complementos canónicos y partes de nota de crédito pasan en fixtures actuales | Las suites históricas de notas de crédito/cancelación de complemento usan el copiador de base; crear equivalentes con fixtures propios antes de liberar |
| 11. Reportes de ventas, facturación y cancelaciones | PASS | Fixture autocontenido reconcilia estados, cantidad, descuentos, impuestos, total, aplicaciones y saldo; excluye draft/cancelled/credited del universo emitido | Validación visual de reportes y filtros sobre laboratorio representativo |
| 12. Acceso, permisos, configuración y separación | PASS | CSRF/permisos fiscales, runtime por ambiente, emisor/wallet/RFC/compañía y feature flag pasan | Prueba funcional de roles completos y evaluación read-only independiente por cada instancia; `update_invoice_status` necesita prueba explícita de método, CSRF y permiso de cancelación |

## Defectos corregidos

### F-01 — cancelación administrativa no atómica

- Archivo/método: `app/Services/Sales/SaleLifecycleService.php::cancel()`.
- Corrección: `SaleLifecycleService::cancel()` adquiere el lock y posee la única transacción. `FiscalSaleCancellationPolicy::cancelWithinTransaction()` valida y escribe sin confirmar una transacción independiente.
- Prueba: fallo intencional en auditoría revierte ambos estados y la auditoría; repetición idempotente no duplica evidencia; una segunda conexión no atraviesa el row lock.

### F-02 — cancelación administrativa ignora aplicaciones de pago activas

- Archivos/métodos: `FiscalSaleCancellationPolicy::assertCanCancel()`, `SaleLifecycleService::cancel()` y `PaymentAllocationService::paymentsForSale()`.
- Corrección: `assertCanCancel()` cuenta únicamente aplicaciones `active/deleted=0` cuyo pago también está `active/deleted=0`, y bloquea con `SALE_HAS_ACTIVE_PAYMENT_ALLOCATIONS` antes de cambiar estados.
- Prueba: pago parcial activo y total efectivo bloquean; aplicación inactiva/eliminada o pago cancelado no bloquean. Ningún pago histórico es modificado o revertido.
- Inventario: `SaleLifecycleService` y la política no tienen relación con `WarehouseMovementService`/`warehouse_movements`; no se realiza ni se inventa una reversión.

### F-03 — un resultado fiscal desconocido se convierte en pendiente

- Archivo/método: `app/Services/Fiscal/Cancellation/FiscalCancellationService.php::persistResult()`.
- Corrección: sólo `accepted`, `pending`, `pending_acceptance`, `rejected` y `transport_not_sent` producen una transición confirmada. `unknown` o un estado nuevo del proveedor conserva `fiscal_documents.status`, normaliza la solicitud a `unknown` y exige conciliación.
- Prueba: evidencia durable con SHA-256, timeout, estado desconocido y valor no reconocido conservan el estado previo; aceptación, rechazo y pendientes mantienen sus transiciones explícitas.

### F-04 — reportes excluyen estados canónicos `partially_paid` y `paid`

- Archivo/métodos: `app/Models/Invoices_model.php::get_details()`, `invoice_statistics()`, `get_invoices_summary()` y consultas de resumen alrededor de `fully_paid`/`partially_paid`.
- Corrección: filtros y agregados reconocen `not_paid`, `partially_paid` y `paid`, conservando compatibilidad calculada para filas legacy. Borradores, cancelados y acreditados mantienen su semántica separada.
- Corrección adicional: el total cobrado del resumen suma aplicaciones activas de las facturas incluidas, no el monto completo de pagos no aplicados.
- Prueba: tres facturas emitidas reconcilian conteo, total, descuento, impuestos, cobrado y saldo; draft/cancelled/credited quedan fuera del agregado emitido.

## Fallos de pruebas que no prueban un defecto funcional actual

- `IncrementC242`: expectativas actualizadas al contrato vigente (`unknown + requires_reconciliation`, saldo `usable` y separación del costo contractual). Resultado actual: **19 PASS / 0 FAIL**.
- `StructuralMoneyFiscal`: 11 PASS / 1 FAIL y **BLOCKED**. Su fixture “UI-shaped product” omite `item_type`, hoy obligatorio; además consulta la base configurada. El contrato vigente sí pasa en `FiscalPreflight116`, pero se requiere una prueba HTTP aislada del formulario real.
- `IncrementC241`: **BLOCKED**. Contiene una aserción textual anterior sobre estados de cancelación y después consulta la base configurada; encontró ausente la tabla 1.1.6 local. No es evidencia de una instalación dirigida.
- `SatCatalogInfrastructure`: **15 PASS / 0 FAIL** con `php -d extension=sqlite3`. El PHP CLI base no carga SQLite automáticamente; el runner ahora termina con exit code 2 y estado `BLOCKED` cuando falta la extensión, en vez de aparentar éxito.

## Resultado automatizado

### Suites aisladas aprobadas

Total aceptado en este corte: **434 PASS, 0 FAIL** en 24 suites aisladas.

| Suite | Resultado |
| --- | ---: |
| FiscalPreflight116 | 19 PASS |
| FiscalEnvironment116 | 24 PASS |
| FiscalSatStatus116 | 23 PASS |
| FiscalPreparedDocumentLifecycle | 11 PASS |
| FiscalStampingBoundary | 6 PASS |
| FiscalStampingLifecycle | 26 PASS |
| FiscalPdfRegeneration | 15 PASS |
| SharedDomainServices | 16 PASS |
| P13HttpSurface | 24 PASS |
| Fiscal116InstanceCompatibility | 13 PASS |
| Fiscal116ReleaseMetadata | 11 PASS |
| PlatformDefectsCanonical | 20 PASS |
| CanonicalStampBalanceAndSaleStatus | 19 PASS |
| SmartfreeLegacyPolicies | 18 PASS |
| FiscalOnboardingUx | 11 PASS |
| FiscalIssuerCsdReadiness | 14 PASS |
| FiscalStampBalanceCanonical | 17 PASS |
| PaymentComplementsCanonical | 28 PASS |
| FiscalSeriesPdfCanonical | 35 PASS |
| ProposalFiscalConversion | 9 PASS |
| Fiscal116DirectedUpgrade | 15 PASS |
| Fiscal116FunctionalDefects | 26 PASS |
| IncrementC242 | 19 PASS |
| SatCatalogInfrastructure | 15 PASS |

Comando reproducible para cada suite aceptada:

```powershell
php tests\<Suite>\run.php
if ($LASTEXITCODE -ne 0) { throw "Falló <Suite>" }
```

Para las suites SQLite:

```powershell
php -d extension=sqlite3 tests\<Suite>\run.php
if ($LASTEXITCODE -ne 0) { throw "Falló <Suite>" }
```

Antes de ejecutarlo se debe comprobar en el propio `run.php`: adaptador fake/flags PAC deshabilitados, host exclusivamente `localhost`/`127.0.0.1`/`::1`, nombre aleatorio propiedad del runner y cleanup restringido. Las suites en memoria no deben abrir `db_connect()` sin grupo explícito.

### Suites no ejecutadas o no aceptadas

- **BLOCKED por copiador de base:** `InvoiceItemSaveRegression`, `CommercialFlowEnhancements`, `CommercialFiscalFlowRegression`, `CreditNotes`, `CreditNoteEditing`, `CreditNoteFiscalSquare`, `CreditNoteIssueDate`, `FiscalSaleConsistency`, `FiscalStampCommercialControl`, `PaymentComplementCancellation`, `PaymentComplementsFiscalUx`, `PaymentComplementStamping` y cualquier otra que incluya `tests/Increment02/isolated_database.php`.
- **BLOCKED por conexión implícita:** `IncrementC241`, `StructuralMoneyFiscal`.
- **NOT_TESTED:** navegación funcional completa de clientes/productos, validación visual de reportes, flujo HTTP de cancelación administrativa, roles completos y concurrencia multiproceso de folios.

## Pruebas adicionales críticas

1. Fixture HTTP/BD autocontenido para cliente y producto: alta, edición, descripción, unidad, tipo, ObjetoImp, traslados/retenciones y paridad formulario/listado/preflight.
2. Recorrido HTTP de cancelación administrativa: permiso, CSRF, mensaje por pago aplicado y refresco de UI. El contrato transaccional y de pagos ya está cubierto a nivel de servicio.
3. Validar en laboratorio que la instalación no tenga una personalización venta→almacén fuera de Base; Base no contiene dicha relación en el flujo de cancelación.
4. Cancelación fiscal end-to-end desde controlador con fake; los estados y persistencia ya están cubiertos a nivel de servicio.
5. Validación visual de reportes; la reconciliación de datos canónicos ya cuenta con fixture autocontenido.
6. Notas de crédito y cancelación de complementos con esquemas propiedad del runner, sin copiar base origen.
7. Prueba funcional de rutas: método HTTP, CSRF, permiso específico, doble clic e intento entre compañías.
8. Concurrencia real de folios, consulta SAT e idempotencia usando procesos separados y adaptador fake.

## Laboratorio Golden 1.1.5 → 1.1.6

1. Ejecutar el inspector externo read-only contra Golden antes de copiar nada. Exigir origen 1.1.5 oficial, hashes, esquema, personalizaciones y resultado distinto de `NOT_VERIFIED`.
2. Tomar en una misma ventana un respaldo consistente de código, BD y artifacts; registrar hashes, hora y punto de recuperación. No incluir secretos en reportes.
3. Restaurar copias en una red aislada sin salida a PAC/SAT. Anonimizar datos en una copia intermedia; el destino de pruebas nunca será producción.
4. Configurar credenciales exclusivas del laboratorio, flags fiscales deshabilitados y adaptador fake. Verificar que DNS/firewall impidan endpoints PAC aunque el código falle.
5. Confirmar baseline 1.1.5 y fingerprint. El catálogo vacío de estados de proyectos de Golden se registra como fallo funcional conocido, pero no se “corrige” con datos ficticios ni bloquea por sí solo el upgrade fiscal si el contrato técnico no lo requiere.
6. Clonar el laboratorio en dos copias: control 1.1.5 y candidato. Aplicar al candidato únicamente el paquete dirigido 1.1.5→1.1.6; nunca `spark migrate` global.
7. Verificar que las dos migraciones aditivas creen tablas/columnas/índices con prefijo y que `app_schema_versions=1.1.6` se escriba sólo después del post-check.
8. Ejecutar las suites aceptadas y la matriz funcional con datos sintéticos nuevos. Los documentos restaurados son sólo para invariantes read-only: UUID/XML/PDF/hash, series/folios, pagos, créditos y wallets no cambian.
9. Inyectar un fallo antes del post-check de esquema. Confirmar que no se registra 1.1.6 y que la evidencia permite recuperar.
10. Restaurar código, BD y artifacts del mismo punto; comparar hashes/fingerprint con el control. Una recuperación parcial es FAIL.
11. Repetir upgrade limpio y emitir reporte `PASS/FAIL/BLOCKED` por condición. El baseline de proyectos queda separado de la aprobación fiscal y de la aprobación funcional general de Golden.

## Criterios para extender a Smartfree y Navika

### Comunes

- Inspector read-only `COMPATIBLE` o `REVIEW_REQUIRED` resuelto; nunca continuar con `BLOCKED`/`NOT_VERIFIED`.
- Hashes comparados contra 1.1.5, fingerprint de esquema, personalizaciones clasificadas y respaldo consistente probado en laboratorio.
- Ensayo dirigido completo sobre copia anonimizada; cero escrituras a documentos históricos y rollback integral verificado.
- F-01 a F-04 corregidas y cubiertas; runners críticos aislados; matriz sin FAIL y sin BLOCKED en rutas usadas por la instancia.

### Smartfree

- Reproducir con fixtures anonimizados el caso técnico 1410 y demostrar paridad producto/preflight.
- Verificar mappings administrativos↔SAT, PUE/PPD, series legacy/development/production, emisor y wallet efectivos.
- Mostrar por separado saldo comercial y créditos PAC, y distinguir PAC configurado de PAC operativo sin invocarlo.

### Navika/DOLD

- Cualquier archivo fiscal distinto del origen oficial queda `REVIEW_REQUIRED`; si no se comprende su finalidad, bloquea el reemplazo.
- Aprobar regresión de XML/PDF, timbrado fake, complementos, notas de crédito, cancelaciones, series/folios y documentos históricos congelados.
- Verificar emisor/wallet/ambiente sin cambiar ninguno. No trasladar configuraciones de Smartfree.
- Ejecutar primero sobre copia representativa anonimizada y exigir recuperación conjunta demostrada antes de considerar una ventana productiva.

## Correcciones mínimas antes de generar release

1. Sustituir o aislar las suites críticas que copian/leen la base configurada.
2. Completar pruebas funcionales HTTP de clientes/productos, notas de crédito, cancelación de complementos, reportes y cancelación administrativa.
3. Obtener inspección read-only y ensayo de upgrade por instancia; ninguna igualdad de versión sustituye hashes y esquema.
4. Mantener como bloqueos contractuales el costo de consulta SAT general y el selector de ambiente preview-only.

Hasta completar esta lista, 1.1.6 no debe etiquetarse, publicarse ni desplegarse.
