# C2.2.9 — Homologación de partidas e impuestos

Los modales reales son `proposals/item_modal_form.php`, `estimates/item_modal_form.php` e `invoices/item_modal_form.php`. Los tres reutilizan `_commercial_margin_fields.php` y `_fiscal_item_fields.php`.

Proposal y Estimate carecían de persistencia para override. La migración `2026-08-21-090500_AddFiscalOverrideToCommercialItems` agrega únicamente `fiscal_override_json LONGTEXT NULL`, con el mismo contrato de Venta. Las conversiones copian el JSON y neutralizan impuestos legacy de encabezado.

`CommercialItemTaxResolver` continúa como motor autoritativo. `CommercialItemTaxDisplayService` presenta precio unitario base, múltiples impuestos y total en las tres tablas. La UI usa dos decimales; el backend calcula con `FiscalDecimal`.

Incluido, 58 × 2 con IVA 16%: base unitaria 50, subtotal 100, IVA 16, total 116. Excluido: base unitaria 58, subtotal 116, IVA 18.56, total 134.56. ObjetoImp 01 muestra sin impuesto.

No hubo PAC, timbrado, XML/PDF ni movimientos de timbres.
