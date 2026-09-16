# Incremento 05: política de precios e impuestos

## Auditoría inicial

RISE calcula el subtotal sumando `invoice_items.total`. El descuento global vive en `invoices.discount_amount`, `discount_amount_type`, `discount_type` y `discount_total`. Los impuestos administrativos se seleccionan en `tax_id`, `tax_id2` y `tax_id3`; los dos primeros se suman y el tercero se resta. `Invoices_model::get_invoice_total_meta()` y `update_invoice_total_meta()` son la autoridad de servidor que recalcula `invoice_subtotal`, `discount_total`, `tax`, `tax2`, `tax3` e `invoice_total`.

Los pagos permanecen en `invoice_payments`. El saldo no es una columna independiente: RISE lo obtiene como `invoice_total - SUM(invoice_payments.amount)`. Por eso un cambio compatible en `invoice_total` se refleja en listado general, ficha del cliente, cuenta por cobrar, detalle, pagos y PDFs administrativos. Los pagos existentes no se modifican ni reasignan.

Las columnas administrativas históricas usan la precisión original de RISE y parte de su cálculo utiliza operaciones PHP. Los nuevos snapshots usan DECIMAL(18,2), tasas fiscales de seis decimales y aritmética decimal centralizada. El frontend no calcula importes fiscales.

La estructura administrativa sólo representa dos impuestos globales sumados y uno restado. El ajuste se permite únicamente cuando todas las partidas comparten una combinación de tasas que pueda mapearse exactamente a esa estructura. Cuotas, combinaciones diferentes por partida o más impuestos se simulan cuando es seguro, pero bloquean la aplicación administrativa.

## Políticas por emisor

`fiscal_profiles.tax_pricing_mode` admite:

- `tax_inclusive`: los precios incluyen impuestos. Se extraen base e impuestos y se conserva el total.
- `tax_exclusive`: los importes son bases antes de impuestos. Los traslados se suman, las retenciones se restan y puede aumentar el saldo.
- `preserve_total`: decisión comercial explícita de conservar lo cobrado y desglosarlo, aunque matemáticamente pueda coincidir con `tax_inclusive`.

Un emisor nuevo propone `tax_inclusive` en la interfaz. Un emisor existente con NULL se interpreta temporalmente como `tax_inclusive`; la migración no sobrescribe perfiles ni ventas. `allow_sale_tax_pricing_override` inicia en 0. La política no forma parte del readiness fiscal del emisor.

## Modelo de preparación

`sale_fiscal_pricing_preparations` guarda el emisor, receptor, serie, política, totales administrativos, estimación fiscal, diferencia, pagos y saldo del momento, confirmación, aplicación y un JSON por partida e impuesto. Es un snapshot de cálculo, no un CFDI ni un documento fiscal.

Estados: `simulated`, `confirmation_required`, `confirmed`, `applied`, `superseded` y `cancelled`. Una nueva simulación conserva la anterior como `superseded`. Una aplicada es inmutable.

## Aritmética y redondeo

`FiscalDecimalCalculator` centraliza normalización, suma, resta, multiplicación, división, comparación con tolerancia, tasas y redondeo monetario half-up a dos decimales. Usa BCMath cuando está disponible. Incluye respaldo de enteros decimales arbitrarios implementado con strings, sin float.

El orden es: cantidad por valor unitario ya reflejado en la partida administrativa, descuento global distribuido proporcionalmente, base, impuestos por partida, agregación y total. El residuo del descuento se asigna determinísticamente a la última partida para que la suma coincida.

Para tasas incluidas se divide el neto entre `1 + traslados - retenciones`. En modo exclusivo se aplica cada tasa a la base. Exento genera cero impuesto; tasa 0% se conserva. Las cuotas sólo se calculan en modo exclusivo. Una cuota incluida o combinación no resoluble produce un error y nunca un importe inventado.

## Ventas pagadas

La simulación conserva pagos. Una venta de 100.00 pagada por 100.00 que se vuelve 116.00 muestra saldo previsto 16.00 y una advertencia. Una venta parcialmente pagada usa `total previsto - pagos existentes`.

## Ajuste controlado

`SaleTaxAdjustmentService` requiere confirmación y permiso. Bloquea la preparación y la venta con `FOR UPDATE`, comprueba que total y pagos coincidan con el snapshot, valida que el mapeo a RISE sea seguro, configura los impuestos administrativos compatibles, ejecuta el recálculo oficial de RISE y compara el resultado con la simulación. Cualquier diferencia hace rollback. Una segunda aplicación se rechaza.

No se aplica cuando la venta fue eliminada o cancelada, cambió el total o los pagos, la preparación ya se aplicó o la combinación fiscal no cabe en RISE. La operación administrativa general sigue disponible.

## Permisos

- `fiscal_sales_pricing_review`
- `fiscal_sales_pricing_apply`
- `fiscal_sales_pricing_override`

Los roles anteriores no reciben claves nuevas. El administrador global conserva acceso. El override sólo aparece si el rol lo permite y el emisor habilitó la opción; queda guardado en el snapshot y no cambia al emisor.

## Interfaz y listado

El formulario de emisores incorpora la política y ayuda visible. La revisión muestra política, comparación administrativa/fiscal, diferencia, pagado, saldos y confirmación. Los cambios de emisor, receptor, serie u override se recalculan mediante POST al servidor.

El menú de acciones de `/invoices` abre el mismo modal y muestra un badge compacto del último snapshot. No se agregó una columna a DataTables.

## Migraciones

- `2026-07-25-050000_AddIssuerTaxPricingPolicy.php`
- `2026-07-25-050100_CreateSaleFiscalPricingPreparations.php`

Son aditivas. No alteran destructivamente invoices, invoice_items, invoice_payments, taxes o items. No consumen folios ni crean emisores ficticios.

## Pruebas aisladas

Las pruebas usan la clonación temporal de `tests/Increment02/isolated_database.php`. Cubren los tres modos, IVA 16%, venta pagada, descuento, redondeo half-up, fallback sin BCMath, determinismo, snapshots superseded, confirmación, aplicación, idempotencia y conflicto por venta modificada.

## Revisión manual

### Precios incluidos

1. Configurar un emisor con “Los precios incluyen impuestos”.
2. Crear una venta con IVA.
3. Abrir `/invoices` y elegir “Revisión fiscal” en la fila.
4. Confirmar total previsto igual al actual, diferencia 0 y ausencia de ajuste.

### Impuestos agregados

1. Configurar “Los impuestos se agregan al solicitar factura”.
2. Crear una venta compatible de 100.00 con IVA 16%.
3. Confirmar base 100.00, impuesto 16.00, total 116.00 y diferencia 16.00.
4. Marcar la confirmación y pulsar “Aplicar impuestos a la venta”.
5. Confirmar nuevo total y saldo en listado, detalle y cliente.

### Conservar total

1. Configurar “Conservar el total y desglosar impuestos”.
2. Revisar una venta de 100.00 con IVA 16%.
3. Confirmar base aproximada 86.21, impuesto 13.79, total 100.00 y diferencia 0.

### Venta pagada

1. Crear y pagar una venta de 100.00.
2. Revisarla con política exclusiva.
3. Confirmar total 116.00 y saldo previsto 16.00 antes de aplicar.

## Limitaciones

No se persiste documento fiscal ni se consume serie o folio. No se implementó PAC, XML, CFDI, CSD, timbrado, cancelación, nota de crédito fiscal ni complemento de pago. El ajuste administrativo complejo queda bloqueado si la combinación por concepto no puede representarse exactamente con los tres campos de impuestos de RISE.
