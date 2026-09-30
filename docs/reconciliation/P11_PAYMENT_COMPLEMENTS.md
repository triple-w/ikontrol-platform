# P11 — Complementos de pago canónicos

Fecha de cierre técnico: 2026-09-30. Estado: implementado y probado en un schema MySQL temporal aislado; no desplegado en Base ni Navika; cero llamadas PAC reales.

## Contrato resultante

El complemento sigue naciendo de un `invoice_payment` administrativo real. Los CFDI internos conservan su invoice, allocation y saldo administrativo. Los CFDI externos viven sólo en `payment_complement_external_documents` y `payment_complement_external_taxes`: no crean invoice, venta, allocation ni movimiento financiero.

`PaymentComplementRelatedDocumentNormalizer` entrega a snapshot y XML un único contrato de `DoctoRelacionado`, con origen trazable y los campos UUID, serie, folio, moneda, equivalencia, PPD, objeto de impuesto, parcialidad, saldos e impuestos DR. Rechaza UUID repetidos entre cualquier combinación de documentos. Internos, externos y mixtos usan el mismo materializador.

Los importes en moneda distinta de `MonedaP` se convierten mediante `EquivalenciaDR` sin reducir su escala SAT de diez decimales. Los totales del pago fiscal pueden incluir referencias externas, pero nunca escriben en el pago, allocation o ledger financiero administrativos.

## Snapshot y materialización

`PaymentComplementFiscalSnapshotService` normaliza ambos orígenes, agrega traslados, retenciones y exentos persistidos, genera el XML y guarda un snapshot `frozen`. El hash cubre el contenido completo salvo el XML, que se verifica además contra `xml_content`. Una vez congelado, cambios posteriores de catálogos, invoice o externo no alteran la representación fiscal.

El materializador Pagos 2.0 consume exclusivamente el contrato normalizado. No requiere `invoice_id`; un complemento sólo externo materializa `fiscal_documents.invoice_id = NULL`, mientras uno mixto conserva una invoice interna si existe. Serie, folio, saldos, moneda, equivalencia e impuestos DR provienen del snapshot. `MetodoDePagoDR` queda congelado para auditoría/PDF, pero no se serializa porque no es atributo de `DoctoRelacionado` en Pagos 2.0.

El timbrado congela primero el snapshot y después delega al mismo `FiscalStampingService` de P10. Por ello `transport_not_sent`, resultado incierto, conciliación, locks y wallet mantienen las reglas de P10; el ambiente continúa resolviéndose por `FiscalRuntimeContext` de P09.

## UI, rutas y permisos

La pantalla existente permite agregar, editar y retirar un CFDI externo antes del snapshot, capturar impuestos DR y combinarlo con facturas internas. Las tres mutaciones usan rutas POST explícitas, filtro CSRF y la guarda de permiso fiscal ya usada por el módulo. Después del snapshot o enlace fiscal, los datos quedan en sólo lectura. P13 revisará de forma transversal la granularidad final de permisos y el catch-all.

## Schema y validación

P11 no crea migración. Requiere aplicar de forma dirigida:

- `CreateCanonicalPaymentComplementExternalDocuments` (P04);
- `AddCanonicalFiscalStampTransportEvidence` (P10);
- las tablas fiscales, administrativas y de snapshots que ambas declaran como precondición.

`tests/PaymentComplementsCanonical/run.php` crea una base MySQL con nombre aleatorio controlado, construye sólo dependencias sintéticas, aplica P04 y P10 de forma dirigida y elimina la base al finalizar. Resultado: 28 aserciones aprobadas para interno, externo, mixto, UUID único, impuestos múltiples/exento, MXN/divisa, saldos, congelamiento, XML, rollback y ausencia de efectos administrativos.

Regresiones adicionales:

- `php tests/PaymentComplementExternalSchema/run.php --mysql`: 28/28;
- `php tests/FiscalEnvironment/run.php`: 16/16, sandbox y production MOCK;
- `php tests/FiscalStampingLifecycle/run.php`: 26/26, incluido no-envío, unknown, conciliación, locks y wallet;
- lint PHP de todos los archivos P11: aprobado.

Los runners antiguos que dependen de copias incompletas del estado local (`PaymentComplementStamping`, `PaymentComplementsFiscalUx`, `PaymentComplementCancellation`) no constituyen fixtures canónicos P11 y no se usaron como evidencia: carecen de pagos activos o documentos fiscales completos antes de llegar al código P11. `PaymentComplementsPhase3` conservó sus ocho verificaciones estáticas, pero su tramo dependiente de datos encontró el mismo fixture incompleto.

## Pendientes P12/P13

P12 debe renderizar PDF final desde el snapshot/documento persistido y cerrar presentación de serie, folio y branding sin consultar catálogos actuales. P13 debe auditar permisos de gestión separados del permiso de timbrado, rutas transversales y acceso no autorizado. Las migraciones P04 y P10 siguen pendientes de aplicación dirigida en cada instalación; no debe ejecutarse `migrate` global.
