# P06 — Proposals, plantillas y conversión

**Estado:** implementado y probado de forma aislada; pendiente de despliegue controlado.
**Fecha:** 2026-09-29

## Contrato de presentación

`ProposalTotalsService` sigue siendo la única fuente de subtotal, descuento, total después de descuento, impuestos y total general. El resumen de modelo entrega `total_after_discount`; las vistas de total y de partidas ya no restan importes por su cuenta.

`{PROPOSAL_ITEMS}` conserva la tabla legacy con su resumen. `{PROPOSAL_ITEMS_WITH_TAXES}` produce sólo una tabla de partidas con Imagen, Producto/servicio, Cantidad, Precio sin impuestos, Impuestos y Total. El renderer agrega los placeholders `{PROPOSAL_DISCOUNT_ROW}`, `{PROPOSAL_TOTAL_AFTER_DISCOUNT_ROW}`, `{PROPOSAL_TAXES}` y `{PROPOSAL_GRAND_TOTAL}`. Las filas de descuento quedan vacías cuando su importe es cero.

Los importes de impuestos por partida sólo se muestran cuando el resolver fiscal puede obtenerlos; una propuesta legacy sin configuración fiscal conserva su salida. La selección de una visualización con impuestos no cambia el cálculo comercial o fiscal.

## Plantillas e imágenes

Cuando la columna aditiva de P01 exista, el modal permite elegir una plantilla o la opción legacy. La selección se persiste en `proposal_template_id`; una propuesta histórica con `NULL` no recibe una inferencia. Al crear una propuesta, la plantilla seleccionada se copia a `content` como snapshot visual. No se incorporó contenido, branding ni configuración de Navika.

El renderer común se usa para preview y PDF. La tabla mantiene la validación de data URI PNG/JPEG y no reintroduce la imagen decorativa lateral retirada por el layout normalizador.

## Aceptación y conversión

`ProposalToInvoiceService` conserva `tax_id` y `tax_id2` de la propuesta al crear la venta. Después de crear las partidas, calcula el total esperado mediante `ProposalTotalsService` y comprueba subtotal, descuento y total de venta a precisión monetaria antes de permitir el cierre transaccional. `ProposalAcceptanceService` conserva bloqueo, rollback, backlinks e idempotencia existentes.

La verificación sólo se aplica a una venta nueva desde proposal; no reconstruye ni modifica propuestas aceptadas, ventas existentes ni documentos fiscales históricos.

## Validación

`C:\xampp\php\php.exe tests\ProposalCanonical\run.php`: **13 aserciones aprobadas**. Cubre descuentos before/after tax, múltiples impuestos, tabla fiscal sin resumen duplicado, tabla legacy, placeholders, selección nullable, contrato de conversión, idempotencia/rollback y ausencia de PAC.

Los lints PHP de controller, helper, servicio y vistas modificados aprobaron. `tests/SharedDomainServices/run.php` conserva **16 aserciones aprobadas**.

`C:\xampp\php\php.exe tests\ProposalPdfConversion\run.php`: **27 aserciones aprobadas**. El runner define `K_PATH_CACHE` antes de cargar TCPDF, dentro de un directorio aleatorio y efímero de `writable/`, y lo elimina al terminar. No depende de `C:\xampp\tmp` ni modifica TCPDF. Valida binarios PDF, plantilla legacy y fiscal, PNG/JPEG, saltos de página, totales canónicos y ausencia de resumen duplicado en la tabla fiscal.

`C:\xampp\php\php.exe tests\ProposalFiscalConversion\run.php`: **9 aserciones aprobadas**. Crea y elimina un esquema MySQL local con nombre aleatorio, con fixtures fiscales sintéticos. Cubre `tax_id`, `tax_id2`, subtotal, descuento, impuestos, total general, descuentos `before_tax`/`after_tax`, idempotencia, rollback y vínculo Proposal→Sale. No lee, copia ni escribe datos Base/Navika y no carga PAC.

## Pendientes

- Aplicar P01 y la selección de plantilla mediante el despliegue individual aprobado, nunca con migrate global.
- P06 queda cerrado para integración de código. La comprobación posterior al despliegue debe usar una propuesta histórica aprobada sin recalcularla; P06 no toca P07, P09 ni PAC.