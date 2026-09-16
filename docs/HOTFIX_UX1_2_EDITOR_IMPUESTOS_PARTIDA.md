# HOTFIX UX1.2

La persistencia canónica continúa siendo `invoice_items.fiscal_override_json`. Contiene ClaveProdServ, ClaveUnidad, unidad, ObjetoImp, descripción y la colección estructurada de impuestos (`tax_type`, `tax_code`, `factor_type`, `rate_or_quota`). El snapshot v2 se genera después y no se duplica como interfaz de edición.

El modal de Venta usa catálogos SAT para ObjetoImp e impuestos, permite agregar y eliminar filas, y soporta traslado, retención, Tasa, Cuota y Exento. ObjetoImp 01 elimina impuestos; ObjetoImp 02 exige al menos uno. El servidor revalida catálogos, combinaciones y decimales.

El override se activa al cambiar datos fiscales y no modifica el maestro. Sólo admin o `fiscal_items_manage` ve la opción de guardar como predeterminado. Esa operación usa `ProductFiscalDefaultUpdateService` dentro de la misma transacción que la partida.

La revisión resuelve override → maestro → blocker y sólo ofrece Editar partida. No hubo PAC, XML/PDF ni movimientos de timbres.
