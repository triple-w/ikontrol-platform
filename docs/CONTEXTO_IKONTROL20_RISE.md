# Contexto técnico de iKontrol2.0 sobre RISE

> Auditoría estática realizada el 21 de julio de 2026 sobre `C:\xampp\htdocs\ikontrol2`. Alcance: código entregado, sin ejecutar la aplicación ni consultar una base de datos instalada. Se creó únicamente este documento. En este texto, **comprobado** significa visible en el código o en `install1/database.sql`; **no comprobado** significa que no existe evidencia suficiente en la entrega.

## 0. Hallazgo de identidad tecnológica

El proyecto **no es Laravel**. Es RISE CRM construido sobre **CodeIgniter 4.6.1**, comprobado en `C:\xampp\htdocs\ikontrol2\system\CodeIgniter.php` (`CodeIgniter::CI_VERSION`). La estructura `app/Controllers`, `app/Models`, `app/Views`, el framework incluido en `system/` y el enrutamiento de `app/Config/Routes.php` lo confirman. Por tanto, “versión de Laravel” es: **no aplica**.

La entrega es una aplicación monolítica por instalación. No hay código de multitenancy; la conexión activa es una sola configuración MySQLi en `app/Config/Database.php`. Esto es compatible conceptualmente con instalaciones independientes por subdominio, pero el archivo entregado contiene credenciales/configuración directa y no se encontró `.env`.

## 1. Resumen técnico

| Aspecto | Resultado comprobado | Evidencia principal |
|---|---|---|
| Framework | CodeIgniter 4.6.1; Laravel no aplica | `C:\xampp\htdocs\ikontrol2\system\CodeIgniter.php` |
| PHP mínimo | 8.1 | `C:\xampp\htdocs\ikontrol2\install1\index.php`, `$php_version_required = "8.1"` |
| PHP recomendado | No está declarado. Recomendación de auditoría: una versión soportada compatible, validada antes de producción | No existe manifiesto raíz que fije versión |
| Base de datos | MySQL/MariaDB mediante `MySQLi`, puerto 3306; esquema InnoDB, `utf8mb3` | `app/Config/Database.php`, `install1/database.sql` |
| Frontend | Vistas PHP de CI4, Bootstrap/jQuery, AJAX, DataTables; no Blade, Livewire, Vue ni React | `app/Views`, `assets/js/app.js`, `assets/js/jquery-3.5.1.min.js` |
| Plantilla | RISE CRM | nombres, módulos y configuración `app/Config/Rise.php` |
| Composer | No existe `composer.json` raíz. Dependencias se distribuyen embebidas en `app/ThirdParty` | manifiestos internos de Google, Stripe, Pusher, PhpSpreadsheet e IMAP |
| npm | No existe `package.json` ni lockfile raíz; activos JS precompilados/vendorizados | `assets/js` |
| Autenticación | Sesión CI; usuarios en `users`; contraseña hash verificada por flujo de `Signin`/`Users_model`; estados y bloqueo de login | `app/Controllers/Signin.php`, `app/Models/Users_model.php`, `users` |
| Roles/permisos | Admin global (`users.is_admin`) y rol (`role_id`); permisos serializados en `roles.permissions`; además permisos de cliente en `users.client_permissions` | `Roles.php`, `Permission_manager.php` |
| Archivos | Disco local: `files/` y rutas configurables; metadatos serializados y `general_files`, `project_files`, etc. | `app/Helpers/app_files_helper.php`, `app/Libraries/App_folders.php` |
| Colas | No se encontró infraestructura de queue/job persistente | ausencia de `Jobs`; notificaciones se procesan directamente |
| Programación | Endpoint `cron`, mínimo 300 s; `Cron_job::run()` procesa recurrencias, recordatorios y tareas | `app/Controllers/Cron.php`, `app/Libraries/Cron_job.php` |
| APIs externas | Stripe, PayPal, Paytm, Google Calendar/API, Gmail/Outlook IMAP/SMTP, Pusher, reCAPTCHA y webhooks | `app/Libraries`, controladores redirect/API/webhook |
| API interna | Enrutamiento automático GET/POST a métodos públicos de casi todos los controladores; CORS explícito sólo para `collect_leads/save` | `app/Config/Routes.php` |

Dependencias PHP principales comprobadas: TCPDF, Stripe PHP, Google API Client 2.18.3, Guzzle, Monolog, Pusher PHP Server, PhpSpreadsheet, Ddeboer IMAP y parsers MIME. Dependencias frontend principales: jQuery 3.5.1, Bootstrap, DataTables, Select2, Summernote, Dropzone, FullCalendar, Chart.js, Frappe Gantt, Magnific Popup, Signature Pad, RecordRTC y Pusher JS. No se puede reconstruir de forma reproducible el árbol completo porque no hay manifiestos raíz.

## 2. Estructura del proyecto

- `C:\xampp\htdocs\ikontrol2\app\Config`: configuración, rutas, servicios y hooks.
- `...\app\Controllers`: 91 archivos PHP; controladores HTTP. `App_Controller.php` y `Security_Controller.php` son bases críticas.
- `...\app\Models`: 93 archivos PHP; Active Record/SQL, todos sobre `Crud_model.php`.
- `...\app\Libraries`: servicios de aplicación no formalizados como capa Service: archivos, permisos, cron, pagos, PDF, e-invoice y conectores.
- `...\app\Helpers`: funciones globales de moneda, fechas, archivos, reportes, notificaciones y UI.
- `...\app\Views`: vistas PHP por módulo; equivalen a componentes parciales, formularios y scripts inline.
- `...\app\Language`: traducciones, incluida `spanish/default_lang.php`.
- `...\app\ThirdParty`: dependencias PHP incluidas en el repositorio/paquete.
- `...\assets`: CSS/SCSS, imágenes y librerías JS precompiladas.
- `...\files`: archivos públicos/plantillas/importaciones.
- `...\writable`: sesiones, logs, caché y uploads en ejecución.
- `...\install1`: instalador y esquema inicial completo (`database.sql`).
- `...\system`: copia del framework CI4; no es código funcional propio.
- `...\plugins`: punto de extensión de plugins.

### Inventario de capas

**Controladores (91):** About, Announcements, Attendance, Automation, Checklist_groups, Checklist_template, Clients, Client_groups, Collect_leads, Company, Contacts, Contract, Contracts, Contract_templates, Cron, Custom_fields, Dashboard, Email_templates, Estimate, Estimates, Estimate_requests, Events, Event_tracker, Expenses, Expense_categories, External_tickets, E_invoice_templates, File_manager, Filters, Forbidden, Google_api, Help, Invoices, Invoice_payments, Items, Item_categories, Knowledge_base, Labels, Leads, Lead_source, Lead_status, Leaves, Leave_types, Left_menus, Messages, Microsoft_api, Notes, Notifications, Notification_processor, Offer, Orders, Order_status, Pages, Payment_methods, Paypal_redirect, Paytm_redirect, Pay_invoice, Pre_loader, Projects, Project_status, Proposals, Proposal_templates, Pwa, Reminder_settings, Reports, Request_estimate, Rise_plugins, Roles, Search, Settings, Signin, Signup, Store, Stripe_redirect, Subscriptions, Tasks, Task_priority, Task_status, Taxes, Team, Team_members, Tickets, Ticket_types, Timeline, Todo, Updates, Uploader, Upload_pasted_image y Webhooks_listener, más los controladores base `App_Controller` y `Security_Controller`.

**Modelos (93):** los modelos coinciden con las tablas funcionales. Los críticos son `Clients_model`, `Users_model`, `Items_model`, `Estimates_model`, `Estimate_items_model`, `Invoices_model`, `Invoice_items_model`, `Invoice_payments_model`, `Orders_model`, `Order_items_model`, `Taxes_model`, `Payment_methods_model`, `Roles_model`, `Settings_model`, `Company_model`, `Expenses_model`, `Projects_model` y `Crud_model`, todos en `C:\xampp\htdocs\ikontrol2\app\Models\`.

**Servicios/librerías:** no existe `app/Services`. La función equivalente vive en `app/Libraries`: `Client.php`, `Permission_manager.php`, `Cron_job.php`, `E_invoice.php`, `Pdf.php`, `Stripe.php`, `Paypal.php`, `Paytm.php`, `Google.php`, `Google_calendar.php`, `Gmail_*`, `Outlook_*`, `Imap.php`, `Automations.php`, `Reminders.php`, `Template.php`, `Ticket.php`, `Clean_data.php`, `Dropdown_list.php`, `Pusher_connect.php`, `ReCAPTCHA.php`, `Hooks.php`, `App_folders.php` y `Excel_import.php`.

**Repositorios, Requests, Policies, Middleware, Jobs, Listeners, Observers y Commands propios:** no existen carpetas/clases comprobables. `app/Filters` sólo contiene `.gitkeep`; se usan filtros del framework/configuración. `app/Database/Migrations` y `Seeds` sólo contienen `.gitkeep`.

**Traits:** `App_folders` y `Excel_import` son traits alojados en `app/Libraries`; `Google_Trait.php` también existe. Se usan en Clients, Contacts, Expenses, File_manager, Items, Leads, Leaves, Projects, Tasks y Team_members.

**Events/providers:** `app/Config/Events.php` registra `pre_system`, carga plugins y CSP. `app/Config/Services.php` actúa como proveedor CI. No existen Providers estilo Laravel.

**Componentes frontend:** no hay Blade/Livewire/Vue/React. Hay parciales PHP por módulo en `app/Views`, incluidos `*_total_section.php`, `*_parts`, `modal_form.php`, reportes y bloques `helper_js.php`. JavaScript crítico: `assets/js/app.js`, `app.min.js`, `app.all.js`, `general_helper.js`, `notification_handler.js`, `service-worker.js`; además hay JavaScript inline en numerosas vistas.

## 3. Módulos funcionales existentes

Las rutas siguen `/{controlador_en_minúsculas}` y `/{controlador}/{método}` por el bucle dinámico de `app/Config/Routes.php`. La tabla resume los módulos relevantes; “funcional” significa que hay controlador, modelo, vistas y esquema, no que se haya probado en navegador.

| Módulo / ruta | Controlador / modelo / tablas / vistas | Permiso y relaciones | Estado aparente |
|---|---|---|---|
| Empresas `/company` | `Company.php`; `Company_model`; `company`; `views/company` | administración/configuración; usada por estimates, invoices, orders, proposals, subscriptions | Funcional genérico |
| Usuarios/equipo `/team_members`, contactos `/contacts` | `Team_members.php`, `Contacts.php`; `Users_model`; `users`, `team`, `team_member_job_info` | admin/roles; contacto cliente por `client_id` | Funcional |
| Roles `/roles` | `Roles.php`; `Roles_model`; `roles`; `views/roles` | administración; permisos serializados | Funcional, diseño frágil |
| Clientes `/clients` | `Clients.php`; `Clients_model`; `clients`, `users`, `client_groups`, `client_wallet` | permiso `client`; enlaza proyectos, estimates, invoices, orders, pagos | Funcional |
| Proveedores | Sin controlador/modelo/tabla/vistas | ninguna | No existe |
| Productos/servicios `/items` | `Items.php`; `Items_model`; `items`, `item_categories`; `views/items` | inicializa permiso `order`; copiados a partidas | Funcional genérico; no distingue producto/servicio |
| Categorías `/item_categories` | controlador/modelo homónimos; `item_categories` | artículos | Funcional |
| Cotizaciones `/estimates` | `Estimates.php`; modelos Estimates/Estimate_items; `estimates`, `estimate_items`; `views/estimates` | permiso `estimate`; cliente/proyecto/solicitud; convierte a invoice | Funcional |
| Propuestas `/proposals` | controlador/modelos homónimos; `proposals`, `proposal_items` | cliente/proyecto; puede copiar partidas | Funcional, concepto cercano pero distinto a cotización |
| Ventas/facturas `/invoices` | `Invoices.php`; Invoices/Invoice_items; `invoices`, `invoice_items`; `views/invoices` | permiso `invoice`; cliente, estimate, order, project | Funcional administrativo |
| Pedidos `/orders` y tienda `/store` | `Orders.php`, `Store.php`; Orders/Order_items; `orders`, `order_items`, `order_status` | permiso `order`; cliente, artículo, proyecto, invoice | Funcional/demo e-commerce |
| Compras | No hay purchase bill/vendor | ninguna | No existe |
| Inventario/almacenes/sucursales | Sin tablas stock, warehouse o branch | ninguna | No existe |
| Pagos `/invoice_payments` | `Invoice_payments.php`; modelo homónimo; `invoice_payments`, `payment_methods`, `client_wallet` | permiso invoice/payment; factura y cliente | Funcional básico |
| Cuentas por cobrar | Derivada de `invoice_total - SUM(invoice_payments.amount)` | invoices/pagos/clientes | Funcional como reporte, no subledger formal |
| Cuentas por pagar | Sólo gastos; no proveedores/documentos por pagar | `expenses` no equivale a CxP | No existe |
| Impuestos `/taxes` | `Taxes.php`; `Taxes_model`; `taxes`; `views/taxes` | configuración; dos o tres IDs en documentos | Funcional genérico, insuficiente para CFDI |
| Descuentos | Campos en estimates/invoices/orders/proposals/contracts | antes/después de impuesto; porcentaje/fijo | Funcional genérico |
| Monedas | `clients.currency`, `currency_symbol` y settings de conversión | por cliente; reportes convierten | Parcial; no por documento |
| Pagos recurrentes/suscripciones `/subscriptions` | controlador/modelos; `subscriptions`, `subscription_items` | cliente/Stripe/invoices | Funcional genérico |
| Reportes `/reports` y subreportes | `Reports.php`, helpers, vistas de invoices/expenses/orders/tickets | permisos de módulo | Funcional, sin contabilidad formal |
| Configuración `/settings` | `Settings.php`; `Settings_model`; `settings`, vistas numerosas | admin/permisos administrativos | Funcional, key/value |
| Proyectos/tareas/tickets/CRM | controladores/modelos/vistas completos | clientes, usuarios y permisos | Funcional administrativo |

No se hallaron módulos de ventas POS, devoluciones de mercancía, kardex, costos, variantes, lotes, series, proveedores, compras ni sucursales.

## 4. Flujo de clientes

1. GET/POST `/clients/modal_form` carga `app/Views/clients/modal_form.php`; `Clients::modal_form()` valida sólo `id` numérico y acceso.
2. POST `/clients/save` arma datos y delega en `App\Libraries\Client::save_client()`.
3. Columnas enviadas exactamente: `client_id` (se transforma en id), `company_name`, `type` desde `account_type`, `address`, `city`, `state`, `zip`, `country`, `phone`, `website`, `vat_number`, `gst_number`, `currency`, `currency_symbol`, `disable_online_payment`, `owner_id`, `managers`, `group_ids`, `labels` y `contact_email`.
4. El esquema exige DB `company_name`, `type`, `starred_by`, `group_ids`, indicadores/ids, Stripe y `managers`; la librería completa defaults. En interfaz, `company_name` y `account_type` son administrativos esenciales; la importación declara obligatorios `company_name` y `type`. `contact_email` crea/relaciona un contacto; sus reglas se encuentran en `Client.php`/`Users_model.php`.
5. Son opcionales a nivel de flujo: domicilio, teléfono, web, VAT/GST, moneda/símbolo, grupos, etiquetas, propietario/gestores y pago online. No hay validación fiscal.

Relaciones lógicas: `estimates.client_id`, `invoices.client_id`, `orders.client_id`, `projects.client_id`, `subscriptions.client_id`; `invoice_payments` se relaciona indirectamente mediante `invoice_id`. CxC se calcula en `Invoices_model` y `Invoice_payments_model` restando pagos a `invoices.invoice_total`. No hay foreign keys declaradas en SQL.

Sólo existe un domicilio en `clients.address/city/state/zip/country`. Los contactos `users` tienen `address` y `alternative_address`, pero no constituyen un catálogo normalizado de domicilios fiscales/comerciales del cliente. No hay separación formal de datos comerciales, administrativos y fiscales.

Fiscalidad comprobada: `vat_number` y `gst_number` son campos genéricos. **No deben asumirse como RFC.** No existen columnas específicas `rfc`, `razon_social`, `regimen_fiscal`, `codigo_postal_fiscal` o `uso_cfdi`. Faltan además residencia fiscal, número de registro tributario extranjero, correo fiscal controlado, domicilio fiscal versionado y validaciones SAT.

Archivos críticos: `app/Controllers/Clients.php`, `app/Libraries/Client.php`, `app/Models/Clients_model.php`, `app/Models/Users_model.php`, `app/Views/clients/modal_form.php`, `install1/database.sql`.

## 5. Flujo de productos y servicios

`Items::save()` (`app/Controllers/Items.php`) valida `id` numérico y `category_id` requerido. Guarda `title`, `description`, `category_id`, `unit_type`, `rate` (mediante `unformat_currency`), `show_in_client_portal`, archivos serializados y fuerza `taxable` a cadena vacía. La tabla única `items` contiene `id`, `title`, `description`, `unit_type varchar(20)`, `rate double`, `files`, `show_in_client_portal`, `category_id`, `taxable`, `sort`, `deleted`.

No existe discriminador producto/servicio; ambos serían artículos genéricos. No hay costo, inventario, existencias, almacén, variantes, listas de precios ni unidad catalogada. `unit_type` es texto comercial libre y `rate` es un precio único. `taxable` sólo es un booleano genérico y el controlador actual lo vacía; no vincula una tasa al artículo. Descuentos se manejan en el encabezado del documento, no por artículo.

Al seleccionar un artículo, sus datos se copian a `estimate_items`, `invoice_items`, `order_items`, `proposal_items`, `contract_items` o `subscription_items`: `title`, `description`, `quantity`, `unit_type`, `rate`, `total`, `item_id`. Esto conserva una instantánea parcial. Compras no existen.

| Campo fiscal buscado | Resultado |
|---|---|
| Clave producto/servicio SAT | No existe |
| Clave unidad SAT | No existe; `unit_type` sólo podría reutilizarse visualmente, no es equivalencia comprobada |
| Objeto de impuesto | No existe; `taxable` booleano no equivale al catálogo SAT |
| IVA/IEPS/retenciones | No se configuran por artículo; sólo tasas genéricas de encabezado |
| Unidad comercial | Existe como texto `unit_type` |
| Descripción fiscal | No existe; `description` es genérica y sólo podría servir como base |
| Costos/inventario/variantes | No existen |

## 6. Flujo de cotizaciones

La cotización actual se llama **Estimate** y usa `/estimates`. `Estimates::save()` requiere `estimate_client_id`, `estimate_date`, `valid_until`; acepta `tax_id`, `tax_id2`, `company_id`, `estimate_note`, proyecto/solicitud y custom fields. Las partidas se guardan en `estimate_items`; cantidad, tarifa y total son `double`.

El encabezado `estimates` conserva cliente, fechas, nota, estado `draft|sent|accepted|declined`, dos tasas, descuento (`before_tax|after_tax`, `percentage|fixed_amount`), proyecto, `accepted_by`, firma, clave pública, empresa y borrado lógico. Puede editarse si `_is_estimate_editable()` y permisos lo permiten; puede clonarse y eliminarse lógicamente. Aceptación se identifica por `status='accepted'`, `accepted_by`, `signature`/`meta_data` según el flujo público.

La conversión crea una factura copiando encabezado/partidas y conserva `invoices.estimate_id`. No se encontró una columna inversa `estimates.invoice_id`; la referencia está del lado de factura. Los cálculos usan suma de partidas, dos impuestos y descuento de encabezado. No hay impuesto/base por partida persistido.

Una cotización puede existir sin información fiscal completa y **no genera CFDI automáticamente**. El flujo hallado sólo crea/convierte documentos administrativos.

## 7. Flujo de ventas

El candidato administrativo a “venta” es `invoices`, aunque semánticamente el template lo llama factura. `/invoices/save` exige cliente, `bill_date` y `due_date`; acepta proyecto, tres impuestos, empresa, nota, etiquetas, archivos, recurrencia y `estimate_id`. Las partidas son `invoice_items`, con `taxable` por línea pero sin desglose fiscal persistido.

Estados persistidos: `draft`, `not_paid`, `cancelled`, `credited`. Pagado/parcial/vencido se deriva de pagos y fechas, no es un estado de la tabla. Cancelación usa `cancelled_at`/`cancelled_by`; no es cancelación SAT. “Devolución” no existe; sí existe `type='credit_note'`, `main_invoice_id` y estado `credited`, que constituye una nota de crédito administrativa genérica.

Pagos múltiples por factura se suman en `invoice_payments`. CxC es `invoice_total - SUM(amount)`. La relación con cotización queda en `estimate_id`; con pedido en `order_id`; con cliente en `client_id`; con inventario no existe. No se encontraron pólizas, cuentas contables, asientos ni libro mayor; los reportes financieros agregan invoices, payments y expenses.

`invoices` es el elemento que **podría ser el origen administrativo futuro** de un documento fiscal porque conserva cliente, partidas, totales y pagos, pero esta auditoría no propone ni implementa esa integración.

## 8. Flujo de pagos

`Invoice_payments::payment_modal_form()` selecciona una factura abierta y propone su saldo. `save_payment()` requiere `invoice_id`, `invoice_payment_method_id`, fecha y monto; persiste `amount`, `payment_date`, `payment_method_id`, `note`, `invoice_id`, `transaction_id`, `created_by`, `created_at` y `deleted`.

- Una factura admite varios registros: **pagos parciales sí**.
- Cada pago tiene exactamente un `payment_method_id`: varias formas para una misma factura son posibles mediante varios pagos, no dentro de un pago.
- Un pago no puede aplicarse a varias facturas: falta tabla cabecera/aplicaciones. Por tanto, **no** liquida varias ventas.
- El saldo se deriva; se compara a dos decimales en varios queries (`number_format`, `TRUNCATE`) con tolerancia configurada.
- Anticipos: `client_wallet` permite saldo por cliente y existe método tipo `client_wallet`; es un monedero genérico, no un modelo fiscal de anticipo.
- Crédito: la factura pendiente y fecha de vencimiento modelan crédito comercial básico; no hay condiciones/líneas de crédito.
- Moneda: se hereda del cliente (`clients.currency`, `currency_symbol`). No existe moneda/tipo de cambio persistido por pago ni por factura.
- Pagos online: Stripe/PayPal/Paytm registran transacciones mediante controladores/librerías/IPN; deben auditarse antes de reutilizar.

Archivos: `app/Controllers/Invoice_payments.php`, `app/Models/Invoice_payments_model.php`, `app/Models/Invoices_model.php`, `app/Models/Payment_methods_model.php`, `app/Views/invoices/payment_modal_form.php`, `app/Libraries/Stripe.php`, `Paypal.php`, `Paytm.php`.

Implicación para complemento de pago: la relación N pagos : 1 invoice y la ausencia de tipo de cambio, moneda por pago, parcialidad, saldo anterior/insoluto fiscal y aplicación N:M hacen insuficiente el modelo actual.

## 9. Sistema actual de impuestos

`taxes` sólo almacena `id`, `title`, `percentage double`, `deleted`, `stripe_tax_id`. Estimates/orders/proposals/contracts/subscriptions tienen dos `tax_id`; invoices tiene tres. El tercer impuesto de invoice es tratado como `TDS` en `app/Libraries/E_invoice.php`, indicio de retención de otra jurisdicción, no una retención mexicana comprobada.

El cálculo está en SQL de los modelos (`get_sales_total_meta` y variantes): suma `items.total`; calcula porcentaje sobre subtotal o subtotal menos descuento según `discount_type`; el descuento puede ser porcentaje o importe fijo. En invoices, `invoice_items.taxable` separa base gravada/no gravada para el e-invoice genérico. Los montos agregados se guardan en `invoices.invoice_subtotal`, `discount_total`, `tax`, `tax2`, `tax3`, `invoice_total` al guardar encabezado/partidas.

Precisión: esquema `double`; no hay escala declarada. UI usa helpers y settings de dígitos; saldos se fuerzan a 2 decimales en `Invoices_model::get_invoice_total_summary()` y algunos reportes usan `TRUNCATE(...,2)`. El momento de redondeo no es uniforme. El impuesto se calcula a nivel agregado, no se persisten base/tasa/importe por partida. Hay máximo 3 tasas en factura, no grupos ni una relación N:M. No se comprobó impuesto incluido; el algoritmo suma tasas al subtotal y distingue descuento antes/después. Traslado/retención mexicanos, IEPS, cuota y exención no existen.

| Nombre actual | Ubicación | Función comprobada | Posible significado MX (no equivalencia) | Riesgo | Recomendación preliminar |
|---|---|---|---|---|---|
| `taxes.percentage` | SQL/modelo Taxes | tasa porcentual genérica | tasa de IVA u otro traslado | Alto: sin tipo, factor, vigencia | conservar sólo para administración hasta diseñar catálogo fiscal |
| `tax_id`, `tax_id2` | estimates/orders/etc. | tasas de encabezado | dos impuestos genéricos | Alto | no mapear directamente a CFDI |
| `tax_id3` / `tax3` | invoices | tercer impuesto; exportación lo llama TDS | posible retención | Crítico | separar modelo fiscal; no renombrar sin migración |
| `invoice_items.taxable` | invoice_items/UI/e-invoice | incluye línea en base gravada | objeto de impuesto | Crítico: booleano insuficiente | mantener como flag administrativo |
| `vat_number` | clients/company | identificador tributario visible | RFC | Crítico | no asumir; agregar campo fiscal específico posteriormente |
| `gst_number` | clients/company | identificador GST visible | ninguno directo | Alto | mexicanizar visualmente o retirar tras análisis de datos |
| `stripe_tax_id` | taxes | mapeo a Stripe Tax | ninguno CFDI | Alto | aislar del futuro catálogo SAT |
| `E_invoice` | library/templates | XML configurable genérico | factura electrónica | Crítico | no confundir con CFDI/PAC |

No se localizaron conceptos funcionales Cess, Duty, Tax Group, Inclusive Tax o Sales Tax separados.

## 10. Terminología que debe mexicanizarse

| Término actual | Ubicación representativa | Uso | Sugerencia MX | Alcance | Riesgo |
|---|---|---|---|---|---|
| Estimate / Quotation | controllers/models/views `estimates`, traducciones | cotización | Cotización | visual inicialmente; técnico si se renombran clases/tablas | Alto técnico |
| Invoice Number / Invoice | invoices y lenguaje | documento de cobro | Venta/Factura administrativa; CFDI sólo cuando timbrado | visual y conceptual | Crítico |
| Bill date | `invoices.bill_date` | fecha de factura | Fecha de emisión administrativa | visual | Medio |
| VAT number | `clients.vat_number`, `company.vat_number` | id fiscal genérico | RFC sólo con campo/validación nuevos | técnico | Crítico |
| GST number | clients/company | id GST | eliminar/definir según negocio | técnico | Alto |
| TDS | `E_invoice.php`, tercer impuesto | impuesto deducido | Retención (sólo tras modelado) | técnico | Crítico |
| State | `clients.state`, lenguaje | estado/provincia | Estado | visual | Bajo |
| ZIP | `clients.zip` | código postal general | Código postal | visual; fiscal requiere campo específico | Medio |
| Tax / Tax rate | taxes, formularios, modelos | tasa genérica | Impuesto/Tasa | visual y técnico | Alto |
| Credit note | `invoices.type` | documento negativo | Nota de crédito administrativa | visual/técnico | Alto |
| Client wallet | `client_wallet` | saldo/anticipo | Saldo a favor/monedero | visual y contable | Alto |
| Company | `company` | emisor/entidad propia | Empresa/Emisor | visual; fiscal técnico | Medio |
| Order | orders/store | pedido | Pedido/orden de venta | visual | Bajo |
| Purchase Bill, TIN, Business Number | No localizados como conceptos propios funcionales | — | — | — | No aplica/no comprobado |

Las ocurrencias están también en `app/Language/english/default_lang.php`, `spanish/default_lang.php` y múltiples vistas. Cambiar sólo traducciones es de bajo impacto; renombrar tablas/columnas/clases rompería SQL, plugins, importadores y enlaces.

## 11. Modelo de datos relevante

El SQL no declara `FOREIGN KEY`; todas las “FK” siguientes son relaciones lógicas por nombre/joins. Casi todas las tablas usan `deleted` como borrado lógico. No hay timestamps uniformes.

| Tabla | Finalidad / PK / relaciones lógicas | Dinero/impuesto/moneda/estado | Borrado/tiempo |
|---|---|---|---|
| `company` | emisores genéricos; PK `id` | VAT/GST; sin moneda | `deleted`; sin timestamps |
| `settings` | key/value; clave única `setting_name`, sin PK id | moneda/tipo de cambio pueden vivir como settings | `deleted`; sin timestamps |
| `clients` | cliente/lead; PK `id`; owner/creator/source | `currency varchar(3)`, símbolo, VAT/GST | `deleted`, `created_date` |
| `users` | staff/contactos; PK `id`; role/client | status, permisos; sin dinero | `deleted`, `created_at`, `last_online` |
| `roles` | rol; PK `id`; permisos serializados | — | `deleted` |
| `items` | catálogo genérico; PK `id`; category | `rate double`, `taxable` | `deleted` |
| `estimates` | cotización; PK `id`; client/project/company | descuento `double`, tax ids, status enum | `deleted`; fechas de documento |
| `estimate_items` | partidas; PK `id`; estimate/item | quantity/rate/total `double` | `deleted` |
| `invoices` | venta/factura/nota; PK `id`; client/project/estimate/order/company | todos los totales `double`; tax1-3; status/type | `deleted`, fechas/cancelación |
| `invoice_items` | partidas; PK `id`; invoice/item | quantity/rate/total `double`, `taxable` | `deleted` |
| `invoice_payments` | pago; PK `id`; invoice/payment method | `amount double`; no currency/fx | `deleted`, payment_date/created_at |
| `payment_methods` | formas genéricas; PK `id` | mínimo `double`, tipo/settings | `deleted` |
| `client_wallet` | saldo cliente; PK `id`; client/user | `amount double` | `deleted`, fechas |
| `taxes` | catálogo de tasas; PK `id` | `percentage double`, Stripe id | `deleted` |
| `orders` / `order_items` | pedido/partidas; PK id | descuentos/totales `double`, dos tax ids, status_id | `deleted` |
| `proposals` / `proposal_items` | propuesta/partidas | descuentos/tasas/importes `double`, status | `deleted` |
| `subscriptions` / `subscription_items` | recurrencia | dos tax ids, importes partida `double`, status/payment_status | `deleted`, fechas |
| `expenses` | gasto | `amount double`, tax fields genéricos según esquema/modelo | `deleted`, expense_date |
| `projects` | proyecto cliente | price/budget según modalidad | `deleted`, fechas |
| `general_files`, `project_files` | metadatos de archivos | — | borrado lógico/fechas según tabla |

No hay tablas de inventario, almacenes, movimientos, proveedores, compras, cuentas contables, certificados, PAC, CFDI, conceptos fiscales, impuestos por partida, domicilios múltiples ni aplicaciones de pago N:M. El esquema usa `utf8mb3`, que merece migración futura controlada a `utf8mb4`.

## 12. Preparación fiscal existente

| Concepto | Clasificación | Evidencia |
|---|---|---|
| RFC / razón social / nombre fiscal / régimen fiscal / CP fiscal / uso CFDI | No existe | no hay columnas específicas; `company_name` y VAT son genéricos |
| Forma de pago | Existe solamente como campo genérico | `payment_methods`; no catálogo SAT |
| Método de pago | No existe | no PUE/PPD |
| Moneda | Existe parcialmente | por cliente/settings, no por documento fiscal |
| Tipo de cambio | Existe parcialmente/genérico | helpers/settings de conversión para reportes; no persistido por documento/pago |
| Exportación | No existe | no clave CFDI |
| Claves SAT producto/unidad | No existe | `unit_type` no equivale |
| Objeto de impuesto | Existe solamente como campo genérico | `taxable` booleano |
| IVA | Existe solamente como impuesto genérico | tasa sin código/tipo/factor |
| IEPS / retenciones MX | No existe | TDS no demuestra retención mexicana |
| Serie | No existe | no columna serie fiscal |
| Folio | Existe parcialmente/genérico | `display_id`, `number_year`, `number_sequence`; no folio CFDI comprobado |
| UUID / XML CFDI / PDF fiscal | No existe | PDF genérico y E_invoice XML no son CFDI |
| Certificados / PAC | No existe | sin almacenamiento/configuración específica |
| Cancelación fiscal | No existe | sólo cancelación administrativa |
| Complementos de pago | No existe | pagos genéricos |
| Notas de crédito | Existe parcialmente | `invoices.type='credit_note'`; no egreso CFDI |
| E-invoice genérico | Existe y parece funcional para plantillas genéricas | `app/Libraries/E_invoice.php`, `e_invoice_templates` |

No se pudo determinar compatibilidad legal de `E_invoice` con estándar alguno sin ejecutar/configurar plantillas; el código encontrado no implementa CFDI mexicano.

## 13. Validaciones administrativas y fiscales

Validaciones administrativas comprobadas:

- Clientes: ids numéricos/acceso; importación exige `company_name` y `type`; email de contacto usa `required|valid_email|max_length[100]`. El guardado normal delega en `Client::save_client`.
- Artículos: `category_id` requerido; `id` numérico. No se observa `title` requerido en validación servidor mostrada, aunque DB lo exige.
- Estimates: cliente numérico, fecha y vigencia requeridos; control de editabilidad/permisos.
- Invoices: cliente numérico, fecha de emisión y vencimiento requeridos; control de editabilidad/permisos.
- Pagos: factura, método, fecha y monto requeridos; ids numéricos. Debe revisarse si se impide monto mayor al saldo en todos los canales online/offline.
- Validaciones de frontend usan jQuery Validate, pero no deben considerarse protección servidor.

### Separación recomendada entre validación administrativa y validación fiscal

Los datos fiscales deben permanecer opcionales para operación administrativa y exigirse sólo al intentar generar un documento fiscal. Esta recomendación no está implementada.

- Artículo: puede crearse/venderse sin SAT; antes de facturar validar clave producto/servicio, unidad SAT, objeto, impuestos y descripción.
- Cliente: puede tener cotizaciones/ventas sin RFC; antes de facturar validar RFC, razón social, régimen, CP fiscal y uso CFDI.
- Empresa: puede operar sin certificados; antes de timbrar validar emisor, régimen, domicilio, CSD vigente y PAC.
- Venta: al facturar validar moneda, tipo de cambio, exportación, forma/método, conceptos, redondeos y totales.
- Pago: al complementar validar documento relacionado, parcialidad, saldo anterior, importe pagado, saldo insoluto, moneda y equivalencia.

Puntos futuros de aplicación: un servicio fiscal nuevo llamado desde el borde de emisión, no desde `Clients::save`, `Items::save` ni la creación administrativa; policies/permisos dedicados; validadores request/DTO; transacción que congele snapshot fiscal. No modificar directamente `E_invoice.php` para convertirlo implícitamente en PAC.

## 14. Riesgos técnicos

| Prioridad | Riesgo | Evidencia/archivo | Impacto | Recomendación |
|---|---|---|---|---|
| Crítica | Dinero e impuestos en `double` | `install1/database.sql` | errores de centavos/timbre | migración futura a decimal y política de redondeo |
| Crítica | Totales fiscales agregados, no por línea | modelos de sales/invoices | imposible representar CFDI fiel | modelo fiscal inmutable por concepto |
| Crítica | E-invoice genérico confundible con CFDI | `app/Libraries/E_invoice.php` | cumplimiento falso | aislar y etiquetar claramente |
| Alta | Redondeo inconsistente | `Invoices_model.php`: `number_format`, `TRUNCATE`, tolerancia | saldos discordantes | librería monetaria decimal única |
| Alta | Sin foreign keys SQL | `install1/database.sql` | huérfanos/integridad | agregar gradualmente tras limpiar datos |
| Alta | Sin transacciones visibles en flujos compuestos | conversión/copia de partidas/pagos | documentos parciales | transacciones de DB y pruebas de fallo |
| Alta | Rutas dinámicas exponen métodos públicos GET/POST | `app/Config/Routes.php` | superficie de ataque | rutas explícitas, verbos/CSRF/autorización auditados |
| Alta | Permisos serializados | `roles.permissions`/`Roles.php` | difícil consultar/migrar/auditar | normalización o capa estable |
| Alta | Campos fiscales genéricos ambiguos | VAT/GST/TDS/tax1-3 | mapeos erróneos | no reutilizar como SAT sin modelo nuevo |
| Alta | Moneda por cliente, no documento/pago | esquema | historia y FX incorrectos | snapshot de moneda/tipo de cambio |
| Alta | Falta de pruebas propias comprobables | no hay suite raíz/manifiesto | regresiones | tests de caracterización antes de refactor |
| Alta | Dependencias vendorizadas sin manifiesto raíz | `app/ThirdParty` | CVE/actualización irreproducible | SBOM y estrategia Composer/npm futura |
| Media | jQuery 3.5.1 y activos precompilados | `assets/js` | deuda/seguridad | inventario y actualización controlada |
| Alta | Lógica de negocio en controladores/modelos SQL | Clients/Invoices/Estimates/modelos | acoplamiento fiscal | servicios/DTO y repositorios graduales |
| Media | Archivos serializados/texto | `files`, columnas `files` | consulta/migración difícil | normalizar metadatos sensibles |
| Alta | Certificados futuros en filesystem común | arquitectura actual de archivos | exposición de CSD | almacén privado cifrado fuera de webroot |
| Media | Datos demo/templates en instalador | inserts de `database.sql`, contrato en inglés | contaminación | separar seed demo de producción |
| Alta | UTF8MB3 | todas las tablas | compatibilidad/caracteres | planificar utf8mb4 |
| Alta | Sin inventario/compras/proveedores | ausencia de módulos | RISE no cubre ERP completo | definir alcance antes de llamar base oficial |
| Alta | Pago N:1 solamente | `invoice_payments.invoice_id` | complemento de pago insuficiente | cabecera de pago + aplicaciones N:M futuras |
| Media | Cálculos también renderizados en vistas/JS | `*_total_section.php`, scripts inline | divergencia navegador/servidor | servidor como autoridad y pruebas cruzadas |

## 15. Archivos críticos priorizados

| Prioridad / ruta completa | Responsabilidad e importancia | Riesgo | ¿Probablemente se modificará? |
|---|---|---|---|
| 1 `C:\xampp\htdocs\ikontrol2\install1\database.sql` | fuente del modelo inicial | double, sin FK, sin fiscal MX | Sí, mediante migraciones futuras; no ahora |
| 2 `...\app\Models\Crud_model.php` | persistencia y cálculo común de ventas | SQL/acoplamiento | Probable refactor cuidadoso |
| 3 `...\app\Models\Invoices_model.php` | totales, saldos, estados, recurrencia | redondeo/agregados | Sí |
| 4 `...\app\Controllers\Invoices.php` | ciclo de factura/venta | lógica extensa y conversiones | Sí |
| 5 `...\app\Models\Invoice_items_model.php` y `invoice_payments_model.php` | partidas/pagos | modelo fiscal insuficiente | Sí |
| 6 `...\app\Controllers\Invoice_payments.php` | captura de pagos | N:1, sin FX/parcialidad fiscal | Sí |
| 7 `...\app\Libraries\E_invoice.php` | XML e-invoice genérico | confusión con CFDI/TDS | Revisar; preferible aislar |
| 8 `...\app\Controllers\Estimates.php` y modelos asociados | cotización/conversión | copia/snapshot parcial | Probable |
| 9 `...\app\Controllers\Clients.php`, `Libraries\Client.php`, `Models\Clients_model.php` | cliente/contactos | datos fiscales mezclados | Sí |
| 10 `...\app\Controllers\Items.php`, `Models\Items_model.php` | catálogo | sin SAT/costos/tipo | Sí |
| 11 `...\app\Models\Taxes_model.php`, `Controllers\Taxes.php` | tasas genéricas | insuficiente para impuestos MX | Sí o reemplazo paralelo |
| 12 `...\app\Libraries\Permission_manager.php`, `Controllers\Roles.php` | autorización | serialización/alcance fiscal | Probable |
| 13 `...\app\Config\Routes.php` | superficie HTTP | rutas dinámicas | Sí, endurecimiento |
| 14 `...\app\Config\Database.php` | conexión única | secretos/config directo | Sí, despliegue por instalación |
| 15 `...\app\Libraries\Cron_job.php` | recurrencias | podría duplicar documentos | Revisar/modificar |
| 16 `...\app\Helpers\currency_helper.php` | formato/conversión | float/redondeo | Sí |
| 17 `...\app\Views\invoices`, `estimates`, `clients`, `items`, `taxes`, `settings` | UI | mezcla administrativa/fiscal | Sí tras diseño |
| 18 `...\assets\js\app.js`, `general_helper.js` | framework UI/cálculos | dependencia cliente | Revisar; cambios puntuales |

## 16. Conclusiones

RISE es viable como base **CRM/PSA administrativa** de iKontrol2.0 si se acepta que no es Laravel ni un ERP fiscal mexicano. Clientes, contactos, usuarios, roles, proyectos, tareas, cotizaciones, facturas administrativas, pagos simples, gastos, tickets y reportes están suficientemente construidos para pruebas de caracterización y evolución controlada.

Necesitan limpieza prioritaria invoices/estimates/orders, impuestos, moneda, pagos, permisos serializados, rutas dinámicas y dependencias vendorizadas. Proveedores, compras, inventario, almacenes, sucursales y CxP no están construidos. La terminología VAT/GST/TDS/Invoice/Estimate/ZIP debe mexicanizarse primero a nivel conceptual y visual, sin renombrados técnicos precipitados.

Falta prácticamente todo el dominio CFDI: datos del emisor/receptor, catálogos SAT, conceptos fiscales, impuestos por concepto, moneda/FX por documento, serie/folio fiscal, CSD, PAC, XML/UUID, cancelación, relación de CFDI y complemento de pago. Antes de integrar un PAC deben resolverse aritmética decimal, redondeo, snapshots, transacciones, idempotencia, seguridad de certificados, integridad referencial y pruebas.

Deben conservarse inicialmente el flujo administrativo de clientes/contactos, permisos existentes mientras se caracteriza, proyectos/tareas/tickets, mecanismo de vistas y el esquema de instalación sólo como referencia histórica. No deben tocarse sin pruebas los helpers comunes, `Crud_model`, las conversiones y el cron. La integración fiscal debe añadirse como dominio paralelo explícito, no como reinterpretación silenciosa de VAT/GST/TDS.

## Anexo A. Archivos y carpetas inspeccionados

Se inspeccionaron inventarios completos de `app/Controllers`, `app/Models`, `app/Libraries`, `app/Helpers`, `app/Views`, `app/Config`, `app/Database`, `app/Language`, `assets/js`, `files`, `plugins`, `install1`, `system` (para versión) y `writable` (estructura). Lectura detallada: `Routes.php`, `Database.php`, `Rise.php`, `Events.php`, `Clients.php`, `Items.php`, `Estimates.php`, `Invoices.php`, `Invoice_payments.php`, `Orders.php`, `Taxes.php`, `Roles.php`, `Signin.php`, `Cron.php`, sus modelos/vistas centrales, `Permission_manager.php`, `Client.php`, `Cron_job.php`, `E_invoice.php`, helpers monetarios/archivos y `install1/database.sql`.

## Anexo B. Limitaciones y estado Git

- No se ejecutó la aplicación ni se inspeccionó una base instalada; estados “funcional” son inferencias estructurales respaldadas por código, no pruebas E2E.
- No hay `composer.json`/`package.json` raíz; versiones completas no son determinables de forma reproducible.
- `git status --short` y `git status` no pueden producir un estado porque `C:\xampp\htdocs\ikontrol2` **no es un repositorio Git** en la entrega. Resultado observado: `fatal: not a git repository (or any of the parent directories): .git`.
- No se modificó código funcional, rutas, vistas existentes, migraciones, dependencias o configuración; únicamente se añadió este documento y su carpeta `docs`.

