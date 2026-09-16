# Auditoría de precisión de `items.rate`

## Decisión

Es seguro convertir la columna operativa de `DOUBLE` a `DECIMAL(18,6)` en esta fase. La migración `2026-08-04-160100_ConvertItemRateToExactDecimal` conserva los valores existentes y establece `NOT NULL DEFAULT 0.000000`.

## Evidencia

- La tabla física operativa es `ikontrol_items`.
- Los 2 valores existentes estaban entre 1000 y 2500, sin NULL, negativos ni valores fuera del rango de `DECIMAL(18,6)`.
- FC2 `DECIMAL(18,4)` cabe sin pérdida en el destino.
- `unformat_currency()` devuelve una cadena decimal; no hace cast a float.
- Query Builder entrega DECIMAL como cadena y la capa fiscal ya transforma `(string) $item->rate` mediante `FiscalDecimal`/`FiscalDecimalCalculator`.
- No existen índices, FKs ni expresiones generadas dependientes del tipo de `items.rate`.

## Usos revisados

- DDL: `install1/database.sql` y migraciones.
- Persistencia: `Items_model`, `Items::save()` e importador administrativo de artículos.
- UI: formulario/listado de artículos.
- Copias: cotizaciones, facturas administrativas, pedidos, contratos, propuestas y suscripciones.
- Cálculo fiscal: preparación y snapshots de conceptos.
- Helpers: `unformat_currency()` y `to_decimal_format()`.
- JSON/JS: el valor se transporta en formularios; cálculos administrativos posteriores continúan usando las convenciones heredadas.

## Corrección complementaria

El formulario de artículos utilizaba `to_decimal_format()`, que muestra como máximo dos decimales y habría redondeado `12.3456` al abrir/guardar. Ahora presenta la cadena almacenada, elimina sólo ceros finales y respeta el separador decimal configurado. No convierte el precio a float.

## Límites

- Esta fase sólo hace exacto el precio maestro `items.rate`.
- `invoice_items`, `estimate_items`, `order_items`, `proposal_items`, `contract_items` y otras tablas administrativas conservan sus tipos actuales. Muchos controladores heredados multiplican importes con operadores PHP binarios.
- Al copiar un artículo a una partida, la precisión final queda limitada por la columna y cálculo de esa partida.
- La capa fiscal mitiga esto copiando el valor a snapshots `DECIMAL(18,6)` y usando calculadores decimales.
- No se agregó un segundo precio fiscal.

## Pruebas de borde

La suite aislada comprueba almacenamiento y lectura exactos de `0`, `0.000001`, `12.3456`, `999999999999.999999` y un valor normal existente representativo.

