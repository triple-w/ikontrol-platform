# Incremento C2.1 — modelo comercial-fiscal

## Respaldo y alcance

Antes de aplicar la migración se creó el respaldo verificable:

`writable/backups/c2_1_20260729_090000`

Incluye `ikontrol_new.sql` (9,138,626 bytes), `.env`, configuración, controladores,
servicios, modelos y vistas fiscales, además de `writable/fiscal` y
`writable/fiscal-private`. `SHA256_INVENTORY.json` contiene 208 entradas. El
respaldo no expone credenciales en este documento.

C2.1 no invoca PAC, no timbra, no cancela CFDI, no consume timbres y no modifica
XML, PDF ni UUID existentes.

## Auditoría

- Venta comercial: `ikontrol_invoices` (nombre lógico CI: `invoices`).
- Documento fiscal: `ikontrol_fiscal_documents`.
- Relación anterior: `fiscal_documents.invoice_id`, uno a muchos y conservada
  temporalmente como columna legacy.
- Antes de C2.1 no existían tablas de asignación documento–venta, borradores
  C2.1, asignación borrador–venta ni relaciones entre documentos.
- Se encontraron 17 documentos fiscales: 16 con `invoice_id` y uno importado
  sin venta. El documento sin venta se conservó sin crear una venta ficticia.
- Se encontraron 8 ventas comerciales.

## Migración y tablas

La migración
`2026-08-02-130000_CreateCommercialFiscalAllocationModel` crea:

- `ikontrol_fiscal_document_sales`;
- `ikontrol_fiscal_drafts`;
- `ikontrol_fiscal_draft_sales`;
- `ikontrol_fiscal_document_relations`.

Adapta `ikontrol_invoices` agregando `cancellation_reason`; las columnas
`status`, `cancelled_at` y `cancelled_by` ya existían. Los importes de
asignación son `DECIMAL(18,6)`. Hay índices por cada extremo y estado, y una
restricción única por pareja para evitar duplicados.

La migración histórica creó 16 relaciones, sólo cuando la venta referenciada
existía. Una relación timbrada queda `active`, una cancelada queda `cancelled`
y los estados previos al timbrado quedan `legacy`. Es idempotente por la clave
única y la comprobación previa. `invoice_id` no se eliminó y queda deprecado.

## Servicios y reglas

`FiscalSaleAllocationService` centraliza resumen, saldo, validación, reserva,
conversión, liberación y consulta de ventas por documento. La fórmula es:

`venta - facturas vigentes - borradores reservados = disponible`

Los importes se procesan como enteros de millonésimas mediante
`FiscalDecimal`; no se usa `float`. Reservar y convertir usa transacciones,
relectura y `SELECT ... FOR UPDATE`. Se rechazan cero, negativos,
sobreasignación, duplicados y sumas que no coincidan con el total.

Estados calculados de la venta:

- `not_invoiced`;
- `draft`;
- `partially_invoiced`;
- `fully_invoiced`;
- `cancelled_invoices`;
- `mixed`.

Una factura cancelada conserva su asignación histórica, pero deja de consumir
saldo. Descartar un borrador cambia sus reservas a `released`; convertirlo las
cambia a `converted` y crea las asignaciones definitivas.

`FiscalSaleCancellationPolicy` impide cancelar ventas con documentos vigentes
o borradores activos. Una venta libre, o con sólo documentos cancelados, puede
cancelarse registrando usuario, fecha y motivo. El endpoint comercial usa esta
política; la eliminación física de ventas queda bloqueada. Cancelar una venta
no altera documentos ni relaciones fiscales.

`FiscalIssueDatePolicy` lee `fiscal.maxIssueAgeHours` (72 por defecto) y
`fiscal.allowFutureIssueDate` (false por defecto). Valida antigüedad y fechas
futuras sin hardcodearlas en controladores.

`FiscalPreInvoiceService` construye emisor, receptor, ventas, conceptos,
impuestos, totales, pago, uso CFDI, fecha y observaciones desde el borrador.
La vista básica muestra **PREFACTURA — DOCUMENTO SIN VALIDEZ FISCAL**. No
genera XML ni usa el servicio PDF/PAC.

## Permisos e interfaz mínima

Se registraron:

- `fiscal.sales.invoice`;
- `fiscal.drafts.view`, `.create`, `.edit`, `.discard`;
- `fiscal.invoices.view`, `.download_xml`, `.download_pdf`, `.send`, `.cancel`;
- `fiscal.advanced.view`, `.reconcile`, `.regenerate_pdf`.

El detalle comercial muestra total, facturado vigente, reservado, disponible,
estado, documentos y borradores relacionados. Las acciones dependen del
permiso. El detalle fiscal sólo entrega intentos y artefactos técnicos a quien
tiene `fiscal.advanced.view`; no basta con ocultar un botón.

No se agregaron rutas PAC ni rutas de timbrado. La prefactura queda como
servicio y vista básica para integrarse al flujo completo de C2.2.

## Pruebas

Se ejecutaron sin red los `run.php` de Increment00, 02, 03, 04, 05, 06, 07,
08, 09, A1, A2, B, C1, C11, C13 y C21. Resultado: todas aprobadas, cero
fallos. C2.1 aprobó 34 casos, incluyendo M:N, parciales, reservas, liberación,
conversión, cancelación comercial, precisión decimal, fecha, bloqueo,
migración legacy, documento sin venta, permisos y ausencia de PAC.

Los 49 archivos respaldados bajo `writable/fiscal` y
`writable/fiscal-private` compararon byte a byte por SHA-256: 49 iguales,
cero modificados y cero ausentes.

## Archivos C2.1

Creación:

- migración `CreateCommercialFiscalAllocationModel`;
- cuatro modelos de las tablas nuevas;
- `FiscalDecimal`;
- `FiscalSaleAllocationService`;
- `FiscalSaleCancellationPolicy`;
- `FiscalIssueDatePolicy`;
- `FiscalPreInvoiceService`;
- vistas `invoices/fiscal_summary.php` y `fiscal/drafts/preinvoice.php`;
- `tests/IncrementC21/run.php`;
- este documento.

Modificación:

- `app/Config/Fiscal.php`;
- `.env.example`;
- `app/Controllers/Invoices.php`;
- `app/Controllers/Roles.php`;
- `app/Controllers/Fiscal/InvoiceModule.php`;
- `app/Views/invoices/view.php`;
- `app/Views/roles/permissions.php`;
- `app/Views/fiscal/invoices/show.php`.

## Pendientes para C2.2

- formulario completo de creación/edición de borradores;
- rutas y controlador de prefactura;
- selección interactiva M:N de ventas;
- validación de fecha inmediatamente antes del futuro timbrado;
- flujo de relaciones fiscales y cancelación confirmado por PAC.

No se hizo commit ni push.
