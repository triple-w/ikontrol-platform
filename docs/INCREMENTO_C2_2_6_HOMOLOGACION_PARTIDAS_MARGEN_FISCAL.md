# Incremento C2.2.6

Proposal sólo mostraba producto, descripción, cantidad, unidad y precio. Estimate añadía costo y “Utilidad sobre costo (%)”, calculada como markup con `cost * (1 + percentage/100)`. Venta no tenía costo, margen ni configuración fiscal de partida.

Los tres modales reutilizan `_commercial_margin_fields.php`. La autoridad matemática es `CommercialMarginService`, basada en `FiscalDecimal`: `price = cost / (1 - margin/100)` y `margin = ((price-cost)/price)*100`. La previsualización JavaScript evita ciclos; el backend vuelve a validar y recalcular. Margen 100 o superior se rechaza.

La migración aditiva incorpora costo al catálogo y a Proposal/Venta, margen a Proposal/Venta, y `fiscal_override_json` a `invoice_items`. El override es explícito, no modifica `item_fiscal_settings` y tiene prioridad sobre el maestro. El snapshot v2 permanece como evidencia durable posterior, no como interfaz de captura.

La venta muestra un bloque fiscal derivado del producto u override. Las líneas libres conservan `item_id=0` y requieren datos propios. La revisión fiscal sólo muestra blocker y “Editar partida”; no presenta inputs técnicos normales.

No hubo PAC, timbrado, XML/PDF ni movimientos de timbres.
