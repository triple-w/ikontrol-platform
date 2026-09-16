# Incremento C2.3.3 — Flujo de revisión fiscal

> Nota C2.3.4: las referencias a `ikontrol_new`, venta 59, borrador 5,
> documento 25 e intento 22 son evidencia histórica. La base activa canónica
> de esta instancia es `ikontrol20_dold_preview`; esos registros no se copiaron.

## Respaldo

`writable/backups/c2_3_3_fiscal_review_flow_20260813_132204` contiene el dump de `ikontrol_new` (9,312,253 bytes), código en alcance y `SHA256SUMS.txt`.

## 404 y flujo unificado

Existían dos entradas visibles: el flujo moderno `fiscal/drafts/create/{sale}` → `Drafts::create` con snapshot v2, y la revisión legacy `fiscal/invoices/review/{sale}` → `InvoiceReview::show`. Además, Facturar se mostraba con `fiscal.sales.invoice`, pero `Drafts::create` exigía sólo `fiscal.drafts.create`; ese rol llegaba a denegación/404.

Todas las acciones visibles apuntan ahora a `fiscal/drafts/create/{sale}`. La entrada acepta ambos permisos equivalentes y conserva la verificación de acceso. La ruta legacy queda sólo por compatibilidad histórica, sin enlace visible. No se creó alias ni segundo flujo.

## Venta, conceptos y cabecera

La venta cerrada permanece inmutable. La revisión no enlaza edición comercial. **Editar datos fiscales** modifica únicamente el snapshot: descripción fiscal, clave SAT, clave unidad, unidad y objeto de impuesto. Cantidad, precio, descuento y total comercial son sólo lectura.

`FiscalDraftWorkflowService` reemplaza conceptos e impuestos del borrador editable, regenera `fiscal_snapshot` y `fiscal_draft_item_taxes`, recalcula con `FiscalDecimal` y conserva `snapshot_version=2`. Reutiliza el borrador operacional vigente con la misma selección.

**Datos del CFDI** muestra emisor, serie, receptor, Uso CFDI, Método, Forma, moneda, tipo de cambio, régimen y CP. El Uso maestro sólo precarga. El esquema no guarda defaults maestros de Método/Forma; `CfdiPaymentRuleService` precarga PPD/99 cuando no hay pago. Los cambios no actualizan el cliente.

`FiscalIssueDatePolicy::constraints()` expone mínimo, máximo, zona `America/Mexico_City` y mensaje; backend vuelve a validar con la misma política.

## Precios incluidos, borrador y prefactura

El resultado sospechoso pertenecía a la preparación legacy. El motor canónico, para total 116.00 con IVA 16%, produjo base 100.00, traslado 002 Tasa 0.160000 por 16.00 y total 116.00. `FiscalDraftTaxSnapshotService` separa base/impuesto conservando el total.

La revisión web guarda inicialmente como draft; el detalle permite marcar ready después de revisar la prefactura. Ésta lee fecha, Uso, Método, Forma, partes, impuestos y totales del borrador.

## Prueba PAC

Venta 59: `closed`, `not_paid`, total 116.00. Borrador 5: snapshot v2, TEST, G03, PPD/99, base 100.00 e IVA 16.00.

La única llamada PAC development creó intento 22 y documento 25. Hubo HTTP 200, pero la respuesta no cumplió el contrato JSON esperado: `response_invalid`, `requires_reconciliation=1`, documento `stamp_status_unknown`. No se reintentó ni liberó la reserva. No hay UUID, XML timbrado ni PDF; WSTools33 no se ejecuta sin XML válido.

## Pruebas

IncrementC233: 32/32. IncrementC23 limpio: 47/47. La corrida histórica obtuvo 29 suites aprobadas y cinco fallos: expectativas legacy actualizadas en Increment05/IncrementC22 y condiciones preexistentes de ambiente/fixtures en DoldPreview y ExceptionHandlerMissingArgs; C23 fue reejecutada limpia y aprobó. Las pruebas automatizadas no llaman al PAC.

No commit. No push.
