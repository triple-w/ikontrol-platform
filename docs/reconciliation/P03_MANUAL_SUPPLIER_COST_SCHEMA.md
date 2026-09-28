# P03 — Costos manuales de proveedor

Fecha: 2026-09-28. Estado: **backend implementado y probado; migración no desplegada en Base ni Navika**. P04 no iniciado. Commit del paquete: `reconcile: manual supplier cost schema`.

## Alcance y fuentes

Puntos de entrada revisados: Suppliers y SupplierCostHistoryService, sus consumidores directos de historial y comparación, y la migración/servicio de costos manuales de Navika como fuente de lectura. No se copió su controlador, UI de captura, configuración ni datos. La autorización actual permite implementar P03 aislado; no declara resueltas las revisiones de datos pendientes de P02.

Las únicas entradas de [P02](P02_MIGRATION_LEDGER.md) utilizadas como contrato de proveedores son:

| Migración histórica | Decisión P03 |
| --- | --- |
| CreateSupplierCostHistory | Conservar tablas, referencias a producto/proveedor/cliente y snapshots formales; admitir cliente NULL sólo como ampliación del esquema. |
| AddSupplierIdentity | No renormalizar proveedores ni cambiar RFC/identidad; la validación de datos de P02 permanece pendiente. |
| BackfillFormalSupplierCostHistory | No ejecutar ni reconstruir economía histórica. |
| IndexSupplierCostComparison | Preservar índice de comparación. |
| CreateProposalItemSupplierQuotes | Sin cambios ni creación de cotizaciones ficticias. |
| UnifyCommercialItemPricingAndSupplierHistory | Exigir contrato genérico ya presente; no reescribir source_type/id/item/folio ni cambiar hashes formales. |
| EnsureGenericSupplierCostHistorySource | No replay del backfill. Las filas heredadas con origen incompleto se conservan y se prueban expresamente. |

## Archivos del paquete

- [Migración nueva](../../app/Database/Migrations/2026-09-28-130000_EnableCanonicalManualSupplierCostHistory.php).
- [SupplierCostHistoryService](../../app/Services/SupplierCostHistoryService.php): entrada manual separada y lectura de historial sin excluir cliente NULL.
- [ManualSupplierCostService](../../app/Services/ManualSupplierCostService.php): validación y alta idempotente con conexión inyectada.
- [Detalle de producto](../../app/Views/items/view.php) y [detalle de proveedor](../../app/Views/suppliers/view.php): adaptación mínima de lectura para NULL y origen manual; sin formularios ni nuevas acciones.
- [Pruebas de servicio/esquema/concurrencia](../../tests/ManualSupplierCostsCanonical/run.php) y [render de vistas](../../tests/ManualSupplierCostsCanonical/views.php).
- Este documento y la entrada P03 del [registro de ejecución](BASE_V1_RECONCILIATION_EXECUTION.md).

## Migración canónica

Se adapta la capacidad de `EnableManualSupplierCostHistory` de Navika mediante una **nueva** migración `2026-09-28-130000_EnableCanonicalManualSupplierCostHistory`, sin alterar ninguna histórica ni importar el timestamp/ledger Navika.

| Cambio en product_supplier_cost_history | Compatibilidad |
| --- | --- |
| client_id INT NULL DEFAULT NULL | Preserva FK a clients y todos los valores existentes. |
| sale_unit_price y quantity DECIMAL(18,6) NULL DEFAULT NULL | No rellena ceros, no redondea ni cambia valores formales. |
| quoted_at DATETIME NULL DEFAULT NULL | Extensión respecto a Navika: fecha comercial opcional; created_at registra captura, no se inventa fecha de cotización. |
| notes TEXT NULL, updated_at DATETIME NULL | Columnas aditivas; sin backfill de registros anteriores. |
| idempotency_key CHAR(64) NULL | Historia formal anterior conserva NULL; no se deriva una clave para sus filas. |
| uq_cost_history_manual_idempotency(idempotency_key) UNIQUE | Identidad de solicitudes manuales; permanece independiente del unique económico formal. |

Preflight anterior al primer DDL: tabla existente, orígenes genéricos nullable, unique económico formal exacto, tipos/defaults esperados, compatibilidad de campos/índice manuales si ya existen y ausencia de claves manuales duplicadas. Ante incompatibilidad aborta, no repara datos silenciosamente. Conserva índices/FK existentes. No contiene UPDATE, DELETE, backfill ni llamada a servicios de negocio.

`up()` se puede repetir sobre el esquema compatible; `down()` conserva datos y columnas. DDL MySQL puede confirmar parcialmente ante fallo operativo: revalidar antes de reintentar. La migración acepta MySQLi; SQLite sólo se usa para probar el contrato del servicio, no para simular fidelidad de ALTER/FK de MariaDB.

No se ha aplicado sobre la Base real. Su despliegue debe ser individual, con destino explícito, guardia de historial y bloqueo, no `spark migrate` global. La presencia de esta migración no autoriza ejecutar las 19 históricas. Las revisiones P02 de identidad, procedencia y cobertura de datos siguen siendo gates del despliegue/activación, aunque esta ampliación no modifica esas filas.

## Contrato del backend

`SupplierCostHistoryService::saveManual(array $data, int $user, ?int $id = null)` delega en el servicio manual usando la misma conexión. Devuelve `['id' => int, 'created' => bool]`. `manual(id)` sólo devuelve registros `source_type=manual`; no transforma snapshots formales.

- Requiere producto existente, proveedor activo no eliminado, actor positivo, costo positivo y token válido. El caller futuro debe autenticar y autorizar al actor; el servicio no sustituye permisos HTTP.
- Tokens de 1–128 caracteres alfanuméricos, guion o guion bajo. Se conserva el namespace Navika: SHA-256 de `manual|actor|token`. No se sanitizan tokens distintos para convertirlos en la misma identidad.
- Misma identidad y payload normalizado: devuelve el registro existente sin cambiarlo. Misma identidad con costo, fecha, notas u otros datos distintos: error. Un nuevo token representa un nuevo evento, incluso con costo idéntico.
- El índice único arbitra concurrencia: MySQL usa INSERT con conflicto sin modificación de payload; SQLite usa ON CONFLICT DO NOTHING. La lectura posterior MySQL es actual (`FOR UPDATE`) para ver al ganador incluso dentro de una transacción REPEATABLE READ del caller. No se captura una violación de unique para continuar con una transacción CI marcada como fallida. Se exige `foundRows=false` (default del driver) para distinguir creación de conflicto sin cambio; una conexión incompatible se rechaza antes de escribir.
- Sólo escribe product_supplier_cost_history. No crea Proposal, Estimate, Invoice, aplicaciones, cobros ni movimientos financieros. No inicia ni confirma una transacción ajena.
- `source_type/source_status=manual`; cliente, IDs de origen comercial y vínculos Proposal quedan NULL. No admite inyectar esos vínculos desde el payload. source_folio opcional es una referencia descriptiva libre, no un documento creado.
- Precio de venta y cantidad son opcionales: NULL/omitido/vacío permanece NULL. Un precio explícito cero es válido y se distingue de ausencia; cantidad informada debe ser positiva.
- Importes se reciben como cadena decimal o entero, hasta 12 dígitos enteros y seis decimales, sin floats/notación exponencial. Fecha opcional estricta YYYY-MM-DD o YYYY-MM-DD HH:MM:SS UTC; fechas inválidas no se normalizan silenciosamente.
- En P03 se acepta **MXN**. Los agregadores actuales no separan monedas; admitir otras produciría comparaciones engañosas. La ampliación multimoneda queda condicionada al contrato de comparación futuro.
- No se implementa edición ni borrado manual. Pasar un ID a saveManual se rechaza; no hay nuevos endpoints.

Los métodos snapshotProposal/Estimate/Invoice y sus hashes/criterios permanecen intactos. Se probaron tanto repetición como generación de una nueva versión formal después de insertar manuales. Las filas heredadas con origen genérico incompleto no se reclasifican. `historyBuilder()` cambia el join de cliente a LEFT y rotula el origen manual; las vistas evitan enlazar a Proposal 0 o convertir precio/fecha NULL a un valor ficticio.

## Pruebas ejecutadas

Desde la raíz con PHP XAMPP:

```powershell
C:/xampp/php/php.exe -d extension=sqlite3 tests/ManualSupplierCostsCanonical/run.php
C:/xampp/php/php.exe tests/ManualSupplierCostsCanonical/run.php --mysql
C:/xampp/php/php.exe tests/ManualSupplierCostsCanonical/views.php
```

| Validación | Resultado |
| --- | --- |
| Servicio con SQLite en memoria, conexión explícita no compartida | 47 aprobadas, 0 fallos |
| Esquema/servicio en MariaDB temporal con prefijo p03_ | 57 aprobadas, 0 fallos |
| Render de las dos vistas reales con helpers puros, sin bootstrap/BD | 8 aserciones aprobadas |
| Lint PHP de archivos del paquete y diff-check | Correctos |

Cobertura: snapshots formales de los tres tipos; una fila heredada incompleta; manual sin cliente/precio/cantidad/fecha; producto/proveedor/notas; fecha explícita; repetición y conflicto de token; aislamiento por actor; importes/fecha/identificadores inválidos; coexistencia y nuevas versiones formales; preservación exacta del historial previo y de tablas comerciales/financieras; repetición y rollback no destructivo de la migración; FK preservadas; rechazo de índice no único y de claves duplicadas antes del DDL; rechazo del backend sin schema desplegado; NULL y escape de texto en vistas.

La prueba MySQL crea un schema aleatorio `ikontrol_test_p03_*` con fixtures sintéticos. Se conecta al servidor local sin seleccionar la BD fuente para crearlo; no clona tablas ni filas Base/Navika. Dos procesos con conexiones independientes prueban el mismo token dentro de transacciones REPEATABLE READ: mismo ID, una sola creación, una sola fila y transacciones utilizables. El schema propio se elimina en finally; eliminación confirmada en la ejecución. Las credenciales sólo se usan en memoria para el servidor local/worker, no se imprimen ni versionan.

PAC se deshabilita antes del bootstrap; no hay llamadas fiscales. No se ejecutaron runners heredados que copian datos comerciales.

## Pendientes y riesgos para P07

1. Despliegue individual de esta migración y cierre de gates de datos P02; no está operativa en la BD real por haber pasado fixtures.
2. Controller/formulario y rutas con verbo, CSRF y permisos supplier_costs_edit/view. Ningún endpoint nuevo se expone en P03.
3. Diseño acordado de edición/borrado y su efecto sobre auditoría e idempotencia; no importar sin revisión el hard-delete de Navika.
4. SupplierComparisonService sigue limitado a historial formal. Los listados/resúmenes de SupplierCostHistoryService incluyen manuales; incorporar manuales al comparador y referencias Proposal/Estimate/Invoice requiere reconciliar NULL y procedencia en P07. No declarar esa integración terminada.
5. Mantener comparaciones en MXN hasta definir separación/conversión explícita por moneda. Una fecha omitida no equivale a «última cotización hoy».
6. Las pruebas no resuelven datos históricos desconocidos ni todas las políticas de permisos. La migración aborta ante incompatibilidades; cualquier reparación adicional exige evidencia y un paquete explícito.
