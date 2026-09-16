# Hotfix C2.3.7 — Entrada fiscal desde la venta

## Respaldo

Se creó `writable/backups/c2_3_7_fiscal_entry_hotfix_20260813_203232`, con dump de `ikontrol20_dold_preview`, código en alcance e inventario SHA-256.

## Causa y corrección

Los botones tenían la URL canónica, pero `modal_anchor()` activa el manejador de `assets/js/app.all.js`, que enviaba siempre `POST ajaxModal=1`. La ruta `fiscal/drafts/create/{sale}` sólo acepta `GET`; el clic real devolvía `Can't find a route for 'POST: fiscal/drafts/create/8'`.

El manejador modal admite ahora `data-action-method`, conservando `POST` como default. Ambos botones fiscales declaran `GET`. Se actualizaron `assets/js/app.js` y el bundle servido `assets/js/app.all.js`; no se agregó un alias POST. Visibilidad y controlador comparten los permisos `fiscal.sales.invoice` o `fiscal.drafts.create`.

## Evidencia operativa

Se usó la venta 8, cliente 35, `commercial_status=closed`, pago `not_paid`. Ambos anchors renderizados apuntaron a `fiscal/drafts/create/8`, con `data-action-method=GET`. Ambos XHR respondieron HTTP 200 `text/html` y renderizaron `app/Views/fiscal/drafts/form.php`.

El modal mostró emisor 2 dinámico, serie A, receptor 37, fecha CFDI, G03, PPD, 99 y edición fiscal separada. El guardado real por `appForm` creó el borrador 2, snapshot v2: subtotal 116.00, IVA 18.56 y total 134.56. La prefactura respondió HTTP 200 y la venta permaneció cerrada.

No hubo llamadas PAC, timbrado, PDF ni consumo de timbres. El wallet quedó en 19 disponibles y 0 reservados; permanece un único intento PAC histórico.
