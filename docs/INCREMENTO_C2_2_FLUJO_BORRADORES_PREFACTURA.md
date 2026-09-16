# Incremento C2.2 — flujo de borradores y prefactura

## Respaldo

Respaldo previo verificable:

`writable/backups/c2_2_20260729_122003`

- Base `ikontrol_new`: 9,143,300 bytes.
- SHA-256 SQL: `B5A915B864649D26F2DAB4EF17D9A0775D1ED46B34DBF9E7181E68A0F3D453C1`.
- Inventario: 362 archivos.
- Incluye `.env`, configuración, controladores, servicios y modelos fiscales,
  vistas comerciales/fiscales y almacenamiento fiscal. `app/Views/layouts` no
  existe en este proyecto y por ello no hubo contenido que copiar.
- Ninguna credencial se incluye en este documento.

## Auditoría y compatibilidad

Se reutilizan:

- `invoices` e `invoice_items` como venta y partidas comerciales;
- `fiscal_profiles` como fuente canónica de emisor y receptor;
- catálogos SAT, `fiscal_series`, configuración de artículos e impuestos;
- `FiscalSaleAllocationService`, `FiscalIssueDatePolicy`,
  `FiscalPreInvoiceService` y permisos de C2.1.

No se agregaron campos fiscales duplicados a `clients`. El receptor se actualiza
en `fiscal_profiles` sólo después de una confirmación explícita y se auditan
únicamente los nombres de los campos modificados.

La migración `2026-08-03-140000_ExtendFiscalDraftWorkflow` agrega a
`fiscal_drafts` la referencia al perfil receptor y serie, condiciones,
observaciones y datos de descarte/listo. Crea:

- `fiscal_draft_items`: snapshot trazable por borrador, venta y partida;
- `fiscal_draft_audit`: eventos sin payloads fiscales completos.

## Rutas y controlador

Controlador: `App\Controllers\Fiscal\Drafts`.

- `GET fiscal/drafts`
- `POST fiscal/drafts/list`
- `GET fiscal/drafts/create/{sale}`
- `POST fiscal/drafts`
- `GET fiscal/drafts/{draft}`
- `GET fiscal/drafts/{draft}/edit`
- `POST fiscal/drafts/{draft}`
- `POST fiscal/drafts/{draft}/discard`
- `GET fiscal/drafts/{draft}/preinvoice`
- `POST fiscal/receivers/{profile}`

Todas las mutaciones usan CSRF. El controlador valida permisos y vuelve a
comprobar acceso a cada venta relacionada con `can_view_invoices`, evitando
IDOR por identificadores de borrador, venta o receptor.

## Flujo comercial

En listado y detalle de ventas aparece **Facturar** sólo con
`fiscal.sales.invoice`, venta no cancelada, saldo positivo y sin intento de
timbrado pendiente o incierto. Una venta totalmente reservada muestra el
borrador activo. Una venta parcialmente facturada mantiene disponible el saldo
restante.

La revisión muestra venta, emisor, receptor, comprobante, fecha y conceptos.
Puede agrupar ventas con el mismo cliente, empresa/emisor y moneda. La cantidad
por partida puede ser parcial. Los importes del navegador no son fuente de
verdad.

`FiscalDraftWorkflowService` vuelve a cargar partidas, precios, totales,
descuentos, impuestos, perfiles, serie y saldos. Calcula con
`FiscalDecimal`, guarda snapshots y reserva mediante transacción y
`SELECT ... FOR UPDATE`. Si el saldo cambió, rechaza el guardado con un mensaje
amigable. Al editar, reemplaza las reservas propias y libera las ventas
retiradas.

## Validaciones y estados

`FiscalDraftValidationService` devuelve `valid`, `errors` y `warnings` con
campo, sección, código interno y mensaje español. Valida emisor, CSD vigente
sin descifrarlo, receptor, conceptos, datos fiscales de partidas, pago, moneda,
tipo de cambio, fecha y asignaciones.

Errores fiscales incompletos permiten guardar en `draft`; corrupción decimal,
fecha inválida, ausencia de conceptos, cero, negativos, venta cancelada o
saldo excedido bloquean. Un borrador completo queda `ready`.

Presentación de estados:

- Incompleto;
- Listo para facturar;
- En preparación;
- Facturado;
- Descartado;
- Expirado;
- Error.

La fecha utiliza `fiscal.maxIssueAgeHours` y
`fiscal.allowFutureIssueDate`, con zona `America/Mexico_City`. La interfaz lee
el límite efectivo; actualmente 72 horas.

## Prefactura y navegación

La prefactura imprimible incluye logotipo administrativo cuando existe,
emisor, receptor, serie provisional, fecha, ventas, conceptos, impuestos,
totales, pago, uso CFDI y observaciones. Muestra:

**PREFACTURA — DOCUMENTO SIN VALIDEZ FISCAL**

No contiene UUID, sello, TimbreFiscalDigital, QR fiscal ni operación PDF/PAC.
Desde ventas se navega a borradores, prefactura y facturas; desde borradores se
navega a una o varias ventas.

Descartar conserva borrador, payload, conceptos y auditoría; cambia estado y
libera todas sus reservas en transacción.

## Pantalla normal y herramientas avanzadas

La pantalla normal de facturas muestra serie/folio, fecha, cliente, ventas
relacionadas, UUID abreviado, total, estado y acciones comerciales. Generación
o regeneración PDF, proveedor, plantilla, intentos y artefactos se renderizan
sólo con `fiscal.advanced.view` y permisos técnicos específicos.

## Auditoría

Eventos:

- `draft_created`
- `draft_updated`
- `draft_marked_ready`
- `draft_discarded`
- `allocation_reserved`
- `allocation_changed`
- `allocation_released`
- `preinvoice_viewed`
- `receiver_updated`

Se guarda usuario, fecha, borrador, venta y resumen mínimo; no se escriben XML,
PDF, credenciales ni perfiles fiscales completos en logs.

## Prueba visual controlada

Se utilizó la venta existente `INVOICE #1`:

1. Se creó el borrador C2.2 `#1`, estado `ready`.
2. Se reservó `928.000000`.
3. La prefactura devolvió las leyendas obligatorias.
4. Se descartó el borrador conservando historial.
5. El saldo disponible regresó a `928.000000`.
6. Intentos de timbrado: 6 antes y 6 después.
7. Hash agregado de UUID y artefactos: idéntico antes/después.

No se pulsó ni invocó ninguna operación PAC.

## Pruebas e integridad

C2.2 ejecuta 47 casos sin red. También se ejecutan Increment00, 02, 03, 04,
05, 06, 07, 08, 09, A1, A2, B, C1, C11, C13 y C21.

Los 49 archivos existentes bajo `writable/fiscal` y
`writable/fiscal-private` coinciden con el respaldo: 49 iguales, cero
modificados y cero ausentes.

- Llamadas PAC: 0.
- Timbres consumidos: 0.
- XML existentes modificados: 0.
- PDF existentes modificados: 0.
- UUID existentes modificados: 0.

## Pendientes para C2.3

- transición operativa de `ready` hacia preparación de timbrado;
- segunda validación de fecha inmediatamente antes de timbrar;
- sellado/timbrado bajo autorización específica;
- envío fiscal y cancelación real;
- políticas de selección por cantidades previamente facturadas a nivel partida.

No se hizo commit ni push.
