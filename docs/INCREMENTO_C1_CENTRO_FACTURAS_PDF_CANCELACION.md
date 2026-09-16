# Incremento C1 — Centro de facturas, PDF obligatorio y cancelación fiscal

> Continuidad C1.1: `generarPDF` se implementa como operación separada del
> timbrado. Recuperar una representación no altera UUID, XML ni intentos.

## Objetivo

C1 establece el centro de documentos fiscales y diferencia claramente venta administrativa, preparación interna, timbrado, representación impresa y cancelación fiscal.

Respaldo verificado: `C:\Users\iKontrol\Backups\ikontrol-C1-20260724-223712`.

## PDF obligatorio

Para estado operativo **Timbrada** deben existir XML timbrado, UUID y un `pac_pdf` Base64 válido. El PDF se valida con decodificación estricta, cabecera `%PDF-`, cierre `%%EOF`, límite de tamaño y SHA-256. Se guarda en `fiscal_document_binary_artifacts`; no se crea archivo PDF permanente.

Preview y descarga usan endpoints autenticados, decodifican en memoria y vuelven a comprobar tamaño/hash. El XML sigue siendo la autoridad fiscal.

Si falta PDF se proyecta `stamped_pdf_pending`; si es inválido, `stamped_pdf_error`. Ambos bloquean el retimbrado. No existe recuperación real de PDF porque las fuentes disponibles no documentan una operación exclusiva; véase `docs/PAC_PDF_CAPABILITIES.md`.

## Centro de facturas

Ruta: `GET fiscal/invoices`.

El listado consulta documentos, receptor, timbre y última cancelación sin seleccionar XML ni `content_base64`. Incluye serie/folio, receptor/RFC, venta, fechas, importes, UUID, estado fiscal, PDF, cancelación y acciones. Permite búsqueda por serie/folio, UUID, RFC o receptor, además de filtros de estado, PDF y venta.

## Estados visibles

`draft`, `processing`, `signed`, `stamped`, `stamped_pdf_pending`, `stamped_pdf_error`, `correctable_error`, `unknown`, `cancellation_pending`, `cancelled`, `cancellation_rejected`, `cancelled_internal` y `superseded`.

La proyección es de sólo lectura. Una cancelación interna sólo aplica antes del timbrado y no llama adaptador. Una cancelación fiscal requiere UUID, XML timbrado y timbre persistido.

## Cancelación fiscal

La migración `2026-07-31-110000_CreateFiscalCancellationWorkflow` crea:

- `fiscal_cancellation_requests`;
- `fiscal_cancellation_attempts`;
- `fiscal_cancellation_artifacts`.

La solicitud y el intento se confirman antes de invocar `FakeFiscalCancellationAdapter`. El adaptador se ejecuta fuera de la transacción. Existe lock nombrado por documento e idempotency key única.

Escenarios fake: accepted, pending, rejected, timeout_unknown, transport_not_sent y persistence_error. Sólo accepted proyecta `cancelled`. Unknown exige conciliación y no se reenvía. El acuse fake se almacena Base64 con MIME, tamaño y SHA-256; XML/PDF/UUID originales permanecen intactos.

No se implementó un adaptador real de cancelación porque no existe una operación contractual disponible en el proyecto.

## Permisos y rutas

Permisos: `fiscal_invoices_view`, `fiscal_invoices_download_xml`, `fiscal_invoices_download_pdf`, `fiscal_invoices_cancel`, `fiscal_invoices_view_cancellation` y `fiscal_invoices_reconcile_cancellation`.

La cancelación usa POST, CSRF, permiso, lock e idempotencia. El acuse se descarga por un endpoint autenticado.

## Seguridad y riesgos

- Fake PAC y fake cancellation exclusivamente.
- `fiscal.allowRealPac=false`; producción bloqueada.
- Sin secretos, XML o Base64 en listados/logs.
- No se modifica la venta administrativa al cancelar CFDI.
- La recuperación real de PDF y la cancelación PAC quedan pendientes de documentación oficial.
- Los documentos 9, 10, 12 y 13 y el intento 11 no se utilizan como fixtures destructivos.

No hubo llamadas externas, commit ni push.

## Corrección del fixture PDF falso

La primera versión de `FakePacAdapter::fixturePdf()` concatenaba manualmente un
archivo de 198 bytes. Aunque contenía `%PDF-` y `%%EOF`, su catálogo no
referenciaba un árbol `/Pages`, no había `/Page`, `MediaBox`, recursos ni una
tabla `xref` consistente. La validación original comprobaba solamente el
contenedor Base64, tamaño, cabecera, cierre y SHA-256; por ello el artefacto
superaba la validación aunque un visor no pudiera renderizarlo.

El fixture ahora se construye con la biblioteca TCPDF ya incluida en RISE. Usa
identificadores y timestamps fijos para ser determinista, no se comprime y
genera una página A4 visible con las leyendas `FACTURA DE PRUEBA` y
`FAKE PAC - SIN VALIDEZ FISCAL`. No contiene datos fiscales reales.

`PacPdfValidator` conserva las comprobaciones anteriores y adicionalmente abre
el documento con `TCPDF_PARSER`. Valida el `Catalog`, la referencia `Pages`, el
árbol de páginas, `Count`, al menos una página y la presencia efectiva o
heredada de `MediaBox` y `Resources`, además de `Contents`. Un PDF con cabecera
y EOF pero sin esta estructura produce `PDF_STRUCTURE_INVALID`; el timbrado y
UUID se conservan, el PDF queda en error y no se permite retimbrar.

Los endpoints de preview y descarga vuelven a validar Base64, estructura,
tamaño y hash antes de responder. Entregan bytes desde la posición cero con
`Content-Type: application/pdf`, disposición `inline` o `attachment` y
`Content-Length` exacto. No crean archivos PDF permanentes.

### Reparación controlada del documento 14

Se ejecutó `tools/repair_fake_pdf_fixture.php --document=14 --apply`, permitido
únicamente cuando el timbre pertenece al proveedor `fake`, ambiente `local`,
el adaptador configurado es falso y las llamadas reales están deshabilitadas.

- Artefacto anterior: ID 1, 198 bytes, conservado como
  `pac_pdf_replaced/invalid_structure`.
- Artefacto nuevo: ID 2, 60,729 bytes, una página, validación estructural
  correcta.
- UUID antes/después:
  `46B21033-3738-4C50-8808-9012736684F0`.
- SHA-256 del XML antes/después:
  `7f127588fb48bc4b6defe620a57d78b5a613239a1075bb4ec9f32d8048caaa29`.
- Intento antes/después: ID 14; conteo total 1.
- Auditoría: `fake_pdf_fixture_replaced`.

La reparación no llamó al adaptador, no retimbró, no modificó el XML ni UUID y
no afectó documentos 9, 10, 12 o 13 ni el intento 11.
