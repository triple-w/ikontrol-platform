# Incremento C2.3.5 — flujo fiscal en la base activa

Fecha: 2026-08-13. Base canónica: `ikontrol20_dold_preview`.

## Respaldo

Se creó `writable/backups/c2_3_5_active_db_flow_20260813_150443`, con dump de la base, 378 archivos e inventario SHA-256. SHA-256 del inventario: `BC199D4BA00C6FAF1E29437D5D19DCFB2C403E4A26E8DCBC5A39A97400141A5A`.

## Correcciones

- `FiscalIssuerResolver` obtiene emisor `ready`, company, environment, vigencia y CSD/secret activo desde la base; no contiene RFC ni IDs operativos hardcodeados.
- La serie se filtra por emisor, development, activa, no eliminada y tipo de documento.
- Los unknown se evalúan por venta/draft/documento relacionado; el agregado global queda sólo como diagnóstico.
- Ambos botones Facturar usan `GET fiscal/drafts/create/{sale}` y `Drafts::create()`.
- La revisión muestra emisor, receptor, fecha, serie, Uso CFDI, método, forma y edición del concepto/impuesto en snapshot v2. No modifica `invoice_items`.
- `FiscalPreviewModeGuard` dejó de inferir preview por el nombre histórico de la base. La política depende de configuración explícita.
- Se habilitó explícitamente `fiscal.stampingEnabled=true` y `fiscal.previewMode=false` para esta integración development.

## Evidencia operativa

Venta 7, cliente 35, company 1. Se cerró por `POST invoices/close_sale/7`. El request AJAX real a `fiscal/drafts/create/7` respondió HTTP 200. Se guardó el borrador 1 por `POST fiscal/drafts`, snapshot v2, G03, PPD/99, serie A. La prefactura respondió HTTP 200.

Totales: subtotal 11,976.00; IVA 16% 1,916.16; total 13,892.16. La venta permaneció `closed`.

## Intento PAC

Los dos primeros accionamientos fueron bloqueados antes de transporte por el guard histórico y `stampingEnabled=false`; no crearon intento, reserva ni llamada PAC.

Después de normalizar ambos guardas se realizó una única llamada real development. Documento 1, folio A-1, intento 15, HTTP 200, provider code 200. La respuesta exterior fue JSON `code/message/data`; `data` fue XML CFDI directo (no JSON), longitud exterior 5,339 bytes. El parser anterior exigía JSON dentro de `data`, por lo que registró `stamp_data_invalid` y `requires_reconciliation=1`.

La evidencia cifrada contiene CFDI 4.0, TimbreFiscalDigital y UUID `ceb0ca60-4680-4298-b68b-e0638c0eeaee`. No debe reenviarse. El parser se amplió estrictamente para aceptar XML CFDI directo sólo cuando es CFDI 4.0 y contiene un TimbreFiscalDigital único; la validación semántica completa continúa en `StampedXmlValidator`.

## Estado seguro

El intento 15 queda pendiente de conciliación desde la evidencia cifrada. No se generó PDF porque el XML aún no se ha conciliado/persistido como timbre. No se hizo reintento PAC, cancelación, commit ni push.

Pruebas: C2.3.2 20/20, C2.3.3 32/32, C2.3.4 15/15 y C2.3.5 26/26.
