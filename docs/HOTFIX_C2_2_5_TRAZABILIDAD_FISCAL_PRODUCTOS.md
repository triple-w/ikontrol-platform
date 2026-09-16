# HOTFIX UX1.1 / C2.2.5

La auditoría de `ikontrol20_dold_preview` confirmó que `invoice_items`, `estimate_items` y `proposal_items` conservan `item_id`. Los conversores copian esa referencia y ahora rechazan referencias positivas inexistentes. Los controladores preservan `item_id` al editar aunque el autocomplete no lo reenvíe.

El caso observado no perdió trazabilidad: Proposal 1 contiene ZAPATOS (`item_id=2`) y bandera (`item_id=5`), y la venta 9 conserva ambos IDs. Sus configuraciones maestras están `incomplete`: tienen ClaveProdServ y ClaveUnidad, pero carecen de ObjetoImp e impuestos. Por eso no son configuraciones fiscales utilizables.

`ProductFiscalConfigurationResolver` es la fuente inicial canónica. Sólo entrega configuración completa `active/ready`; nunca inventa IVA ni claves. `FiscalDraftWorkflowService` incorpora claves e impuestos al snapshot inicial. Un snapshot completo existente se conserva durable; uno incompleto puede regenerarse desde el maestro una vez corregido.

La revisión normal oculta controles fiscales técnicos. Para productos ligados al catálogo muestra nombre y faltantes e indica resolverlos en el catálogo. Las líneas con `item_id=0` se identifican como conceptos libres. Los overrides técnicos permanecen en el flujo avanzado.

No hubo PAC, timbrado, consumo de timbres ni cambios en XML/PDF.
