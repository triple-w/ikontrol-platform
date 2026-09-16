# Incremento 06: borradores fiscales y snapshots

## Cierre del incremento anterior

Los Incrementos 4 y 5 se validaron con 354 verificaciones exitosas. Se creó el commit `f441987` (`feat: add fiscal pricing policies and sale tax simulation`) y la etiqueta anotada `ikontrol-2.0-incremento-05`. No se hizo push. El Incremento 6 permanece sin commit.

## Auditoría

La venta administrativa aporta cliente, empresa, cotización/proyecto de referencia, partidas, cantidades, descripciones, precios, descuentos, impuestos administrativos, moneda, fechas y total. `invoice_payments` conserva los pagos; el saldo se deriva de `invoice_total - SUM(invoice_payments.amount)`.

Los perfiles fiscales aportan RFC, razón social, régimen, código postal y domicilio. El receptor aporta Uso CFDI. `item_fiscal_settings` y `item_fiscal_taxes` aportan ClaveProdServ, ClaveUnidad, ObjetoImp y configuración exacta de impuestos. `sale_fiscal_pricing_preparations` aporta el cálculo fiscal determinista y la política de precios.

No se reutilizan silenciosamente VAT/GST, métodos de pago administrativos ni direcciones comerciales. Forma y método SAT se capturan expresamente. Una partida manual sin `item_id` queda bloqueada porque todavía no existe un override fiscal por partida.

## Modelo persistente

- `fiscal_documents`: encabezado, estado, versión, serie/folio, moneda, pago, totales y hash.
- `fiscal_document_issuers`: snapshot del emisor.
- `fiscal_document_receivers`: snapshot del receptor.
- `fiscal_document_items`: conceptos congelados.
- `fiscal_document_item_taxes`: impuestos por concepto.
- `fiscal_document_tax_totals`: agrupación determinista.
- `fiscal_document_metadata`: referencias administrativas, advertencias, versión de reglas y pago observado.
- `fiscal_document_audit`: creación, consulta, cierre, reemplazo, cancelación e intentos fallidos.

Todos los importes nuevos usan `DECIMAL`. No se modificaron `invoices`, `invoice_items`, `clients`, `items`, `taxes` ni `invoice_payments`.

## Estados e inmutabilidad

Los estados operativos son `draft`, `ready`, `locked`, `superseded` y `cancelled_internal`. `stamped` y `stamping_error` están reservados, pero no se usan.

La creación completa produce `ready`. Un documento `ready` puede cerrarse y pasa a `locked`. No existe endpoint para editar snapshots. Un documento cerrado sólo puede conservarse o reemplazarse mediante una nueva preparación confirmada; el anterior pasa a `superseded`.

Editar posteriormente cliente, perfil, producto, impuesto, emisor o venta no modifica las filas snapshot. El hash SHA-256 identifica exactamente el origen utilizado; no es sello digital.

## Folios e idempotencia

La serie se lee con `SELECT ... FOR UPDATE`. El contador, el encabezado y todos los snapshots se escriben dentro de una única transacción. No se utiliza `MAX + 1`.

El folio sólo se consume cuando la transacción completa confirma. Un rollback restaura el contador. La restricción única es emisor + tipo + serie + folio.

La misma venta, preparación y opciones producen el mismo hash y devuelven el documento existente. Si el origen cambia, se requiere confirmación y permiso de reemplazo. Los documentos `locked` nunca se modifican.

## Forma de pago, método y moneda

Se agregaron catálogos mínimos versionados:

- `sat_payment_forms`: 01, 03, 04, 28 y 99.
- `sat_payment_methods`: PUE y PPD.
- `sat_currencies`: MXN, USD y EUR.

Los valores no están hardcodeados en la vista. MXN no requiere tipo de cambio. USD y EUR exigen un valor decimal positivo proporcionado por el usuario; no existe consulta externa. `export_code` queda persistido como `01`, sin implementar comercio exterior.

## Totales

El servicio usa `FiscalDecimalCalculator`, sin `float`. Valida:

`subtotal - descuento + traslados - retenciones = total`

Los impuestos se calculan por concepto y se agrupan por clave, tipo, factor y tasa/cuota. Tasa cero y Exento producen importe cero. Las cantidades deben ser positivas. Una diferencia impide confirmar la transacción.

## Permisos

- `fiscal_drafts_view`
- `fiscal_drafts_create`
- `fiscal_drafts_lock`
- `fiscal_drafts_supersede`
- `fiscal_drafts_cancel`

Los roles existentes no reciben claves nuevas. El administrador global conserva el comportamiento administrativo de RISE.

## Interfaz

La revisión fiscal permite seleccionar emisor, receptor, serie, forma SAT, método SAT, moneda y tipo de cambio. Sólo muestra “Crear borrador fiscal” cuando readiness y simulación están completos.

La sección “Preparaciones fiscales” muestra serie, folio, fecha, total, estado y acceso de lectura. La vista presenta documento, emisor, receptor, conceptos, impuestos y totales, con el aviso:

> Este documento es una preparación fiscal interna. Todavía no es un CFDI y no tiene validez fiscal.

No existe botón Timbrar, XML ni envío al PAC.

## Pruebas y aislamiento

`tests/Increment06/database_integration.php` clona la base local a una base temporal de nombre aleatorio, ejecuta migraciones y elimina la base al finalizar. No consume IDs ni folios de desarrollo.

Se prueban migraciones, snapshots, conciliación exacta, hash, idempotencia, folio transaccional, rollback, inmutabilidad, cierre, permisos y ausencia de XML/PAC/UUID. Las regresiones 0–5 se ejecutan por separado.

Limitación: no se ejecuta una carrera real con dos procesos del sistema operativo; la unicidad de base, el bloqueo de fila y la transacción cubren la prevención estructural de duplicados. Tampoco se implementan overrides para partidas manuales.

## Revisión manual

1. Abra una venta fiscalmente lista y pulse **Revisión fiscal**.
2. Seleccione emisor, serie y receptor.
3. Seleccione Forma de pago SAT y Método de pago SAT.
4. Confirme la moneda; capture tipo de cambio si no es MXN.
5. Pulse **Crear borrador fiscal**.
6. En **Preparaciones fiscales**, abra la serie y folio creados.
7. Revise emisor, receptor, conceptos, impuestos y total.
8. Edite temporalmente el nombre del producto administrativo y reabra el borrador: el snapshot debe conservar su descripción.
9. Pulse **Cerrar preparación fiscal**.
10. Confirme que queda `Cerrado` y no puede editarse.
11. Confirme que no existe botón Timbrar, XML, UUID ni conexión PAC.

## Fuera de alcance

No se genera XML, no se conecta PAC, no se timbra, no se carga CSD, no se sella, no se obtiene UUID, no se cancela ante SAT y no se implementan complementos de pago ni notas de crédito.
## Corrección de visualizador y traducciones

### Causa del 404

La tabla de preparaciones construía el enlace `fiscal/invoices/drafts/{fiscal_document_id}` mediante `modal_anchor()`. Este helper de RISE abre `#ajaxModal` mediante **POST**, mientras que `FiscalRoutes.php` sólo registraba esa URL como **GET**. CodeIgniter rechazaba por ello `POST /fiscal/invoices/drafts/1` antes de ejecutar el controlador.

El número enviado por la tabla era correctamente `fiscal_documents.id`; no era el folio, el índice de fila ni `invoices.id`.

La ruta final es:

```text
POST /fiscal/invoices/drafts/{fiscal_document_id}/view
App\Controllers\Fiscal\InvoiceReview::draft($id)
```

No se agregó GET: la interacción real es un modal AJAX de RISE y no existe navegación independiente que lo requiera. `Routes.php` continúa cargando una sola vez `Config/FiscalRoutes.php`.

### Visualizador y permisos

El controlador valida un entero positivo, obtiene el documento con `Fiscal_documents_model::complete()`, exige `fiscal_drafts_view` y presenta exclusivamente:

- encabezado congelado;
- snapshot del emisor;
- snapshot del receptor;
- conceptos e impuestos por concepto;
- resumen de impuestos;
- metadata de la preparación.

No utiliza perfiles, productos o impuestos vivos como fallback. Un ID inválido o inexistente devuelve contenido seguro compatible con el modal y registra el detalle técnico; no inserta una página 404 completa en la interfaz. Los usuarios sin permiso conservan el flujo de acceso denegado de RISE.

### Estrategia del modal y foco

El botón **Ver** envía POST y reemplaza `#ajaxModalContent` dentro del modal ya abierto. No crea otro `#ajaxModal`. Antes del reemplazo retira el foco del botón y, al terminar, enfoca el primer control visible. El visualizador ofrece **Volver a revisión fiscal** mediante el mismo contenedor. Esto evita ocultar un modal que todavía conserva el foco y trata la advertencia `aria-hidden` en su causa local, sin modificar Bootstrap ni `app.all.js`.

### Traducciones

Las claves de los Incrementos 6 y 7 habían sido agregadas después de `return $lang;` en ambos `default_lang.php`; por ello CodeIgniter devolvía literalmente `default_lang.*`. El retorno quedó al final de cada archivo. Se completaron además las claves del visualizador en español e inglés:

| Clave | Español | Inglés |
|---|---|---|
| `view_fiscal_draft` | Ver borrador fiscal | View fiscal draft |
| `close_fiscal_preparation` | Cerrar preparación fiscal | Close fiscal preparation |
| `series` | Serie | Series |
| `folio` | Folio | Folio |
| `fiscal_document_type_income` | Ingreso | Income |
| `product_service_key` | ClaveProdServ | Product/service key |
| `unit_key` | ClaveUnidad | Unit key |
| `unit_value` | Valor unitario | Unit value |
| `tax_summary` | Resumen de impuestos | Tax summary |
| `back_to_fiscal_review` | Volver a revisión fiscal | Back to fiscal review |

Las traducciones existentes de creación, forma/método de pago, tipo de cambio, estados, subtotal y preparaciones ya estaban declaradas, pero eran inalcanzables por la posición incorrecta del `return`.

### Revisión manual

1. Abrir una venta y su **Revisión fiscal**.
2. Confirmar que no aparece texto `default_lang.*`.
3. Crear o localizar una preparación fiscal.
4. Pulsar **Ver** y confirmar que abre sin 404.
5. Revisar documento, emisor, receptor, conceptos, impuestos y totales.
6. Usar **Volver a revisión fiscal**.
7. Cerrar el modal y confirmar que no aparece la advertencia de foco.
8. Confirmar que no existe botón **Timbrar**.

Esta corrección no cambia cálculos, snapshots, impuestos, folios, ventas ni estados internos. Tampoco incorpora PAC, XML, CFDI, CSD o timbrado.
