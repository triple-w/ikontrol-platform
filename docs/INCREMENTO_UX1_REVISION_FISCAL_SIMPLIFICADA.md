# Incremento UX1 — Revisión fiscal simplificada

La entrada desde venta renderiza `fiscal/drafts/review.php`. La vista histórica `form.php` queda reservada para edición avanzada. `FiscalReviewPresenter` traduce la validación completa del snapshot v2 a `review_needed` o `ready`, checks, resumen, productos y blockers accionables.

El flujo normal trabaja exclusivamente con la venta solicitada. No presenta multiventa, CSD, asignaciones ni parámetros técnicos de impuestos. Mantiene visibles Fecha CFDI, Uso CFDI, Método y Forma; moneda MXN es informativa y condiciones/observaciones están bajo “Más opciones”.

Guardar utiliza el workflow existente, mantiene el draft físico y rerenderiza el mismo modal. “Vista previa” conserva la prefactura opcional. “Confirmar y facturar” permanece deshabilitado hasta UX2 y no se realizaron llamadas PAC.

La venta 8 validó subtotal 116.00, IVA 18.56 y total 134.56. El wallet permaneció 19/0, con un único intento PAC histórico y cinco movimientos preexistentes.
