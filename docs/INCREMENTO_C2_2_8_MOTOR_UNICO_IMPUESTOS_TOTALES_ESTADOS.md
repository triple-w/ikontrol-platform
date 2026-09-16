# C2.2.8 — Motor único de impuestos, totales y estados comerciales

## Respaldo

`writable/backups/c2_2_8_unified_tax_totals_states_20260820_094553`, con dump de `ikontrol20_dold_preview` e inventario SHA-256.

## Semántica canónica

`fiscal_profiles.tax_pricing_mode` es la configuración existente. `tax_inclusive` interpreta el precio como total con impuestos; `tax_exclusive` lo interpreta como base y suma traslados/resta retenciones. La configuración nunca selecciona el impuesto.

La fuente por partida es: override explícito de `invoice_item`, luego configuración fiscal del producto y finalmente blocker. `taxable`, `tax_id`, `tax_id2` y `tax_id3` quedan como compatibilidad legacy y no alimentan el motor nuevo. Como las columnas de encabezado son `NOT NULL DEFAULT 0`, los documentos nuevos guardan `0` neutral; no se creó migración.

## Implementación

`CommercialItemTaxResolver` centraliza resolución y cálculo. Proposal, Estimate, Sale y `FiscalReviewPreparation` lo consumen. `FiscalDraftTaxSnapshotService` conserva la aritmética decimal durable y contempla tasas, cuotas, exentos, traslados y retenciones, incluida la extracción desde precio final. Los descuentos se aplican una vez al importe comercial antes del desglose.

Los modales iniciales ya no presentan impuestos de encabezado. La lista de ventas muestra estado comercial y pago por separado. Se normalizaron las traducciones españolas de estados.

## Compatibilidad y pendientes

No se eliminaron columnas ni cálculos legacy usados por reportes históricos. Su limpieza física requiere un incremento posterior con migración y reconciliación de reportes. Las líneas libres siguen requiriendo configuración propia y `ObjetoImp=02` sin impuestos genera blocker; no se inventa IVA.

No hubo llamadas PAC, consumo de timbres, generación XML ni PDF.
