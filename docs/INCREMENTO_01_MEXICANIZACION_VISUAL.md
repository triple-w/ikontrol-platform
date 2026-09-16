# Incremento 01: mexicanización visual y conceptual

> Fecha: 21 de julio de 2026. Alcance exclusivo: traducciones y presentación. No se modificaron tablas, datos, controladores, modelos, rutas, estados internos ni cálculos; no se implementó funcionalidad fiscal.

## 1. Auditoría previa

Se buscaron los términos solicitados, sin distinguir mayúsculas, en idiomas, vistas, controladores, librerías y JavaScript. Se detectaron 706 archivos porque los nombres internos (`invoice`, `client`, `item`, etc.) atraviesan toda la aplicación y las bibliotecas JS incluyen vocabulario genérico. Eso no significa que las 706 coincidencias sean textos visibles.

### Archivos con textos visibles relevantes

| Área | Archivos principales revisados | Clasificación |
|---|---|---|
| Diccionario | `app/Language/spanish/default_lang.php`, `app/Language/english/default_lang.php` | Texto visible modificable; principal punto de cambio. |
| Menú | `app/Libraries/Left_menu.php`, `app/Views/includes/left_menu.php`, `app/Views/left_menu/*` | Las rutas/nombres son internos; el texto sale de `app_lang()` y se cambia sólo por idioma. |
| Clientes/empresa | `app/Views/clients/{client_form_fields,client_info,view,clients_list}.php`, `app/Views/company/{modal_form,company_widget}.php` | Texto visible; VAT/GST son datos legacy, no RFC. |
| Productos | `app/Views/items/{index,modal_form,view,grid_view}.php` | Texto visible modificable; `items`, `rate`, `unit_type` siguen internos. |
| Cotizaciones | `app/Views/estimates/**`, `app/Views/estimate_requests/**`, plantillas PDF/correo | Texto visible modificable; IDs, estados y URLs no se cambian. |
| Ventas | `app/Views/invoices/**`, incluidos PDF, reportes, pagos y correos | Texto visible modificable; `invoice` sigue siendo entidad administrativa interna. |
| Pagos | `app/Views/invoices/payment_modal_form.php`, `app/Views/invoices/payments/**`, `app/Views/payment_methods/**` | Texto visible; la forma administrativa no equivale a catálogo SAT. |
| Impuestos | `app/Views/taxes/**`, encabezados en ventas/cotizaciones/reportes | Texto visible; `tax_id`, tasas y cálculos permanecen intactos. |
| E-Invoice | `app/Views/settings/invoices/{index,e_invoice}.php`, `app/Views/e_invoice_templates/**`, `app/Libraries/E_invoice.php` | Módulo genérico que debe quedar fuera del flujo mexicano. Lógica y permisos no se modifican. |
| PDFs | `app/Views/invoices/invoice_pdf.php`, `app/Views/estimates/estimate_pdf.php` y sus `*_parts` | Texto administrativo modificable; estructura/cálculos intactos. |
| Reportes/dashboard | `app/Views/invoices/reports/**`, widgets de `invoices`, `estimates`, clientes y pagos | Se mexicanizan por las mismas claves de idioma. |
| Correos | vistas `send_*_modal_form.php` y plantillas configurables | Etiquetas del sistema cambian; contenido almacenado en BD requiere revisión humana. |

### Clasificación de coincidencias

1. **Visible y modificable:** valores finales de idioma, encabezados, etiquetas, avisos y leyendas PDF.
2. **Interno, no modificable:** nombres de archivos/clases/métodos, variables, claves de idioma, rutas (`/invoices`, `/estimates`), permisos, nombres de tabla/columna y comentarios técnicos.
3. **Ambiguo/revisión humana:** contenido libre guardado en BD (plantillas de correo, pie de PDF, título de aplicación, menús personalizados), “bill” de proveedores externos y traducciones inglesas usadas si el usuario cambia de idioma.
4. **Fuera del flujo mexicano:** E-Invoice genérico; se oculta su pestaña normal, pero no se borra ni desactiva técnicamente para no alterar instalaciones que ya lo usen.

## 2. Diccionario aplicado

| Clave interna conservada | Presentación española final |
|---|---|
| `invoice`, `invoices` | Venta, Ventas |
| `invoice_id`, `invoice_number` | Folio de venta |
| `add_invoice`, `edit_invoice`, `invoice_details` | Registrar venta, Editar venta, Detalle de venta |
| `bill_date`, `due_date` | Fecha de venta, Fecha de vencimiento |
| `estimate`, `estimates`, `estimate_number` | Cotización, Cotizaciones, Folio de cotización |
| `create_invoice` en una cotización | Convertir en venta |
| `client`, `clients`, `company_name` | Cliente, Clientes, Nombre comercial |
| `company`, `companies` | Empresa, Empresas |
| `zip`, `state` | Código postal, Estado |
| `item`, `items` | Producto o servicio, Productos y servicios |
| `unit_type`, precio en formulario de artículo | Unidad comercial, Precio |
| `tax`, `taxes`, `taxable` | Impuesto administrativo, Impuestos administrativos, Gravable administrativamente |
| `payment_method` | Forma de pago administrativa |
| `credit_note` | Nota de crédito administrativa |
| `client_wallet` | Saldo a favor |
| `amount`, `balance` | Importe, Saldo |
| `orders`, `expenses`, `reports`, `settings` | Pedidos, Gastos, Reportes, Configuración |

Las claves internas no se renombraron. Los overrides se colocaron al final del idioma español para conservar compatibilidad con las claves existentes y evitar un reemplazo mecánico inseguro dentro del código.

## 3. VAT, GST y TDS

- Los inputs `vat_number` y `gst_number` se conservan en el DOM y siguen enviando su valor para no borrar información al editar; se ocultan visualmente en formularios de cliente y empresa.
- Se ocultan en la ficha principal del cliente y en los bloques principales de PDF/vista de venta y cotización.
- Donde un módulo secundario legacy todavía los muestre (contratos, propuestas, estados de cuenta o contactos), la etiqueta final es “Identificador fiscal legado (VAT/GST; no es RFC)”. Nunca se presenta como RFC.
- TDS participa técnicamente como tercer impuesto en ventas y reportes. Ocultarlo cambiaría la comprensión de totales existentes; se conserva como “Impuesto/retención legado (no SAT)”. No se presenta como ISR ni retención SAT.

## 4. E-Invoice

`app/Libraries/E_invoice.php` sigue intacto y no es CFDI. La pestaña E-Invoice se oculta en la configuración normal mediante presentación (`d-none`), sin borrar URL, configuración, permisos ni lógica. Si un administrador accede directamente a la vista existente, ve:

- “Factura electrónica genérica (no CFDI)”.
- “Este módulo no corresponde a la facturación electrónica mexicana ni realiza timbrado ante el SAT.”

No se conectó con el dominio fiscal futuro ni se cambió su estado almacenado.

## 5. Cambios por módulo

### Menús y branding

El menú conserva sus URLs y permisos; las claves muestran Clientes, Productos y servicios, Cotizaciones, Ventas, Pagos, Gastos, Pedidos, Empresa, Impuestos administrativos, Configuración y Reportes. El nombre visible real procede de `settings.app_title`, por lo que no se cambió a iKontrol mediante código ni SQL. Debe configurarse manualmente como `iKontrol 2.0` en `/settings/general`. No se encontró crédito RISE hardcodeado en vistas que pudiera retirarse; no se tocaron licencias.

### Clientes

Se aplicaron Nombre comercial, Domicilio, Ciudad o municipio, Código postal, País, Responsable y Usuarios responsables. Se añadió la ayuda “Los datos fiscales se configurarán posteriormente en una sección independiente.” No se creó esa sección ni se presentaron datos comerciales como razón social fiscal.

### Productos y servicios

El módulo se presenta como “Productos y servicios”. En el modal: Nombre, Descripción, Categoría, Unidad comercial y Precio. Se añadió una ayuda no bloqueante que aclara que la configuración SAT llegará después. No se agregaron campos.

### Cotizaciones

Se aplicaron Cotización/Cotizaciones, estados Borrador/Enviada/Aceptada/Rechazada, Vigente hasta, Folio de cotización y “Convertir en venta”. Los valores internos `draft`, `sent`, `accepted`, `declined` y el flujo de conversión no cambiaron.

### Ventas

`invoices` se presenta como Ventas, con Folio de venta, Fecha de venta, Estado/Detalle/Total de venta, documento de venta y ventas recurrentes. Las notas de crédito se aclaran como administrativas. La vista de detalle muestra “Esta venta es un documento administrativo. No representa un CFDI timbrado.”

### Pagos

Se aplicaron Registrar pago, Fecha de pago, Importe, Referencia de operación, Notas, Historial de pagos, Saldo a favor y Forma de pago administrativa. El modal aclara que esta forma no corresponde automáticamente a la Forma de Pago del SAT.

### Impuestos administrativos

El módulo y sus acciones se presentan como administrativos. Se añadieron “Nombre del impuesto”, “Porcentaje” y la advertencia de que no constituyen configuración SAT. No se alteraron tasas, IDs, relaciones ni operaciones.

### PDFs y correos

El PDF de venta muestra “Documento administrativo de venta”; encabezados y claves de invoice/estimate se resuelven como Venta/Cotización. No se añadieron UUID, QR, SAT, PAC, sello o leyenda fiscal. Los correos generados mediante claves heredan la terminología; el contenido libre ya almacenado debe revisarse manualmente.

## 6. Archivos modificados

- `app/Language/spanish/default_lang.php`
- `app/Language/english/default_lang.php` (sólo nuevas ayudas, para evitar claves faltantes)
- `app/Views/clients/client_form_fields.php`
- `app/Views/clients/client_info.php`
- `app/Views/company/modal_form.php`
- `app/Views/company/company_widget.php`
- `app/Views/items/modal_form.php`
- `app/Views/estimates/estimate_parts/estimate_to.php`
- `app/Views/invoices/view.php`
- `app/Views/invoices/invoice_pdf.php`
- `app/Views/invoices/invoice_parts/bill_to.php`
- `app/Views/invoices/payment_modal_form.php`
- `app/Views/payment_methods/*` no requirió cambio: usa claves compartidas.
- `app/Views/taxes/index.php`
- `app/Views/taxes/modal_form.php`
- `app/Views/settings/invoices/index.php`
- `app/Views/settings/invoices/e_invoice.php`

Archivo creado: `docs/INCREMENTO_01_MEXICANIZACION_VISUAL.md`.

No se modificaron controladores, modelos, librerías, rutas, migraciones, base, `system/**`, `app/ThirdParty/**` ni JavaScript vendorizado.

## 7. Textos que permanecen

- “Invoice”, “Estimate”, VAT, GST, TDS y E-Invoice permanecen en nombres internos, comentarios, variables, rutas, el idioma inglés y asignaciones antiguas del archivo español que son sobrescritas por los valores finales. No son evidencia de texto español efectivo.
- VAT/GST pueden aparecer en módulos secundarios legacy con la advertencia “no es RFC”; se preserva porque esos módulos pueden representar documentos históricos.
- TDS permanece visible sólo como impuesto/retención legado no SAT porque puede afectar totales existentes.
- Plantillas de correo/PDF, título de la aplicación, pie y menús personalizados guardados en BD no pueden cambiarse sin modificar datos; requieren revisión administrativa.
- En idioma inglés permanece la terminología inglesa original. No se tradujo por completo porque el alcance prioriza español, pero las nuevas ayudas sí tienen fallback inglés.

## 8. Pruebas y validaciones

- Lint PHP de los dos idiomas y 13 vistas modificadas: sin errores.
- Valores efectivos comprobados cargando el idioma español: Venta, Ventas, Cotización, Cotizaciones, Productos y servicios, Forma de pago administrativa, Impuestos administrativos, identificadores VAT/GST “no es RFC”, TDS “no SAT” y E-Invoice “no CFDI”.
- Pruebas Incremento 0: **58 passed, 0 failed**.
- `php spark routes`: sin rutas fiscales, PAC, timbrado ni controlador `Fi`; las URLs RISE no se modificaron.
- No se ejecutaron pruebas de navegador ni generación real de PDF porque requieren sesión/datos de una instalación; quedan en la revisión manual.
- Git no tiene commit inicial: `git diff` no puede construir un diff histórico del árbol, que continúa íntegramente no rastreado desde el Incremento 0.

## 9. Lista de revisión manual

| # | URL | Acción | Debe aparecer | No debe aparecer | Captura recomendada |
|---|---|---|---|---|---|
| 1 | `/` | Iniciar sesión y abrir menú lateral. | Clientes, Productos y servicios, Cotizaciones, Ventas, Pagos, Reportes. | Invoice, Estimate, Items. | Menú completo abierto, escritorio. |
| 2 | `/clients` | Abrir listado y alta/edición. | Cliente, Nombre comercial, Domicilio, Código postal y ayuda fiscal futura. | VAT/GST/RFC/Razón social fiscal. | Listado y modal completo. |
| 3 | `/items` | Abrir listado y modal. | Productos y servicios, Nombre, Unidad comercial, Precio y ayuda SAT futura. | Item, Rate, campos SAT. | Listado y modal. |
| 4 | `/estimates` | Crear, guardar y abrir detalle. | Cotización, Folio, Vigente hasta, Enviada/Aceptada/Rechazada, Convertir en venta. | Estimado, Estimate, Crear factura. | Listado, modal y detalle. |
| 5 | `/invoices` | Crear y abrir una venta. | Venta, Folio de venta, Fecha de venta y aviso “No representa un CFDI”. | Factura fiscal, CFDI, Invoice. | Listado, modal y detalle con aviso. |
| 6 | `/invoice_payments` y pago desde `/invoices/view/{id}` | Registrar pago y revisar historial. | Forma de pago administrativa, Importe, Referencia y aviso SAT. | Método de pago SAT. | Modal e historial. |
| 7 | `/taxes` | Abrir listado y modal. | Impuestos administrativos y advertencia SAT. | Configuración fiscal SAT, ISR. | Listado con alerta y modal. |
| 8 | `/estimates/preview/{id}` | Abrir/descargar PDF. | Cotización y Folio de cotización. | Estimate, Factura fiscal, UUID/QR. | Primera página completa. |
| 9 | `/invoices/preview/{id}` | Abrir/descargar PDF. | Venta y “Documento administrativo de venta”. | Factura fiscal, CFDI, UUID, QR, SAT/PAC. | Primera página y totales. |
| 10 | Mismas URLs en ancho móvil | Repetir menú, modales y detalles. | Textos completos o ajuste responsivo legible. | Cortes que oculten acciones/importe. | 390×844 px: menú, venta y pago. |

Para `/settings/invoices`, confirmar adicionalmente que E-Invoice no aparece en las pestañas normales. Si se abre directamente `/settings/e_invoice`, capturar la advertencia “Factura electrónica genérica (no CFDI)”.

## 10. Limitaciones y confirmación

La consistencia total depende de que la instalación use el idioma español y de revisar contenido configurable en BD. Los textos de plugins o menús personalizados no pueden garantizarse desde el core. Los estados compartidos se tradujeron de forma neutral cuando también pueden usarse fuera de ventas.

**Confirmación:** no se modificó lógica administrativa ni fiscal; no se agregaron perfiles, RFC, SAT, CFDI, certificados, series, complementos o PAC. No se cambiaron nombres internos, rutas, permisos, estados, cálculos, tablas o datos.
