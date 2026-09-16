# HOTFIX UX2.1 — Error PAC, cierre automático y draft bajo demanda

## Diagnóstico del intento 21

El PAC rechazó de forma definitiva el CFDI A-2 con `CFDI40167`. El concepto firmado tenía cantidad 2, valor unitario 58.00 e importe 100.00. Para precios con impuestos incluidos, el snapshot separó correctamente base 100.00 e IVA 16.00, pero la materialización conservó el precio comercial bruto como `ValorUnitario`. El PAC esperaba un importe aproximado de 116.00 al multiplicar 2 × 58.00.

La corrección deriva `ValorUnitario` del importe fiscal antes de descuento dividido entre cantidad, usando `FiscalDecimal`. No se alteró el intento ni el XML histórico.

El intento fue `rejected`, HTTP 200, sin UUID, sin XML timbrado, sin conciliación y sin PDF. La reserva se liberó y el wallet quedó en 19 disponibles y 0 reservados.

## UX

El orquestador devuelve `category`, `message`, `technical_reference`, `actions`/`blockers` y `retry_allowed`. El mensaje PAC sanitizado se presenta como mensaje principal; credenciales y valores sensibles se sustituyen por `[PROTEGIDO]`.

Facturar una venta draft/open valida primero, la cierra mediante `SaleLifecycleService`, crea o reutiliza el draft v2 y continúa por el motor canónico. Un rechazo fiscal no reabre la venta.

Abrir la revisión sin draft usa `FiscalReviewPreparation`, que calcula en memoria con los resolvers, reglas de pago, política de fecha y `FiscalDraftTaxSnapshotService`. Sólo “Guardar borrador” o “Confirmar y facturar” persisten.

Las ventas cerradas conservan los bloqueos backend y dejan de renderizar agregar, editar, eliminar y reordenar partidas.
