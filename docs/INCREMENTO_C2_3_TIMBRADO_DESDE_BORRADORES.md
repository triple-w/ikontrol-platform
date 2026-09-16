# Incremento C2.3 — Timbrado desde borradores con snapshot fiscal v2

## Resultado

C2.3 conecta el flujo visible `Borrador ready → Facturar` con la infraestructura durable de CFDI ya existente. La construcción fiscal parte exclusivamente de:

```php
FiscalDraftSnapshotService::getCompleteFiscalSnapshot($draftId)
```

No se reconstruyen conceptos, partes, impuestos ni receptor desde productos, partidas comerciales, configuraciones fiscales mutables o catálogos SAT.

## Respaldo

- Ruta: `writable/backups/c2_3_resume_20260729_233248`
- SQL: `ikontrol_new.sql`
- Tamaño: 9,151,799 bytes
- SHA-256 SQL: `7100d5fb2f15b022d808efe0fe581b41698bcaf42b5d1efe87f23046ff6f9cec`
- Inventario: `SHA256_INVENTORY.json`
- Archivos inventariados: 229

## Bloqueo anterior e integración C2.2.3

C2.2.3 incorporó `fiscal_draft_item_taxes`, `snapshot_version=2`,
`requires_snapshot_refresh`, `snapshot_completed_at` y la lectura central del
snapshot. C2.3 añade un preflight que bloquea snapshots v1, snapshots pendientes
de refresco, conceptos gravables sin impuestos, totales inconsistentes,
asignaciones no reservadas, ventas canceladas, fechas inválidas y borradores con
documento principal previo.

Durante la integración se detectó que el snapshot del emisor conservaba sólo el
identificador mutable de régimen. El guardado normal ahora congela también
`issuer_snapshot.tax_regime_code`. No se consulta el catálogo para reconstruir el
CFDI.

## Servicios

- `FiscalDraftStampingPreflightService`: valida el snapshot y condiciones
  operativas sin reservar folio ni crear intentos.
- `FiscalDocumentFromDraftSnapshotService`: materializa las tablas
  `fiscal_documents`, partes, conceptos, impuestos y totales exclusivamente
  desde snapshot v2.
- `FiscalDraftStampingService`: coordina bloqueo, materialización, Pre-XML,
  sellado, intento durable, adaptador, persistencia y conversión de
  asignaciones.
- Servicios reutilizados: `CfdiPreXmlArtifactService`, `CfdiSigningService`,
  `FiscalStampingService` y `FiscalSaleAllocationService`.

## Documento durable e idempotencia

La migración `2026-08-04-150300_PrepareFiscalDraftStamping` agrega:

- `fiscal_documents.source_draft_id`, único;
- `fiscal_drafts.fiscal_document_id`, único.

El orquestador usa `GET_LOCK` por borrador, bloqueo de fila para borrador y
serie, y la idempotencia existente por hash del XML firmado, proveedor,
ambiente y operación. La llamada al adaptador ocurre fuera de la transacción de
materialización.

## CFDI e impuestos

El adaptador entre snapshot y documento durable traduce explícitamente:

- `transfer` → `transferred` → `cfdi:Traslado`;
- `withholding` → `withheld` → `cfdi:Retencion`.

Se conservan por concepto `tax_code`, `factor_type`, `rate_or_quota`,
`tax_base`, `tax_amount` e `is_exempt`. `Exento` omite tasa e importe; tasa
cero se mantiene como `0.000000`. Los agrupados globales se construyen por
código, naturaleza, factor y tasa/cuota. Toda aritmética usa `FiscalDecimal`.

La ecuación validada antes de materializar es:

```text
subtotal - descuento + traslados - retenciones = total
```

## Sellado, intento durable y estados

El documento se crea `locked`, genera Pre-XML, se sella con el CSD activo y sólo
entonces se entrega al `FiscalStampingService`.

- Error local previo al transporte: no invoca adaptador; borrador vuelve
  `ready` y conserva reservas.
- Rechazo conocido/no enviado: no convierte asignaciones.
- Resultado incierto: documento queda sujeto a conciliación y borrador
  `blocked`; no se reintenta automáticamente.
- Éxito: se persisten XML timbrado, UUID, timbre y PDF si el PAC fake entrega
  uno válido; después se convierten las asignaciones y el borrador pasa a
  `stamped`.
- Fallo de PDF posterior: no revierte XML, UUID, timbre, documento ni
  asignaciones.

## Ruta, permiso e interfaz

- `POST fiscal/drafts/{draft}/stamp`
- Permiso: `fiscal.invoices.stamp`
- CSRF obligatorio.

El detalle muestra `Facturar` sólo para un borrador `ready`, con permiso y
preflight aprobado. La confirmación presenta serie, fecha, total y número de
ventas relacionadas, sin datos técnicos.

## Prueba funcional fake

La prueba aislada C2.3 creó una venta y un borrador mediante los servicios
normales del dominio de borradores. Resultado:

- snapshot v2 e impuesto durable;
- documento fiscal creado desde snapshot;
- Pre-XML y XML sellado;
- una invocación a `FakePacAdapter`;
- XML timbrado y UUID fake persistidos;
- PDF fake válido persistido;
- asignaciones convertidas;
- borrador `stamped`;
- cero conexiones externas;
- cero timbres reales.

## Candidato para prueba real manual posterior

Se creó mediante `InvoiceCreationService` y `FiscalDraftWorkflowService`, sin
SQL manual:

- venta: 56;
- borrador: 2;
- estado: `ready`;
- serie: A;
- total: 5800.000000;
- snapshot_version: 2;
- requires_snapshot_refresh: 0;
- impuestos persistidos: 1;
- CSD vigente: sí;
- SoapClient CLI: sí;
- WSDL configurado: sí;
- `fiscal.allowRealPac=false`;
- `fiscal.pacAdapter=fake`.

El borrador 2 no fue timbrado y no tiene documento fiscal asociado. No está
listo para una prueba PAC real mientras `fiscal.allowRealPac` continúe en
`false`; esa protección es intencional.

## Integridad histórica

Antes y después de preparar el candidato:

- UUID: 5 → 5; hash agregado sin cambios;
- artefactos XML: 31 → 31; hash agregado sin cambios;
- PDF: 6 → 6; hash agregado sin cambios;
- intentos de timbrado: 6 → 6;
- timbres: 5 → 5.

No se modificaron XML, PDF ni UUID históricos.

## Pruebas

`tests/IncrementC23/run.php`: 47 aprobadas, 0 fallidas.

La regresión completa ejecutó 21 suites (`Increment00` a `IncrementC23`, con
A1, A2, B, C1, C11, C13, C21, C22, C221, C222 y C223): 700 comprobaciones
aprobadas, 0 fallidas.

## Seguridad y alcance

- PAC real: 0 llamadas.
- Timbres reales consumidos: 0.
- Cancelaciones: 0.
- No se imprimieron secretos.
- No se hizo commit.
- No se hizo push.
