# Plan ejecutable de conciliación Base / Navika

Fecha: 2026-09-28. Estado: **AUDITORÍA FINALIZADA; INTEGRACIÓN NO INICIADA**. Este plan describe trabajo futuro; no ejecuta ni autoriza cambios de código, copias entre proyectos, migraciones, timbrado o merge automático.

Evidencia y matriz: [BASELINE_RECONCILIATION_BASE_VS_NAVIKA.md](BASELINE_RECONCILIATION_BASE_VS_NAVIKA.md).

## Punto de partida

- Base: C:/xampp/htdocs/ikontrol-platform, main, HEAD c1b689897d1ac15c83b2fb22714b05b5f905b258. Cambios locales preexistentes en baseline, tests y docs/architecture: preservarlos.
- Navika/DOLD: C:/xampp/htdocs/ikontrol2/ikon2.0, main, HEAD 4daa4164f8f89c6801aa580fb6b59ee033b05839; limpio al inicio.
- 19 archivos sólo Base, 54 sólo Navika, 40 cambios de contenido en rutas comunes y 2,006 diferencias sólo EOL. No reemplazar un árbol por el otro.
- Base DB: ikontrol20_ik_ikontrol, 172 tablas, 65 registros/84 archivos de migración. Navika DB: ikontrol20_dold_preview, 174 tablas, 86 registros/87 archivos; 48 registros clean_build.
- Falta proposals.proposal_template_id en ambas bases. Navika la exige. Dos tablas externas sólo Navika. Hay diferencias de nullability, unique, FK y collation detalladas en la auditoría.

## Reglas de ejecución futura

- Registrar para cada ID F/M y diferencia schema: decisión A–G, responsable, justificación, dependencias y pruebas de aceptación. G bloquea la integración de ese paquete; no elegir por fecha.
- Todo ensayo de código/DDL/DML usa repositorio y bases aisladas, con identidad de destino comprobada y PAC real deshabilitado. No reutilizar las bases fuente como laboratorio.
- Preservar historial fiscal: UUID, XML firmado, folios, pagos, allocations, timbres y estados inciertos. Una restauración de código no deshace un timbrado o pago real.
- Separar migraciones de estructura y backfills; no renumerar ni editar retroactivamente una aplicada para igualar ambos árboles. No marcar como aplicada por mera existencia de una tabla.
- Incorporar pruebas en cada fase. No ejecutar suites desconocidas contra .env actual: algunas crean/escriben bases o artefactos, y otras manuales llaman PAC.

## Fase 0 — Preservación y decisiones (responsable: mantenimiento + producto)

- [ ] Volver a leer HEAD/status y hashes de archivos objetivo; actualizar matriz si cambió alguna fuente desde la auditoría.
- [ ] Preservar en una revisión separada el trabajo no versionado Base: IkontrolBaselineCheck.php, Services/Baseline, tests/BaselineCheck y docs/architecture. No sobrescribir ni limpiar.
- [ ] Establecer política CRLF/LF coherente con .gitattributes de Navika; aislar normalización de cambios funcionales.
- [ ] Aprobar objetivo del contrato fiscal: diferencia entre runtimeMode y environment, habilitación productiva y guardas obligatorias. No adoptar la expectativa productiva del test Navika sin esta decisión.
- [ ] Definir si costos manuales permiten eliminación física o requieren historial/auditoría; confirmar política de edición y moneda.
- [ ] Distinguir contenidos específicos del cliente (marca, plantillas de empresa, propuesta 5 citada en docs, llaves/configuración/metadata) del código reusable. No trasladar valores del .env ni metadata de instalación.

Salida: catálogo A–G por diferencia, fuentes preservadas y contratos económicos/fiscales acordados. Bloqueo: decisión crítica sin responsable o prueba.

## Fase 1 — Caracterización previa (responsable: QA + backend)

- [ ] Preparar entornos aislados que representen ambos esquemas, sin credenciales PAC productivas.
- [ ] Revisar bootstrap de cada suite, selección de base, creación/borrado de fixtures y servicios externos antes de ejecutarla.
- [ ] Registrar comportamiento actual de pagos, aceptación/conversión, redondeos, histórico de costos y timbrado fake por separado para Base/Navika.
- [ ] Capturar invariantes: saldos administrativos, vínculo pago→aplicaciones, series/folios, consumo/reserva/liberación de wallet, hashes XML/UUID históricos y datos fiscales inmutables.
- [ ] Verificar que las suites de timbrado se ejecuten secuencialmente: la documentación existente advierte locks MySQL con IDs compartidos entre bases.

Salida: expectativas antes del cambio y fixtures representativos. No hay resultado de pruebas nuevas en la auditoría actual.

## Fase 2 — Schema e historial (responsable: DBA + backend; riesgo CRITICAL)

- [ ] Comparar postcondiciones y DML de cada una de las 19 migraciones Base sin registro, listadas abajo. Distinguir efecto completo/parcial/ausente, con consultas específicas sobre clones.
- [ ] Verificar interpretación de namespace/group: 48 clean_build y 38 default de Navika frente a 65 default Base. No cambiar grupos sólo para silenciar migrate:status.
- [ ] Documentar una estrategia por migración: reconciliar historial con evidencia, reparación incremental o ejecución controlada únicamente si corresponde. Los backfills requieren validación de datos, no sólo DDL.
- [ ] Resolver diferencias de collations por tabla/columna; comprobar compatibilidad, bytes y colisiones de índices en clones. No convertir automáticamente latin1→utf8mb4.
- [ ] Resolver los nombres de FKs de notas de crédito sin crear relaciones duplicadas ni perder RESTRICT.
- [ ] Preparar AddProposalTemplateSelection (nullable, sin inferencia/backfill de snapshots históricos), pendiente en Navika.
- [ ] Preparar contrato de manuales: client_id/sale_unit_price/quantity nullable, notes/updated_at/idempotency_key y uq_cost_history_manual_idempotency.
- [ ] Preparar contrato de externos: dos tablas con índices/FKs e invoice_id nullable, preservando relaciones internas.
- [ ] Ensayar instalación desde baseline y actualización desde cada versión; repetir sólo operaciones diseñadas como idempotentes y comparar postcondiciones.
- [ ] Definir recuperación por cambio. Down no destructivo de manuales no es rollback completo; volver a invoice_id NOT NULL no es seguro tras crear externos.

Salida: schema objetivo explícito, ledger de migración justificado y ensayo reversible documentado. Bloqueo: cualquier postcondición/DML desconocida. No usar un migrate global para resolverlo.

### Migraciones Base que requieren conciliación individual

| Timestamp | Migración | Tarea |
| --- | --- | --- |
| 2026-08-26-100000 | CanonicalizeAdministrativePayments | Verificar DDL + DML + postcondiciones; no ejecutar automáticamente |
| 2026-08-26-180000 | ApplyAdministrativePaymentsToSales | Verificar DDL + DML + postcondiciones; no ejecutar automáticamente |
| 2026-08-26-210000 | PreparePaymentComplementDraftsFromAllocations | Verificar DDL + DML + postcondiciones; no ejecutar automáticamente |
| 2026-08-27-090000 | TraceComplementGeneratedAllocations | Verificar DDL + DML + postcondiciones; no ejecutar automáticamente |
| 2026-08-27-100000 | CompletePaymentComplementFiscalData | Verificar DDL + DML + postcondiciones; no ejecutar automáticamente |
| 2026-08-27-120000 | EnablePaymentComplementStamping | Verificar DDL + DML + postcondiciones; no ejecutar automáticamente |
| 2026-08-28-150000 | CreateFiscalCreditNotes | Verificar DDL + DML + postcondiciones; no ejecutar automáticamente |
| 2026-08-28-170000 | CreateSupplierCostHistory | Verificar DDL + DML + postcondiciones; no ejecutar automáticamente |
| 2026-08-29-090000 | AddSupplierIdentity | Verificar DDL + DML + postcondiciones; no ejecutar automáticamente |
| 2026-08-29-100000 | BackfillFormalSupplierCostHistory | Verificar DDL + DML + postcondiciones; no ejecutar automáticamente |
| 2026-08-29-110000 | IndexSupplierCostComparison | Verificar DDL + DML + postcondiciones; no ejecutar automáticamente |
| 2026-08-29-120000 | CreateProposalItemSupplierQuotes | Verificar DDL + DML + postcondiciones; no ejecutar automáticamente |
| 2026-08-29-130000 | CreateWarehouseLogisticsFoundation | Verificar DDL + DML + postcondiciones; no ejecutar automáticamente |
| 2026-08-31-090000 | CreateWarehouseTransfers | Verificar DDL + DML + postcondiciones; no ejecutar automáticamente |
| 2026-08-31-091000 | AddWarehouseProductLabelLogo | Verificar DDL + DML + postcondiciones; no ejecutar automáticamente |
| 2026-09-01-090000 | UnifyCommercialItemPricingAndSupplierHistory | Verificar DDL + DML + postcondiciones; no ejecutar automáticamente |
| 2026-09-01-091000 | EnsureGenericSupplierCostHistorySource | Verificar DDL + DML + postcondiciones; no ejecutar automáticamente |
| 2026-09-01-100000 | CanonicalizeInvoiceTotalForPayments | Verificar DDL + DML + postcondiciones; no ejecutar automáticamente |
| 2026-09-01-110000 | DecouplePaymentComplementFiscalDocuments | Verificar DDL + DML + postcondiciones; no ejecutar automáticamente |

## Fase 3 — Servicios compartidos y herramientas Base (responsable: backend)

- [ ] Revisar e integrar diagnóstico genérico Base: BaselineCheck, servicios de baseline, checks de DB/log/dashboard. Conservar formato de salida usado por iKontrolAdmin.
- [ ] Revisar aparte provisionamiento, password, settings baseline y ajustes de timbres: tienen capacidad de escritura y quedan G hasta validar guardas/contratos.
- [ ] Preparar la versión de SupplierCostHistoryService requerida por BackfillFormalSupplierCostHistory antes del ensayo del backfill. No permitir que una migración histórica use silenciosamente una semántica incompatible del servicio.
- [ ] Mantener resolvers fiscales y dinero decimal como origen común; definir compatibilidad de ProposalTotalsService/CommercialItemTaxDisplayService sin duplicar impuestos en vistas.
- [ ] Reconciliar contrato de salida de FiscalIntegrationStatusService con consumidores y herramientas Base: Navika cambia claves y ambiente.

Salida: servicios compatibles para ambos orígenes, tests de caracterización aprobados. Dependencia: DDL mínimo de fase 2; DML dependiente se completa después de validar servicios.

## Fase 4 — Fiscal por paquetes (responsable: backend fiscal + QA; CRITICAL)

### 4A. Ambientes y transporte

- [ ] Revisar conjuntamente TimbradorXpress.php, factory, REST adapter, preflight, status, CLI prepare/stamp y topbar. No habilitar producción por copiar sólo el adaptador.
- [ ] Matriz de pruebas: módulo apagado, stampingEnabled=false, preview, automated_test, integration/development coherente, integration/production coherente, URL/ambiente/credencial discordantes, serie/borrador/emisor de otro ambiente.
- [ ] Probar que guards bloqueen antes de crear transporte/consumir timbre. Confirmaciones CLI por ambiente deben coincidir con la política aprobada.
- [ ] Revisar FiscalIntegrationPrepare: Base prepara registros de development; Navika exige registros existentes. Acordar provisión fuera del diagnóstico.

### 4B. Reintento, incertidumbre y wallet

- [ ] Integrar como unidad FiscalPreparedDocumentLifecycleService, FiscalStampingService y evidencia del adapter.
- [ ] Cubrir transport_not_sent probado, request_sent contradictorio, timeout/envío incierto, UUID/response previos, stamped/cancelled, snapshot igual/distinto e intento antiguo incierto seguido de fallo local.
- [ ] Cubrir dos solicitudes concurrentes: nunca duplicar timbrado, folio, reserva ni consumo. Unknown exige conciliación, no reintento ciego.
- [ ] Validar wallet en development/production con issuer/serie/borrador coherentes y preservar historial de movimientos.

### 4C. Complementos externos y PDF

- [ ] Tras schema compatible, revisar RelatedDocuments, ExternalDocumentService y PaymentBuilder con internos, externos y mixtos.
- [ ] Integrar snapshot v8, readiness, materializer, FiscalServices/PaymentComplementFiscalDocumentService y PrintDataBuilder junto con controller/view/rutas.
- [ ] Probar monedas iguales/distintas, equivalencia, precisión, traslados/retenciones/exentos, parcialidades/saldos, capacidad y duplicados/concurrencia.
- [ ] Verificar Serie/Folio en XML nuevo y PDF. Regenerar PDF histórico no debe alterar XML firmado ni UUID. No crear venta de relleno para externos.
- [ ] Confirmar que externos no crean movimientos administrativos o allocations espurios; preservar los internos existentes.
- [ ] Regresión obligatoria de ingreso, egreso, cancelación, CSD, wallet y generación PDF, aunque sus archivos directos coincidan.

Salida: suites fiscal fake/aisladas e invariantes aprobadas. Transporte real permanece cerrado hasta decisión operativa separada, fuera de esta auditoría.

## Fase 5 — Proposals (responsable: backend + frontend)

- [ ] Asegurar columna proposal_template_id antes de desplegar servicio/controlador. No inferir IDs de templates para contenido histórico.
- [ ] Integrar ProposalTotalsService y TemplateFiscalService con helper, partials, carga inicial/AJAX, preview público y PDF.
- [ ] Preservar PROPOSAL_ITEMS y PROPOSAL_TOTAL legacy; evaluar ITEMS_WITH_TAXES/TAXES/GRAND_TOTAL y filas condicionales con descuentos cero/positivos.
- [ ] Integrar SelectionService, controladores, editor y assets/js/proposal_editor_save.js juntos: navegar sólo tras guardar/verificar; probar fallo/duplicado/concurrencia.
- [ ] Probar imágenes locales/PDF, retenciones, precios incluidos/excluidos, cost_margin y redondeos. Resolver explícitamente diferencias de centavos, no ocultarlas en presentación.
- [ ] Probar aceptación/conversión: mismos subtotal/descuento/impuestos/total; discrepancia revierte transacción completa, sin venta parcial; preservar compatibilidad legacy incompleta.

Salida: mismo contrato económico en UI/preview/PDF/venta; snapshots previos preservados.

## Fase 6 — Suppliers (responsable: backend + frontend)

- [ ] Activar schema de manuales antes de saveManual/LEFT JOIN/comparador/UI.
- [ ] Mantener snapshots de proposals/estimates/invoices junto con manuales; no convertir manuales en ventas o documentos inexistentes.
- [ ] Validar idempotency_key con repetición y concurrencia; valores opcionales permanecen NULL, no cero inventado.
- [ ] Validar costos por producto/proveedor/moneda/fecha y efectos sobre comparación.
- [ ] Verificar supplier_costs_edit/supplier_costs_view/suppliers_manage en backend y UI con admin/usuario permitido/denegado.
- [ ] Aplicar decisión de edición/eliminación tomada en fase 0 sin borrar evidencia formal.

Salida: comparación y trazabilidad compatibles con ambos orígenes; sin pérdida del historial comercial.

## Fase 7 — Warehouses (responsable: QA + backend)

- [ ] Conciliar historial de migraciones sin reconstruir tablas ya existentes.
- [ ] Regresión de catálogo, movimientos, dispatch/receive, recepción parcial, diferencia, cancelación y tránsito.
- [ ] Verificar saldo/ledger por almacén/producto y barcode/etiquetas; ninguna necesidad de reemplazo funcional se deduce de esta auditoría.

Salida: invariantes logísticos preservados.

## Fase 8 — Rutas, permisos, UI y regresión final (responsable: QA + seguridad de aplicación)

- [ ] Comparar router efectivo en clones con inventario estático; resolver GET supplier_costs→index y rutas catch-all.
- [ ] Cubrir explícitas y dinámicas para acciones nuevas; demostrar autorización/CSRF/verbos, sin presumir que sólo la ruta explícita es alcanzable.
- [ ] Probar settings/roles/dashboards/reports con fixtures de ambos esquemas; igualdad de schema no prueba igualdad de permisos.
- [ ] Validar salida de herramientas Base y avisos de entorno Navika.
- [ ] Ejecutar la matriz de suites de abajo tras revisar su bootstrap, guardar resultados y comparar invariantes de fase 1.

Salida: ninguna regresión crítica abierta; decisiones G críticas resueltas o paquete excluido explícitamente.

### Suites candidatas (no ejecutadas en esta auditoría)

| Paquete | Suites / evidencia a ejecutar únicamente en entorno aislado |
| --- | --- |
| Baseline | Base tests/BaselineCheck/run.php; revisar contrato de herramientas iKontrolAdmin |
| Ambientes/PAC | Navika FiscalProductionEnvironment, TimbradorXpressProductionTransport, FiscalInvoiceFlowEnvironment; ambas IncrementA1 y FiscalStampingBoundary |
| Reintentos | Navika FiscalPreparedDocumentRetry; ambas FiscalPreparedDocumentLifecycle |
| Pagos/externos | Navika PaymentComplementExternalDocuments/run.php y validation.php; ambas CanonicalPayments, PaymentComplementStamping, PaymentComplementsPhase3, PaymentComplementCancellation |
| PDF/fiscal | FiscalPdfRegeneration, CreditNotes, CreditNoteFiscalSquare, FiscalSaleConsistency y suites de cancelación fake pertinentes |
| Proposals | Navika ProposalTemplateTaxes, ProposalTemplatePersistence (PHP y save-flow.js); ambas ProposalPdfConversion |
| Suppliers | Navika ManualSupplierCosts; ambas DoldSupplierCosts, DoldSupplierComparison, SupplierHistoryMigration |
| Warehouses | Ambas DoldWarehousesPhase1 y DoldWarehousesPhase2 |

Los nombres abreviados corresponden a tests/<nombre>/run.php salvo archivos explícitos. Excluir scripts manual_cancel_development/manual_status_development y cualquier llamada real del ensayo ordinario.

## Fase 9 — Cleanup y entrega futura (responsable: mantenimiento)

- [ ] Normalizar EOL en revisión separada; actualizar docs históricos sin trasladar datos del cliente.
- [ ] Revisar .gitkeep, archivos auxiliares y reglas .gitignore; declarar OBSOLETE sólo con evidencia de ausencia de consumidores.
- [ ] Actualizar plantilla/env example con contratos acordados y valores seguros, sin copiar .env.
- [ ] Entregar diff mínimo por paquete, decisiones por ID, inventario final, pruebas y recuperación ensayada.
- [ ] Revisar despliegue real por separado después de validar el resultado concreto. No realizar merge ni migraciones como parte de esta auditoría.

## Criterio de cierre de la conciliación futura

Ambas fuentes representadas; 19 pendientes de historial Base resueltos con evidencia; template consistente con schema; ambientes/reintentos/pagos validados; ningún UUID/XML/saldo/folio perdido; permisos comprobados; contenido cliente aislado; pruebas reproducibles aprobadas y decisiones documentadas. Hoy sólo se entregan auditoría y plan.
