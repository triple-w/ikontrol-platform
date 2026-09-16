# C2.2.7 — Fuente única de impuestos

La política de precio vigente está en `fiscal_profiles.tax_pricing_mode`. Define si el precio se interpreta como incluido, exclusivo o preservando total; no selecciona impuestos.

La identidad fiscal de cada impuesto se resuelve con `InvoiceItemTaxResolver`: override válido de `invoice_item`, producto maestro, o blocker. `invoice_items.taxable` y los impuestos administrativos de cabecera quedan como compatibilidad legacy y no son fuente fiscal.

`CommercialTaxBreakdownService` y `FiscalDraftTaxSnapshotService` comparten el cálculo decimal. Para precio incluido 200, cantidad 10 e IVA 002 al 16%, ambos producen base 1724.137931, traslado 275.862069 y total 2000.00. ObjetoImp 01 produce base 2000, impuesto cero y total 2000.

## Venta 11

`invoice_item=20`, producto ZAPATOS (`item_id=2`) tenía un override `001/transfer/0.160000`. Es inválido: 001 es ISR y no debe representarse como traslado IVA. El maestro está `incomplete`, sin ObjetoImp ni impuestos. Por ello el estado correcto actual es blocker; no se inventó IVA ni se alteró la venta. Una prueba transaccional con override explícito `002/transfer/0.160000` confirmó el desglose esperado y fue revertida.

## Modo tax_exclusive

Se conserva como política separada: el precio es base y los impuestos pueden aumentar el total fiscal. No se fuerza igualdad comercial/fiscal hasta definir expresamente actualización de saldo, cuentas por cobrar y consentimiento comercial en un incremento posterior.

No se hicieron llamadas PAC, movimientos de wallet, commit ni push.
