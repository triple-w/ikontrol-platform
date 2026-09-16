-- Diagnóstico reutilizable y de sólo lectura para una venta relacionada con una cotización.
-- Ejecutar únicamente sobre una instalación autorizada. Revisar el resultado porque puede
-- contener datos comerciales de la venta consultada.
-- Ajustar el prefijo si la instalación no usa ikontrol_ y reemplazar 1 por el ID objetivo.
SET @target_invoice_id := 1;

SELECT i.*
FROM ikontrol_invoices AS i
WHERE i.id = @target_invoice_id;

SELECT ii.*
FROM ikontrol_invoice_items AS ii
WHERE ii.invoice_id = @target_invoice_id
ORDER BY ii.sort, ii.id;

SELECT ip.*
FROM ikontrol_invoice_payments AS ip
WHERE ip.invoice_id = @target_invoice_id AND ip.deleted = 0
ORDER BY ip.payment_date, ip.id;

SELECT cfv.*
FROM ikontrol_custom_field_values AS cfv
WHERE cfv.related_to_type = 'invoices' AND cfv.related_to_id = @target_invoice_id;

SELECT e.*, ei.id AS estimate_item_id, ei.title, ei.quantity, ei.rate, ei.total
FROM ikontrol_estimates AS e
LEFT JOIN ikontrol_estimate_items AS ei ON ei.estimate_id = e.id AND ei.deleted = 0
WHERE e.id = (SELECT estimate_id FROM ikontrol_invoices WHERE id = @target_invoice_id);

-- Demuestra la diferencia del filtro mensual que ocultaba una venta válida.
SELECT id, display_id, bill_date, due_date, status, project_id, estimate_id, deleted,
       bill_date BETWEEN DATE_FORMAT(CURRENT_DATE, '%Y-%m-01') AND LAST_DAY(CURRENT_DATE) AS in_sale_month,
       due_date BETWEEN DATE_FORMAT(CURRENT_DATE, '%Y-%m-01') AND LAST_DAY(CURRENT_DATE) AS in_due_month
FROM ikontrol_invoices
WHERE id = @target_invoice_id;

-- Numeración de cotizaciones: el folio visible se deriva del ID, no de una secuencia separada.
SELECT id, estimate_date, valid_until, status, deleted, project_id, public_key
FROM ikontrol_estimates
ORDER BY id;

-- No contiene operaciones persistentes ni reparación automática.
