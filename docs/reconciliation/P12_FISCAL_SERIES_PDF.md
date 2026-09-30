# P12 — Series, folios, XML y PDF fiscal

Fecha de cierre técnico: 2026-09-30. Estado: implementado y probado con MySQL temporal y adaptador PDF fake; no desplegado; cero PAC real.

## Contrato canónico

Los tres tipos documentales siguen el mismo contrato:

`fiscal_series` asigna bajo lock → `fiscal_documents` congela `fiscal_series_id`, serie y folio → el XML materializa esos valores → el XML timbrado y el PDF quedan como artefactos históricos.

Ingreso, pago y egreso validan su tipo de serie (`ingreso`, `pago`, `egreso`) y nunca derivan identidad histórica de la invoice, el payment o la serie activa actual. Las rutas de materialización detectan un documento ya vinculado antes de reservar otro folio. La nota de crédito ahora abre la transacción y bloquea su fila antes de seleccionar la serie; su anterior `FOR UPDATE` ocurría fuera de transacción.

## XML y evidencia

- Ingreso: `CfdiDraftMapper` y `CfdiXmlBuilder` leen exclusivamente `fiscal_documents`, parties, conceptos e impuestos persistidos.
- Pago: el snapshot P11 sigue siendo la fuente fiscal; al reservar folio se agrega la identidad congelada al payload final y se vuelve a materializar el Pre-XML. El artefacto ya no conserva un XML sin serie/folio.
- Egreso: `CreditNoteService` construye el XML con la serie `egreso`, folio reservado y copias persistidas del documento origen, conceptos, impuestos y UUID relacionado.

El XML preparado y timbrado continúa almacenado con hash. Un XML timbrado no se regenera desde catálogos actuales.

## PDF histórico

`FiscalPacPdfGenerationService` usa el artefacto `stamped_xml`; verifica su hash, extrae el UUID y exige coincidencia con `fiscal_document_stamps.uuid`. `FiscalPdfPrintMetadataBuilder` extrae del XML el tipo, serie, folio, fecha, emisor, receptor, moneda y totales, y rechaza discrepancias con la identidad congelada en `fiscal_documents`.

`PaymentComplementPrintDataBuilder` usa el namespace Pagos 2.0 correcto. Por ello puede leer pagos internos, externos y mixtos, incluidos documentos e impuestos DR, desde el XML persistido.

El PDF válido permanece como artefacto binario versionado. Una solicitud repetida con el mismo documento, UUID y template devuelve el artefacto existente y no llama otra vez al proveedor.

## Branding

El logo se resuelve desde la configuración vigente del emisor sólo para la presentación solicitada al generador PDF. No forma parte del XML ni modifica serie, folio, UUID, partes, conceptos, impuestos o totales. El schema actual no conserva un logo por documento; por tanto, regenerar un PDF puede usar el branding vigente, mientras la evidencia fiscal sigue idéntica.

No se incorporó branding Navika/DOLD.

## Concurrencia y rollback

`FiscalFolioService` y los tres materializadores usan transacciones y `SELECT ... FOR UPDATE` sobre la serie. Ingreso bloquea además el draft, pago el complemento y egreso la nota. Dos reservas secuenciales obtienen folios distintos; una excepción revierte `current_folio`; un retry con documento ya vinculado devuelve el mismo documento y no reserva otro folio. Los locks de timbrado P10 siguen protegiendo submit/retry por documento.

## Validación

- `php tests/FiscalSeriesPdfCanonical/run.php`: 32/32 sobre schema MySQL temporal propio, eliminado al finalizar. Cubre series I/P/E, secuencia, rollback, XML I/P/E, serie activa modificada, pago interno/externo/mixto, impuestos, UUID, UTF-8, cinco PDFs fake, logo aislado, artefactos e idempotencia.
- `php tests/PaymentComplementsCanonical/run.php`: 28/28.
- `php tests/FiscalPdfRegeneration/run.php`: 15/15.
- `php tests/FiscalEnvironment/run.php`: 16/16; sandbox y production MOCK.
- `php tests/FiscalStampingLifecycle/run.php`: 26/26.
- lint PHP y `git diff --check`: aprobados.

Los runners históricos `CreditNotes`, el tramo integrado de `CreditNoteFiscalSquare`, `IncrementC234` e `IncrementC235` dependen de datos locales que no contienen una factura acreditable o configuración fiscal completa. Fallaron antes de ejercer P12 y no se usaron como evidencia. Los cálculos puros de `CreditNoteFiscalSquare` conservaron 10 aprobaciones.

## Pendiente P13

P13 debe revisar rutas, verbos, CSRF, permisos de generación/regeneración/descarga y cualquier acceso dinámico transversal. P12 no cambia permisos, rutas ni schema y no crea migración.
