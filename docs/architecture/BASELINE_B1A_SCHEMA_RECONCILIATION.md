# FASE B1A - RECONCILIACIÓN DE ESQUEMA E HISTORIAL DE MIGRACIONES IKONTROL

## 1. Resumen ejecutivo

La instancia auditada está conectada a MySQL/MariaDB con la base `ikontrol20_ik_ikontrol`, prefijo `ikontrol_`, y el conjunto real de tablas indica un estado mixto: hay un núcleo administrativo y fiscal parcialmente instalado, tablas para proveedores y almacenes presentes, y un historial de migraciones no sincronizado con el registro de la BD.

Conclusiones principales:

- La base no es una instancia limpia recién generada desde cero ni una instalación literal de la última cadena migratoria.
- El historial físico de tablas y columnas coincide con la presencia de módulos avanzados: fiscal, créditos, proveedores, almacenes y pagos complementarios.
- El registro de `ikontrol_migrations` está incompleto respecto a los 84 archivos físicos en `app/Database/Migrations/`.
- La diferencia real no es sólo un “migración pendiente”; hay un caso claro de registro de migraciones desincronizado y varias migraciones con efectos de backfill / normalización no estructurales que deben tratarse como riesgos de reejecución.
- La línea base oficial para una nueva instancia no puede basarse en ejecutar `php spark migrate` a ciegas; primero debe establecerse la reconciliación estructural actual y luego consolidarse un estado definitivo de esquema base.

Evidencia exacta observada:

- Archivos de migración físicos: 84
- Registros en `ikontrol_migrations`: 65
- Versiones únicas: 64
- Duplicado en el historial: 1 (`2026-08-13-190100`)
- Versiones faltantes del registro frente a archivos: 19
- Versiones físicas presentes que no están registradas en BD: varias, y entre ellas las de proveedores/almacenes/complementos de pagos y la canonicalización de totales.

La auditoría confirma además que la migración base de RISE exige que la estructura administrativa heredada ya exista previamente; la propia clase `RiseAdministrativeBaseline` falla si faltan tablas clave y advierte que el esquema debe venir de `install1/database.sql`.

## 2. Esquema real encontrado

### 2.1 Base real y prefijo

- Base auditada: `ikontrol20_ik_ikontrol`
- Prefijo configurado: `ikontrol_`
- Resultado observado: tablas reales como `ikontrol_clients`, `ikontrol_fiscal_documents`, `ikontrol_warehouses`, etc.

### 2.2 CORE presente físicamente

Las tablas del núcleo que existen físicamente en la BD auditada son:

- `ikontrol_clients`
- `ikontrol_items`
- `ikontrol_item_categories`
- `ikontrol_invoices`
- `ikontrol_invoice_items`
- `ikontrol_invoice_payments`
- `ikontrol_payment_allocations`
- `ikontrol_payment_methods`
- `ikontrol_financial_accounts`
- `ikontrol_financial_account_movements`
- `ikontrol_settings`
- `ikontrol_company`
- `ikontrol_users`
- `ikontrol_roles`
- `ikontrol_project_status`
- `ikontrol_task_status`
- `ikontrol_task_priority`

Estructuralmente, la BD local tiene las tablas del núcleo con columnas relevantes, pero la auditoría B0 ya documentó que no hay datos operativos mínimos en varios catálogos: clientes, empresa, impuestos, estados y métodos administrativos de pago están vacíos o incompletos.

### 2.3 MÓDULO FISCAL presente

Las tablas del módulo fiscal que existen físicamente son:

- `ikontrol_fiscal_profiles`
- `ikontrol_fiscal_series`
- `ikontrol_fiscal_documents`
- `ikontrol_fiscal_document_issuers`
- `ikontrol_fiscal_document_receivers`
- `ikontrol_fiscal_document_items`
- `ikontrol_fiscal_document_item_taxes`
- `ikontrol_fiscal_document_tax_totals`
- `ikontrol_fiscal_document_metadata`
- `ikontrol_fiscal_document_stamps`
- `ikontrol_fiscal_stamp_attempts`
- `ikontrol_fiscal_document_sales`
- `ikontrol_fiscal_document_relations`
- `ikontrol_fiscal_credit_notes`
- `ikontrol_fiscal_credit_note_items`
- `ikontrol_payment_complements`
- `ikontrol_payment_complement_payments`
- `ikontrol_payment_complement_documents`
- `ikontrol_payment_complement_fiscal_snapshots`

Estas tablas tienen esquema completo de documentación, firma, emitidos, relación con ventas y complementos de pago. Se observan también registros y estructuras compatibles con el flujo de timbrado y pagos, aunque no se deduce de la estructura que toda la lógica de negocio haya sido concluida.

### 2.4 CATÁLOGOS SAT presentes

Las tablas SAT existentes físicamente son:

- `ikontrol_sat_product_service_keys`
- `ikontrol_sat_unit_keys`
- `ikontrol_sat_tax_codes`
- `ikontrol_sat_tax_factor_types`
- `ikontrol_sat_cfdi_uses`
- `ikontrol_sat_tax_regimes`
- `ikontrol_sat_tax_object_codes`
- `ikontrol_sat_payment_forms`
- `ikontrol_sat_payment_methods`
- `ikontrol_sat_currencies`

La auditoría B0 observó que, dentro de la BD local, algunos catálogos SAT están vacíos o parcialmente precargados; lo importante es que la estructura física está presente, aunque el contenido operativo puede no estar cargado.

### 2.5 OPCIONALES presentes

Las tablas opcionales importantes de proveedores, costos y almacenes están presentes y físicamente aplicadas:

- `ikontrol_suppliers`
- `ikontrol_product_supplier_cost_history`
- `ikontrol_proposal_item_supplier_quotes`
- `ikontrol_warehouses`
- `ikontrol_warehouse_products`
- `ikontrol_warehouse_movements`
- `ikontrol_warehouse_transfers`

Esto es evidencia fuerte de que el esquema final de estas áreas fue aplicado en la base real, aunque no haya sido registrado correctamente en `migrations`.

### 2.6 Evidencia estructural clave por tabla

La observación de columnas confirma esta lectura real:

- `ikontrol_fiscal_documents` incluye identificación de documento, emisor, receptor, tipo, serie, subtotal, descuento, taxes y estado.
- `ikontrol_fiscal_document_items` incluye `quantity`, `unit_value`, `gross_amount`, `tax_object_code`, `taxable_base`, `transferred_tax_total` y `line_total`.
- `ikontrol_fiscal_document_item_taxes` incluye `administrative_tax_id`, `tax_code`, `tax_type`, `factor_type`, `rate_or_quota`, `taxable_base`, `amount`.
- `ikontrol_payment_complements` y `ikontrol_payment_complement_payments` muestran que el flujo de pagos complementarios existe físicamente.
- `ikontrol_warehouses` y `ikontrol_warehouse_products` tienen columnas de estado, control de producto y integridad del movimiento.
- `ikontrol_suppliers` y `ikontrol_product_supplier_cost_history` muestran una capa operativa de proveedores y costos ya aplicada.

## 3. Historial real de migraciones

### 3.1 Conteo real

En la BD auditada: 

- Archivos de migración físicos en `app/Database/Migrations/`: 84
- Registros actuales en `ikontrol_migrations`: 65
- Versiones únicas en `ikontrol_migrations`: 64
- Versiones duplicadas: 1
- Versiones faltantes respecto al código: 19

La comparación exacta de archivos vs registro produjo:

- `missing_registry = 19`
- `duplicate_versions = 1`
- `duplicate_version = 2026-08-13-190100:2`

Listado de versiones faltantes del registro de migraciones:

- 2026-08-26-100000
- 2026-08-26-180000
- 2026-08-26-210000
- 2026-08-27-090000
- 2026-08-27-100000
- 2026-08-27-120000
- 2026-08-28-150000
- 2026-08-28-170000
- 2026-08-29-090000
- 2026-08-29-100000
- 2026-08-29-110000
- 2026-08-29-120000
- 2026-08-29-130000
- 2026-08-31-090000
- 2026-08-31-091000
- 2026-09-01-090000
- 2026-09-01-091000
- 2026-09-01-100000
- 2026-09-01-110000

El registro también presenta un duplicado: `2026-08-13-190100` aparece dos veces. Ese hecho no es neutro: indica un historial de migraciones que no está totalmente consistente ni trazable.

### 3.2 Comportamiento legal de la migración base

La migración base `RiseAdministrativeBaseline` exige tablas RISE existentes previamente:

- `settings`
- `users`
- `roles`
- `clients`
- `items`
- `estimates`
- `estimate_items`
- `invoices`
- `invoice_items`
- `invoice_payments`
- `payment_methods`
- `taxes`
- `company`

Si faltan, la migración lanza una excepción y exige instalar la estructura heredada desde `install1/database.sql` antes de continuar.

Esto es evidencia de que la cadena migratoria no es una “instalación desde cero” completa; es un “ensamble fiscal-administrativo” sobre una base previa.

## 4. Matriz migración vs estructura

### 4.1 Clasificación A-F

Se utiliza la siguiente clasificación:

- A. Registrada y físicamente aplicada
- B. Registrada pero estructura incompleta / diferente
- C. No registrada pero físicamente aplicada
- D. No registrada y no aplicada
- E. Aplicada parcialmente
- F. No determinable sin evidencia adicional

### 4.2 Clasificación por grupo de migraciones

| Grupo / migración representativa | Estado | Evidencia | Riesgo |
|---|---|---|---|
| `2026-07-21-000000_RiseAdministrativeBaseline` | A | Base RISE verificada; tabla `ikontrol_migrations` incluye su versión; la estructura de tablas base existe | Bajo |
| `2026-07-21-010000_CreateMinimalSatCatalogs` | A | Catálogos SAT básicos creados y visibles en la BD | Bajo |
| `2026-07-23-030000_CreateSatProductServiceKeys` | A | `ikontrol_sat_product_service_keys` está presente y con columnas `code`, `description`, `is_active` | Bajo |
| `2026-07-26-060100_CreateFiscalDocuments` | A | `ikontrol_fiscal_documents` y tablas relacionadas existen con columnas de documento | Bajo |
| `2026-08-25-210000_CreateFinancialAccounts` | A | `ikontrol_financial_accounts`, `movements`, `transfers` existen | Bajo |
| `2026-08-25-220000_CreatePaymentAllocations` | A | La tabla `ikontrol_payment_allocations` existe con columnas relevantes | Bajo |
| `2026-08-25-230000_CreatePaymentComplementDrafts` | A | `ikontrol_payment_complements`, `payment_complement_documents` y `payment_complement_payments` presentes | Bajo |
| `2026-08-29-130000_CreateWarehouseLogisticsFoundation` | C | Existen `ikontrol_warehouses`, `ikontrol_warehouse_products`, `ikontrol_warehouse_movements` y `ikontrol_warehouse_movement_lines`; la versión no está en `ikontrol_migrations` | Medio-alto |
| `2026-08-31-090000_CreateWarehouseTransfers` | C | Existen tablas de transferencias y movimientos; la versión no está registrada | Medio-alto |
| `2026-08-28-150000_CreateFiscalCreditNotes` | C | `ikontrol_fiscal_credit_notes` y `ikontrol_fiscal_credit_note_items` físicas están presentes; la versión no aparece en el registro | Medio |
| `2026-08-29-090000_AddSupplierIdentity` | C | `ikontrol_suppliers` física existe con `rfc`, `normalized_name`, `status`; no aparece en `migrations` | Medio |
| `2026-08-29-120000_CreateProposalItemSupplierQuotes` | C | Tabla real presente; versión del registro ausente | Medio |
| `2026-09-01-100000_CanonicalizeInvoiceTotalForPayments` | C | Estructura de pagos y total de facturas ya presenten ajustes; versión no registrada | Medio-alto |
| `2026-09-01-110000_DecouplePaymentComplementFiscalDocuments` | C | La tabla y columnas de pagos complementarios ya están desacopladas; la versión no está registrada | Medio-alto |
| `2026-08-13-210100_BackfillPacCreditSnapshots` | E | Tiene efecto de backfill en `fiscal_pac_credit_snapshots`; se invoca SQL de inserción, no solo creación estructural | Alto |
| `2026-08-26-180000_ApplyAdministrativePaymentsToSales` | E | Modifica `payment_allocations` y elimina columnas/índices; versión faltante del registro y alteración significativa previa al estado físico | Alto |
| `2026-08-13-190100_NormalizeIssuerMasterToDevelopment` / `NormalizeLegacyPreviewToDevelopment` | F | Hay duplicado `2026-08-13-190100` en `ikontrol_migrations`; sospecha de colisión de versiones o ejecución doble | Alto |

## 5. Migraciones registradas y físicamente aplicadas

Las migraciones que se observan tanto en la base de datos como en la estructura del esquema son las que pertenecen al núcleo fiscal y RISE más temprano. En la práctica, esto incluye:

- 2026-07-21-000000_RiseAdministrativeBaseline
- 2026-07-21-010000_CreateMinimalSatCatalogs
- 2026-07-21-010100_ExtendAdministrativeTaxesForFiscalPreparation
- 2026-07-21-010200_CreateFiscalProfiles
- 2026-07-23-030000_CreateSatProductServiceKeys
- 2026-07-23-030100_CreateSatUnitKeys
- 2026-07-23-030200_CreateSatTaxObjectCodes
- 2026-07-23-030300_CreateItemFiscalSettings
- 2026-07-23-030400_CreateItemFiscalTaxes
- 2026-07-23-030500_AddCompleteFiscalAddressToProfiles
- 2026-07-24-040000_ExtendFiscalProfilesForIssuers
- 2026-07-24-040100_CreateFiscalSeries
- 2026-07-25-050000_AddIssuerTaxPricingPolicy
- 2026-07-25-050100_CreateSaleFiscalPricingPreparations
- 2026-07-26-060000_CreateFiscalDraftCatalogs
- 2026-07-26-060100_CreateFiscalDocuments
- 2026-07-26-060200_CreateFiscalDocumentIssuers
- 2026-07-26-060300_CreateFiscalDocumentReceivers
- 2026-07-26-060400_CreateFiscalDocumentItems
- 2026-07-26-060500_CreateFiscalDocumentItemTaxes
- 2026-07-26-060600_CreateFiscalDocumentTaxTotals
- 2026-07-26-060700_CreateFiscalDocumentMetadataAndAudit
- 2026-07-27-070000_CreateFiscalDocumentArtifacts
- 2026-07-28-080000_CreateFiscalIssuerCertificatesAndPaymentMappings
- 2026-07-28-080100_CreateFiscalDocumentSignatures
- 2026-07-29-090000_CreateFiscalPacConfigurations
- 2026-07-29-090100_CreateFiscalStampAttempts
- 2026-07-29-090200_CreateFiscalDocumentStamps
- 2026-07-29-090300_PrepareFiscalStampingStates
- 2026-07-29-090400_DeprecateDatabasePacCredentials
- 2026-07-29-090500_ExtendTimbradorXpressStampMetadata
- 2026-07-29-090600_AddPacErrorGuidance
- 2026-07-29-090700_CreateFiscalBinaryArtifacts
- 2026-07-29-090800_MigrateFiscalPdfPermissions
- 2026-07-30-100000_CreateCsdCertificateSecrets
- 2026-07-31-110000_CreateFiscalCancellationWorkflow
- 2026-08-01-120000_CreateFiscalPdfProviderWorkflow
- 2026-08-02-130000_CreateCommercialFiscalAllocationModel
- 2026-08-03-140000_ExtendFiscalDraftWorkflow
- 2026-08-04-150000_CreateCommercialLifecycle
- 2026-08-04-150100_EnsureCommercialStatusCompatibility
- 2026-08-04-150200_CreateFiscalDraftItemTaxes
- 2026-08-04-150300_PrepareFiscalDraftStamping
- 2026-08-04-150400_AddFiscalIntegrationEnvironment
- 2026-08-04-160000_CreateLegacyImportRegistry
- 2026-08-04-160100_ConvertItemRateToExactDecimal
- 2026-08-04-170000_CreateFiscalStampCommercialControl
- 2026-08-06-180000_AddEstimateItemCostAndProfit
- 2026-08-06-180100_CreateProposalSaleConversion
- 2026-08-13-190000_NormalizeDevelopmentPacOperations
- 2026-08-13-210000_SeparatePacCreditsAndReconciliation
- 2026-08-13-210100_BackfillPacCreditSnapshots
- 2026-08-14-093600_HomologateCommercialItemsAndFiscalOverrides
- 2026-08-21-090500_AddFiscalOverrideToCommercialItems
- 2026-08-25-160000_AddCancellationCommercialWalletOperations
- 2026-08-25-210000_CreateFinancialAccounts
- 2026-08-25-220000_CreatePaymentAllocations
- 2026-08-25-230000_CreatePaymentComplementDrafts
- 2026-08-25-231000_CreatePaymentComplementFiscalSnapshots
- 2026-08-26-090000_HardenFinancialLedger
- 2026-08-26-091000_AddFinancialLedgerGuards

Estas migraciones sí están registradas en la tabla y las tablas físicas existen en la BD. La evidencia es positiva para el motor fiscal y contable.

## 6. Migraciones no registradas pero aplicadas

Estas versiones están ausentes en `ikontrol_migrations` pero el esquema físico indica que sus cambios se aplicaron:

- 2026-08-26-100000_CanonicalizeAdministrativePayments
- 2026-08-26-180000_ApplyAdministrativePaymentsToSales
- 2026-08-26-210000_PreparePaymentComplementDraftsFromAllocations
- 2026-08-27-090000_TraceComplementGeneratedAllocations
- 2026-08-27-100000_CompletePaymentComplementFiscalData
- 2026-08-27-120000_EnablePaymentComplementStamping
- 2026-08-28-150000_CreateFiscalCreditNotes
- 2026-08-28-170000_CreateSupplierCostHistory
- 2026-08-29-090000_AddSupplierIdentity
- 2026-08-29-100000_BackfillFormalSupplierCostHistory
- 2026-08-29-110000_IndexSupplierCostComparison
- 2026-08-29-120000_CreateProposalItemSupplierQuotes
- 2026-08-29-130000_CreateWarehouseLogisticsFoundation
- 2026-08-31-090000_CreateWarehouseTransfers
- 2026-08-31-091000_AddWarehouseProductLabelLogo
- 2026-09-01-090000_UnifyCommercialItemPricingAndSupplierHistory
- 2026-09-01-091000_EnsureGenericSupplierCostHistorySource
- 2026-09-01-100000_CanonicalizeInvoiceTotalForPayments
- 2026-09-01-110000_DecouplePaymentComplementFiscalDocuments

Evidencia física:

- `ikontrol_fiscal_credit_notes` y `ikontrol_fiscal_credit_note_items` existen.
- `ikontrol_suppliers`, `ikontrol_product_supplier_cost_history`, `ikontrol_proposal_item_supplier_quotes` existen.
- `ikontrol_warehouses`, `ikontrol_warehouse_products`, `ikontrol_warehouse_movements`, `ikontrol_warehouse_transfers` existen.
- `ikontrol_payment_complement_fiscal_snapshots` existe, y el código de la migración de desacople indica una estructura significativa.

Conclusión: estas migra ciones están en el esquema, pero no en el “historial oficial” del sistema de migración. Por tanto, el estado de la BD no puede considerarse un estado “limpio” de migraciones ni una base reutilizable para un `php spark migrate` directo.

## 7. Migraciones realmente pendientes

No se puede afirmar que haya migraciones realmente pendientes sin una comparación más detallada del estado de la aplicación y del esquema de producción. Sin embargo, el conjunto de versiones faltantes del registro no puede tratarse como “pendientes” en el sentido de ejecutar un comando automático: 

- varias de esas migraciones ya están aplicadas físicamente (tabla creada, columnas ya existentes); 
- algunos cambios son backfills o alteraciones de índices/casos de negocio; 
- otras son modificaciones estructurales de pagos y complementos que no deberían retrotraerse o ejecutarse a ciegas.

En esta fase, la interpretación correcta es:

- no hay un historial canónico de migra ción confiable;
- la BD física es la fuente de verdad del esquema real;
- la tabla `ikontrol_migrations` no refleja el panorama completo.

## 8. Migraciones parcialmente aplicadas

Las migraciones que se consideran parcialmente aplicadas son las que tienen:

- composición estructural (creación de tablas o columnas) presente;
- pero efectos de negocio o limpieza de datos que no pueden verificarse sin revisiones de datos y dependencias.

Principales casos:

### 8.1 `2026-08-13-210100_BackfillPacCreditSnapshots`

Archivo observado:

- `BackfillPacCreditSnapshots` hace `INSERT` en `fiscal_pac_credit_snapshots` desde `fiscal_pac_credit_consultations`.

Riesgo:

- Se trata de un backfill no estructural y puede duplicar datos si se ejecuta de nuevo o si la data fuente ya fue migrada.
- No se puede afirmar que el backfill haya ocurrido en este estado sin verificar el conteo aún en BD.

### 8.2 `2026-08-26-180000_ApplyAdministrativePaymentsToSales`

Archivo observado:

- Vacía `payment_allocations`
- Elimina índices y una FK
- Añade columna `invoice_id`
- Re-crea índices y restricciones

Riesgo:

- Este cambio altera el significado de la relación de pagos a ventas; no es un cambio sólo de estructura.
- La migración fue escrita para limpiar datos “de prueba” y reubicar la relación de pago-factura.
- Reejecutarla a ciegas en una BD con datos reales puede destruir el vínculo de pagos.

### 8.3 `2026-08-29-100000_BackfillFormalSupplierCostHistory`

Riesgo:

- Backfill de historial de costos de proveedor. Si el origen o los precios fuente no están normalizados, la reejecución puede duplicar o deformar costos históricos.

### 8.4 `2026-09-01-100000_CanonicalizeInvoiceTotalForPayments`

Riesgo:

- Afecta totales administrativos de facturas y pagos. No es seguro reordenar ni recalcular sin un marco explícito de numeración y reconciliación.

## 9. Backfills peligrosos de repetir

La auditoría no debe ejecutar ni repetir estos cambios, pero sí identificarlos como peligrosos.

### 9.1 Backfills de datos / reescrituras

- `BackfillPacCreditSnapshots`
- `BackfillFormalSupplierCostHistory`
- `ApplyAdministrativePaymentsToSales`
- `CanonicalizeInvoiceTotalForPayments`
- `CompletePaymentComplementFiscalData`
- `PreparePaymentComplementDraftsFromAllocations`
- `TraceComplementGeneratedAllocations`

### 9.2 Cambios operativos no estructurales que requieren evidencia antes de reponer

- `UPDATE` o `INSERT` en tablas de historial, permisos, settings, estados de módulos o roles.
- eliminación de índices o FKs para normalizar relaciones.
- transformación de datos entre `invoice_payments`, `payment_allocations` y `fiscal_document_sales`.
- backfills de costos, snapshots fiscales, intentos PAC y documentación del valor de pagos.

### 9.3 Evidencia necesaria para saber si el efecto ya ocurrió

Para cada backfill, la evidencia mínima requiere:

- conteo de filas antes/después en tabla fuente y destino;
- valor de `created_at`, `updated_at`, `status`, `deleted` y clave de unicidad;
- integridad de filas para cada documento/factura/relación;
- comprobación de que no existe duplicación por clave natural o hash; 
- revisión de índices y dependencias de FK;
- comparación de totales agregados versus data de origen.

No se debe ejecutar ningún backfill a ciegas durante B1A; sólo debe documentarse y dejarse como riesgo para B1B.

## 10. Core obligatorio

La nueva instancia no debe asumir todos los módulos activos; el core mínimo debe ser:

### 10.1 Core obligatorio – clientes, ventas, pagos, usuarios, permisos, configuración

- `company` con una empresa administrativa mínima
- `users` con administrador inicial
- `roles` con administrador y estrategia de permisos para roles adicionales
- `settings` mínimos con:
  - idioma español
  - MXN
  - zona horaria de México
  - formatos base
  - módulos base
- `clients` con identidad estable para cliente general; protección contra borrado
- `items` y `item_categories` con catálogo mínimo
- `payment_methods` con `Efectivo` como mínimo obligatorio
- `financial_accounts` con una cuenta financiera MXN base, por ejemplo “Caja general” o “Caja principal”
- `invoices` y `invoice_items` como base de ventas
- `invoice_payments` y `payment_allocations` como base de cobros y cierres

### 10.2 Core técnico

- `settings`
- `roles`
- `permissions` / claves de acceso serializadas
- `users`
- `company`
- `app_schema_versions` / historial de instalación
- `audit logs` o registro de cambios cuando esté definido
- estructura mínima de transición para pagos y cuentas

### 10.3 Módulos opcionales

- Proveedores
- Almacenes / inventario
- Proyectos / tareas
- Facturación fiscal y complementos
- Licencias, plugins y módulos adicionales

## 11. Datos base obligatorios

### 11.1 Core

Debe existir una instalación recién creada con al menos:

- una empresa
- un administrador
- roles mínimos
- settings mínimos
- cuenta mínima MXN
- método de pago administrativo `Efectivo`
- catálogo comercial mínimo
- estados base:
  - `project_status`
  - `task_status`
  - `task_priority`

### 11.2 Estados obligatorios

Los estados base que deberían existir en una instancia funcional son:

- Project status: abierto / completado / en espera
- Task status: pendiente / en proceso / completado / cancelado
- Task priority: baja / media / alta / urgente

La auditoría B0 observó que la BD local no tenía esos datos cargados; la tabla `project_status` estaba vacía. Cualquier baseline de nueva instalación debe incluir este catálogo y no depender del texto de traducción como única fuente.

## 12. Datos fiscales base

### 12.1 Catálogo SAT y catalogación operativa

Debe distinguirse claramente:

- CATÁLOGO SAT: códigos del SAT (ejemplo `002 = IVA`)
- CONFIGURACIÓN OPERATIVA DE IMPUESTOS: tasas, retenciones, uso en la operación (ejemplo IVA 16%, IVA 8%, retención ISR)

Se deben definir, sin insertarlos aún:

- `sat_product_service_keys`
- `sat_unit_keys`
- `sat_tax_codes`
- `sat_tax_factor_types`
- `sat_cfdi_uses`
- `sat_tax_regimes`
- `sat_tax_object_codes`
- `sat_payment_forms`
- `sat_payment_methods`
- `sat_currencies`

Debe existir un catálogo operativo de impuestos para:

- traslado
- retención
- factor (Tasa, Cuota, Exento)
- uso de CFDI
- régimen fiscal
- objeto de impuesto

No debe confundirse el catálogo SAT con los impuestos operativos del sistema.

## 13. Clientes especiales

### 13.1 Público en General

Debe diseñarse como un cliente comercial especial del sistema, no como un dato de un solo documento. Su función debe ser:

- mantener una identidad estable y protegida;
- permitir ventas sin un cliente real específico;
- protegerlo contra borrado accidental;
- mantener su perfil fiscal opcional cuando exista;
- permitir manejarlo sin depender de un ID numérico visible o lógico para la operación.

### 13.2 Cliente extranjero / exportación

Debe diferenciarse de Publico en General:

- Existen dos conceptos distintos: comercial especial y perfil fiscal internacional / exportación.
- El hecho de que un cliente sea extranjero no convierte automáticamente su operación en “exportación”.
- La exportación es una condición de operación/documento y debe validarse con reglas fiscales reales, no con datos inventados para “hacerlo aparecer ready”.

La auditoría B0 concluye que la implementación de `InformacionGlobal` y de exportación no está completa. Por tanto:

- A. Existe el cliente comercial especial: sí, debe diseñarse,
- B. Perfil fiscal: sí, debe definirse por perfil y RFC/residencia fiscal,
- C. Reglas reales: aún no están completamente implementadas.

## 14. Requisitos de health check

Se recomienda un comando futuro como `php spark ikontrol:baseline-check` con estas verificaciones:

- estructura incompleta
- migraciones inconsistentes
- empresa faltante
- administrador faltante
- settings faltantes
- cuenta financiera mínima faltante
- métodos de pago faltantes
- estados obligatorios faltantes
- catálogos SAT faltantes
- módulos activos sin dependencias
- clientes especiales faltantes o prohibidos
- perfil fiscal mínimo faltante para emisión
- consistencia de migraciones vs tabla `migrations`
- doble registro de versiones o colisiones
- tablas presentes sin registro de migración
- backfills sin evidencia de ejecución

No se implementará todavía; se define aquí como requisito de B1B/B2.

## 15. Estrategia recomendada de reconciliación

La estrategia recomendada es la siguiente:

1. No ejecutar migraciones ni alteraciones.
2. Tomar la BD real como fuente de verdad del esquema actual.
3. Comparar cada tabla real con los archivos de migración, tomando en cuenta columnas, índices y foreign keys.
4. Diferenciar claramente:
   - creación estructural
   - datos precargados
   - backfill
   - reescritura de relaciones
5. Declarar estado por migración en una matriz de evidencias.
6. Preparar inspección de los 19 archivos faltantes en el registro y los 1 duplicado.
7. Determinar la baseline “oficial” de una nueva instancia sin depender del historial actual corrupto.
8. Definir serial temporal de migración, no ejecutar nada hasta que se valide el esquema final.

## 16. Plan exacto para B1B

El plan posterior a B1A debe ser:

1. validar cada tabla de core/fiscal/SAT/almacenes con `SHOW CREATE TABLE` y `INFORMATION_SCHEMA`;
2. documentar columnas, índices, PKs y FKs de cada entidad relevante;
3. revisar los 19 archivos faltantes del registro y su relación con tablas físicas;
4. descartar o confirmar cada petición de backfill y estados de migración parcial;
5. construir la baseline de instalación definitiva, sin reescribir datos activos;
6. preparar un script de comprobación `ikontrol:baseline-check` sin ejecución automática;
7. definir la instalación mínima funcional con empresa, administrador, settings, cuenta y estados;
8. preparar B1C para fiscal y flujo de clientes especiales.

## 17. Matriz final de migraciones: estado, evidencia, riesgo y acción

| Migración | Estado | Evidencia | Riesgo | Acción futura recomendada |
|---|---|---|---|---|
| `2026-07-21-000000_RiseAdministrativeBaseline` | A | Tabla `ikontrol_migrations` registra la versión; base administrativa RISE detectable | Bajo | Mantener como base heredada |
| `2026-07-21-010000_CreateMinimalSatCatalogs` | A | Tablas SAT creadas, esquema visible | Bajo | Validar contenido real mínimo |
| `2026-07-26-060100_CreateFiscalDocuments` | A | `ikontrol_fiscal_documents` presente con columnas del modelo | Bajo | Verificar completitud funcional |
| `2026-08-25-210000_CreateFinancialAccounts` | A | `ikontrol_financial_accounts` y movs existen | Bajo | Definir cuenta MXN mínima |
| `2026-08-25-220000_CreatePaymentAllocations` | A | `ikontrol_payment_allocations` existe | Medio | Revalidar relación de pago-venta |
| `2026-08-29-130000_CreateWarehouseLogisticsFoundation` | C | Tabla física presente; versión no en `ikontrol_migrations` | Medio-alto | Confirmar que el historial no se perdió al importar la BD |
| `2026-08-28-150000_CreateFiscalCreditNotes` | C | Tablas físicas presentes; no registradas | Medio | Validar la secuencia por documento |
| `2026-08-29-090000_AddSupplierIdentity` | C | `ikontrol_suppliers` presente y columnas de identidad; no registrada | Medio | Inspeccionar identidad y status |
| `2026-08-29-120000_CreateProposalItemSupplierQuotes` | C | Tabla presente; versión no registrada | Medio | Revisar si hubo importación o copia desde otra base |
| `2026-09-01-110000_DecouplePaymentComplementFiscalDocuments` | C | Estructura de pagos complementarios aplicada; no en `migrations` | Alto | Revalidar desacople antes de implementar nueva baseline |
| `2026-08-13-210100_BackfillPacCreditSnapshots` | E | `INSERT` de copia de datos en snapshot, no sólo creación estructural | Alto | No repetir, solo verificar integridad de datos |
| `2026-08-26-180000_ApplyAdministrativePaymentsToSales` | E | Vacía tabla y reconfigura índices/FK | Alto | No ejecutar en la baseline ni en una DB productiva |
| `2026-08-29-100000_BackfillFormalSupplierCostHistory` | E | Backfill de historial de costos | Alto | Verificar integración antes de cualquier reejecución |
| `2026-09-01-100000_CanonicalizeInvoiceTotalForPayments` | E | Modifica totales y pagos; requiere evidencia de reconciliación | Alto | No volver a ejecutar sin validación de saldo |
| `2026-08-13-190100_NormalizeIssuerMasterToDevelopment` | F | Duplicado exacto en `ikontrol_migrations`; versión colisionada | Alto | Reconciliar el historial antes de B1B |
| `2026-08-13-190100_NormalizeLegacyPreviewToDevelopment` | F | Mismo timestamp en la tabla y no se puede distinguir el original del segundo | Alto | Resolver conflicto de versión en el historial |
| `2026-08-26-100000_CanonicalizeAdministrativePayments` | C | Cambios de campos y normalización presentes, pero no registrados | Medio-alto | Validar procedimiento de normalización |
| `2026-08-27-100000_CompletePaymentComplementFiscalData` | C | Esquema y campos presentes; falta registro | Medio-alto | Verificar snapshots/fiscal_document_id |
| `2026-08-31-091000_AddWarehouseProductLabelLogo` | C | Estructura física de etiqueta/logo presente, no registrada | Medio | Confirmar si fue importada o aplicada manualmente |
| `2026-09-01-091000_EnsureGenericSupplierCostHistorySource` | C | Estructura y lógica de fuente genérica aplicada, no registrada | Medio | Revisar fuente de costo antes de reintroducir baseline |

## 18. Conclusión final

La base real auditada no es una instalación “limpia” ni una base nueva de cero. El conjunto de tablas, columnas, índices y migraciones presentes muestra una plataforma híbrida: RISE + extensiones fiscales + módulos de pagos complementarios + proveedores + almacenes, construida sobre una estructura previa y luego extendida con cambios de negocio y backfills.

La reconciliación global de B1A exige partir del esquema real, no de la expectativa de la cadena migratoria. El sistema no debe ejecutar migraciones automáticamente ni marcar versiones como aplicadas: primero se requiere:

- comparar y clasificar cada migración;
- determinar la baseline estimada para una nueva instancia;
- construir la lista de datos base obligatorios y la validación de integridad; 
- preparar la ejecución segura de B1B.

El archivo actual constituye la evidencia base para esa transición sin tocar la base de datos ni alterar el historial existente.
