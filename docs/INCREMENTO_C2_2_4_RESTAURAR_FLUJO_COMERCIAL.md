# Hotfix C2.2.4 — restauración del flujo comercial

Fecha: 2026-08-13. Base: `ikontrol20_dold_preview`.

## Causa

`SaleLifecycleService::canEdit()` sólo autorizaba cambios estructurales cuando `commercial_status=draft`. Para `open` exigía `structural=false`; por ello `Invoices::item_modal_form()`, `save_item()` y `delete_item()` devolvían “La venta está cerrada para cambios de partidas” para ventas realmente abiertas. El `status` de pago no era la causa, aunque visualmente agravaba la confusión.

La regla corregida permite edición comercial completa en `draft` y `open`. `closed` y `cancelled` permanecen fuera de los estados editables. El cierre sólo ocurre mediante `Invoices::close_sale()` y `SaleLifecycleService::close()`.

También se corrigió `to_decimal_format()` para no pasar strings vacíos a `number_format()` en PHP 8.2; ese error impedía abrir el modal de una partida nueva de cotización.

## Cotizaciones y propuestas

Las cotizaciones son `estimates`; las propuestas son `proposals`, entidades distintas con convertidores propios que convergen en `invoices`.

Las cotizaciones aceptadas se convierten mediante `EstimateAcceptanceService` y quedan `converted`, con `converted_sale_id/at/by`. La venta creada queda `commercial_status=open`. La conversión repetida reutiliza la venta existente.

`ProposalAcceptanceService` ahora puede recuperar propuestas ya aceptadas sin backlink, además de aceptar `draft/sent`. Mantiene bloqueo transaccional, comprueba vínculos y evita duplicados. La propuesta aceptada 1 creó la venta 9; una segunda invocación devolvió `invoice_action=existing`.

## Evidencia HTTP real

- Venta directa 8: `POST invoices/item_modal_form`, HTTP 200; permitió agregar partida 10, editar cantidad, eliminarla, crear la partida 12 y cerrar explícitamente. Después del cierre, el mismo endpoint respondió el bloqueo esperado.
- Cotización 3: creada por `POST estimates/save`; el modal `estimates/item_modal_form` respondió HTTP 200, se agregó la partida 2, se aceptó y creó la venta 10. La repetición devolvió la misma venta 10.
- Propuesta draft 2: modal de partidas HTTP 200. Propuesta aceptada legacy 1: conversión creó la venta 9; repetición reutilizó la 9.

No se llamó al PAC, no se timbró, no se tocaron XML/PDF y no se consumieron timbres. El CFDI histórico A-1 permanece intacto.

Pruebas: C2.2.4 20/20; C2.3.2 20/20; C2.3.3 32/32; C2.3.4 15/15; C2.3.5 27/27; C2.3.6 23/23.
