# Ejecución de la conciliación canónica iKontrol v1.0.0

Fecha de inicio: 2026-09-28. Estado: EN CURSO, versión 1.0.0 todavía no liberada.

## Autoridad y alcance

El único destino y repositorio canónico es `C:/xampp/htdocs/ikontrol-platform`.
`C:/xampp/htdocs/ikontrol2/ikon2.0` es fuente de cambios de solo lectura.
No se crean otro proyecto, repositorio, worktree ni copia de Base.

Guías obligatorias:

- [Auditoría bidireccional](../BASELINE_RECONCILIATION_BASE_VS_NAVIKA.md).
- [Plan de auditoría](../BASELINE_RECONCILIATION_ACTION_PLAN.md).

La autorización actual permite implementar por paquetes en Base y hacer commits separados; sustituye la restricción temporal de «sólo auditoría» de esos documentos. No autoriza copiar configuración/datos del cliente, ejecutar PAC real ni usar las bases existentes como laboratorio destructivo. Las pruebas usan fixtures y conexiones aisladas dentro del mismo proyecto.

## Identidad inicial y preservación

- Rama: `main`.
- HEAD inicial: `c1b689897d1ac15c83b2fb22714b05b5f905b258`.
- No había modificaciones a archivos versionados.
- Trabajo local preservado: `app/Commands/IkontrolBaselineCheck.php`, `app/Services/Baseline/`, `tests/BaselineCheck/`, `docs/architecture/` y ambos documentos de auditoría.
- Checkpoint: `e044301`, `baseline: preserve pre-reconciliation state`.
- No se elimina ni reemplaza el tooling exclusivo de Base.

## Entrega inicial, antes de cambiar funcionalidad

Primer paquete de schema: **P01, selección persistente de plantilla, fundamento aditivo**. Se incorpora únicamente la capacidad estructural nullable de `proposal_template_id`; la UI y el servicio de selección se integrarán después. No se copian plantillas ni se infieren IDs históricos.

Archivos previstos:

- `app/Database/Migrations/2026-09-25-120000_AddProposalTemplateSelection.php`, revisada contra la fuente Navika.
- `tests/ReconciliationSchema/run.php`, pruebas con conexión explícita aislada.
- Este registro y evidencia de reconciliación por migración.

Precondición P00: hacer utilizable la suite Baseline preservada. Su primera ejecución falló antes de las aserciones: las tablas de fixtures no tienen prefijo y la conexión usa `db_`. El manejador de excepciones además enmascara el error con acceso a una propiedad protegida. Se corregirá el aislamiento/fixtures en un commit separado; no se cambiará framework para ocultar el fallo.

Pruebas previstas: Baseline con SQLite en memoria; migración individual con tabla histórica, preservación de contenido, valor NULL, repetición, selección existente y prefijo no vacío. Ninguna prueba puede resolver una conexión de escritura desde `.env` o llamar PAC.

Riesgos: aplicar un runner global ejecutaría migraciones históricas incompatibles o destructivas. Añadir una migración a disco no significa que esté desplegada en la BD local. La capacidad de selección no se declara operativa hasta integrar y probar su servicio/UI. La liberación/tag quedan pendientes de todos los paquetes críticos.

## Hallazgos que bloquean un migrate global

La inspección directa ya confirma:

- `CanonicalizeAdministrativePayments` intenta modificar `payment_allocations.fiscal_document_id` y añadir restricciones sin guardas completas. El contrato posterior usa `invoice_id`; reejecutarla no es una reparación segura.
- `ApplyAdministrativePaymentsToSales` llama `emptyTable()` si aún existe `fiscal_document_id` en allocations.
- `PreparePaymentComplementDraftsFromAllocations` ejecuta `TRUNCATE` de cuatro tablas de complementos y desactiva temporalmente FKs. Sus comentarios sobre datos de prueba históricos NO autorizan borrar datos actuales.

No se ejecutarán esas operaciones contra Base/Navika. Se debe analizar cada efecto DDL/DML y, donde sea necesario, crear reparación canónica nueva, preservando migraciones aplicadas.

## Checklist ejecutable por paquetes

| ID | Paquete / salida requerida | Dependencias | Estado |
| --- | --- | --- | --- |
| P00 | Preservar Base y caracterizar Baseline; fixtures aislados reproducibles | Checkpoint | IMPLEMENTADO; ver bitácora |
| P01 | Schema aditivo de selección de plantilla; pruebas de preservación | P00 | IMPLEMENTADO Y PROBADO; NO DESPLEGADO |
| P02 | Reconciliar individualmente las 19 migraciones sin historial: DDL, DML, estado Base/Navika, riesgo y reparación | Lecturas protegidas | PENDIENTE |
| P03 | ManualSupplierCostHistory: nullability, notas, idempotencia; respetar historial formal | P02 | PENDIENTE |
| P04 | ExternalDocuments: tablas, impuestos DR, FKs, invoice_id nullable | P02 | PENDIENTE |
| P05 | Servicios compartidos: dinero/resolvers, ProposalTotals, estado fiscal y contratos diagnósticos Base | Schema requerido | PENDIENTE |
| P06 | Proposals: placeholders legacy/fiscales, selección, imágenes, UI/preview/PDF, aceptación y rollback de conversión | P01, P05 | PENDIENTE |
| P07 | Suppliers: manuales/formales, comparación, permisos, concurrencia e idempotencia; sin documentos ficticios | P03, P05 | PENDIENTE |
| P08 | Warehouses: preservar Base; probar catálogo, ledger, transferencias, parciales, diferencias y etiquetas | P02 | PENDIENTE |
| P09 | Ambiente fiscal único, separado de CI_ENVIRONMENT; guardas y transporte MOCK sandbox/production | Contrato fiscal | PENDIENTE |
| P10 | Lifecycle/reintentos/unknown/locks/wallet; nunca reintentar con envío posible | P09 | PENDIENTE |
| P11 | Complementos internos/externos/mixtos: snapshot, builder, moneda, saldos e impuestos; sin escrituras administrativas ficticias | P04, P05, P10 | PENDIENTE |
| P12 | I/P/E: serie/folio congelados; PDF/logo/históricos desde documento persistido | P09–P11 | PENDIENTE |
| P13 | Rutas explícitas/catch-all, verbos, CSRF, permisos y regresión transversal | Cada módulo | PENDIENTE |
| P14 | Limpieza EOL/docs/env example/arquitectura en cambio separado; sólo obsolescencia demostrada | Pruebas críticas aprobadas | PENDIENTE |
| P15 | Fuente canónica de versión, docs/releases/1.0.0.md, commit y tag v1.0.0 en este repositorio | Todos los anteriores | BLOQUEADO POR PAQUETES PENDIENTES |

### Protocolo de cierre de cada paquete

- [ ] Reportar alcance, archivos, migraciones, pruebas y riesgo antes de editar.
- [ ] Revisar bloques concretos; conservar cambios Base y excluir contenido de cliente.
- [ ] Verificar dependencias y aislamiento de fixtures.
- [ ] Ejecutar pruebas relacionadas al terminar el paquete; registrar comando, resultado y limitaciones.
- [ ] Revisar diff y comprobar que `.env` y Navika permanecen intactos.
- [ ] Hacer commit separado y registrar referencia por asunto/hash.

## Gates de release

- [ ] Baseline, pagos y complementos internos/externos aprobados.
- [ ] Timbrado fake, producción MOCK, reintentos e incertidumbre aprobados.
- [ ] Notas de crédito, cancelación y wallet sin regresiones.
- [ ] Proposals, templates y PDF con totales consistentes y rollback completo.
- [ ] Suppliers/manuales y warehouses aprobados.
- [ ] Historial y schema reconciliados con evidencia; no existe permiso para migrate global a ciegas.
- [ ] Permisos, CSRF, rutas y fixtures de ambos orígenes validados.
- [ ] Release con pasos de actualización y recuperación, riesgos conocidos y tag sobre este repositorio.

Después de v1.0.0, todo cambio genérico se desarrollará primero aquí. Ninguna instancia cliente se convertirá en fuente principal.

## Bitácora

1. Identidad Git verificada; auditoría/plan leídos.
2. Checkpoint creado preservando íntegramente el trabajo local.
3. Primera prueba Baseline: FALLÓ por prefijo de fixtures; se conserva como evidencia del estado previo.
4. Inicio P00/P01; no se ejecutó migración alguna sobre las bases fuente ni PAC real.
5. P00: conexión SQLite `:memory:` explícita, no compartida y sin credenciales; fixtures completos, limpieza y cache de metadatos coherentes. Se conserva el servicio Base y se corrigen dos defectos reales: consulta a `payment_methods.status` inexistente (se usa `available_on_invoice`) y consulta a settings ausente. No se cambió ningún flujo comercial ni el framework.
6. Validación P00: `php -d extension=sqlite3 tests/BaselineCheck/run.php`. Pruebas de método no disponible y preservación de todos los datos de fixtures añadidas; historial de fixture ahora tiene archivo real y el baseline válido exige PASS sin WARN. Commit del paquete: `reconcile: baseline characterization`.
7. P00 cerrado en `3a90daf`: **13 pruebas aprobadas**. El checkpoint original permanece en `e044301`.
8. P01: migración de selección adaptada con precondición, verificación de schema existente y rollback no destructivo. No altera ninguna migración histórica de Base. [Decisión y validación completas](P01_PROPOSAL_TEMPLATE_SCHEMA.md).
9. P01: **16 pruebas SQLite + 16 pruebas MySQL aprobadas**; schema MySQL temporal propio eliminado. Baseline revalidado: **13 aprobadas**. Lint y diff-check correctos. Commit del paquete: `reconcile: proposal template schema foundation`.
10. Verificación de fuentes posterior: Base sigue con 172 tablas/65 registros, Navika con 174/86; ninguna tiene aún `proposal_template_id`. Navika conserva git status limpio. No se aplicó ni registró la migración en las bases fuente.
11. Próximo paquete: P02, conciliación de las 19 históricas con lecturas DDL/DML y correcciones nuevas cuando proceda. El proyecto tiene 85 archivos de migración; las 19 originales sin registro más P01 no desplegada siguen pendientes de conciliación/despliegue. **No se declara v1.0.0 ni se crea tag antes de superar todos los gates.**
