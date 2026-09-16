# Incremento 03 — Configuración fiscal de productos y servicios

## Resumen

Este incremento agrega preparación fiscal opcional a productos y servicios sin cambiar la operación administrativa de RISE. Un artículo sin datos SAT puede seguir creándose, cotizándose, vendiéndose y cobrándose. La revisión fiscal sólo informa readiness local; no genera CFDI, XML, CSD, timbrado ni documentos fiscales.

Punto de partida verificado: commit estable `7603a78` y etiqueta `ikontrol-2.0-incremento-02`.

## Auditoría inicial

La tabla `items` conserva `title`, `description`, `category_id`, `unit_type`, `rate`, `taxable`, archivos, visibilidad en portal, orden y borrado lógico. El formulario administrativo exige categoría; título y precio se validan en la interfaz existente. `unit_type` es texto comercial, `rate` sigue siendo el valor administrativo existente y `taxable` no se reutilizó como ObjetoImp.

Las tablas `estimate_items`, `invoice_items`, `order_items`, `proposal_items` y `subscription_items` conservan snapshots administrativos de título, descripción, cantidad, unidad, precio, total y orden. `invoice_items` también conserva `taxable`. RISE admite partidas manuales con `item_id = 0`; por ello la revisión fiscal las identifica como incompletas sin asignar claves inventadas.

Los productos se eliminan lógicamente y el listado usa `Items_model::get_details()`. Esta consulta se amplió con `LEFT JOIN` opcionales: instalaciones todavía no migradas siguen devolviendo productos legacy, y ningún artículo desaparece por carecer de configuración fiscal.

El catálogo `taxes` ya contiene configuración fiscal opcional y conserva `percentage` para cálculos administrativos. La nueva relación sólo admite en la interfaz impuestos con `use_for_fiscal = 1` e `is_fiscal_ready = 1`. No se modificaron el controlador, modelo, vistas ni servicio del CRUD de impuestos, ni los campos documentales `tax_id`, `tax_id2` y `tax_id3`.

## Migraciones y tablas

Las migraciones CI4 se ejecutan en este orden:

1. `2026-07-23-030000_CreateSatProductServiceKeys.php`: `sat_product_service_keys`.
2. `2026-07-23-030100_CreateSatUnitKeys.php`: `sat_unit_keys`.
3. `2026-07-23-030200_CreateSatTaxObjectCodes.php`: `sat_tax_object_codes`.
4. `2026-07-23-030300_CreateItemFiscalSettings.php`: configuraciones históricas 1:N por artículo.
5. `2026-07-23-030400_CreateItemFiscalTaxes.php`: relación configuración–impuesto.
6. `2026-07-23-030500_AddCompleteFiscalAddressToProfiles.php`: dirección fiscal complementaria independiente por perfil receptor.

Los índices y restricciones se crean en la migración propietaria de cada tabla: códigos únicos, búsquedas por vigencia/activo, artículo/configuración predeterminada y relación única configuración–impuesto. Los `down()` de tablas que pueden ser referenciadas son deliberadamente no destructivos; el rollback lógico debe planearse después de verificar dependencias. No se alteran `items`, `invoice_items` ni columnas administrativas y no se introducen `FLOAT` o `DOUBLE` fiscales.

## Catálogos, fuente y limitación

Los seeders iniciales incluían únicamente una **carga mínima de desarrollo**:

- ClaveProdServ: `01010101`, `43211503`, `81112100` (3 registros).
- ClaveUnidad: `H87`, `E48`, `KGM` (3 registros).
- ObjetoImp CFDI 4.0: `01`, `02`, `03`, `04` (4 registros).

Posteriormente se localizó una fuente más completa en la base local `factucare`: 52,841 filas de `clave_prod_serv` (52,839 claves distintas) y 2,418 filas de `clave_unidad`. No contiene vigencias, símbolos ni metadatos de versión SAT. El importador reutilizable consolida duplicados idénticos, preserva ceros iniciales, normaliza espacios, actualiza por código, no borra claves usadas y registra `FactuCare 2 database:factucare` como procedencia. Se ejecuta con:

```powershell
php spark db:seed Increment03ItemFiscalCatalogsSeeder
php spark fiscal:import-item-catalogs --source factucare
```

La importación local dejó 52,839 ClaveProdServ y 2,418 ClaveUnidad. Sigue pendiente cotejar esta fuente contra una publicación oficial SAT versionada antes de producción; no se inventaron vigencias.

## Modelo fiscal del artículo

`item_fiscal_settings` separa los datos SAT de `items` y conserva compatibilidad con las columnas históricas del incremento. La interfaz simplificada sólo permite capturar tipo `product|service`, ClaveProdServ, ClaveUnidad e impuestos aplicables. Unidad comercial, descripción, NoIdentificacion, ObjetoImp y estado no son entradas editables.

La unidad y descripción mostradas proceden de `items.unit_type` e `items.description` y son de sólo lectura. Las columnas fiscales anteriores se conservan para compatibilidad y futura migración, pero el servidor ignora valores manipulados enviados para ellas.

`item_fiscal_taxes` relaciona cero o más impuestos existentes, elimina duplicados de entrada y usa una restricción única por configuración e impuesto. No copia tasas a `items` ni calcula impuestos fiscales.

## Estados y readiness

- `not_configured`: no existe configuración predeterminada activa.
- `incomplete`: faltan datos o hay referencias incoherentes.
- `ready`: pasa las validaciones locales de este incremento.
- `inactive`: la configuración existe pero no puede utilizarse.

`ItemFiscalReadinessService` devuelve `item_id`, `configuration_id`, `status`, `is_ready`, `errors`, `warnings`, `missing_fields` y el ObjetoImp derivado. Valida existencia del producto, tipo, catálogos activos, descripción administrativa, duplicados e impuestos fiscalmente listos. Sin impuestos deriva `01`; con uno o más impuestos deriva `02`. Los valores históricos explícitos `03` y `04` se conservan y se señalan como configuración avanzada pendiente de validación normativa.

El estado `ready` significa “listo según comprobaciones locales”, no validación del SAT ni autorización para timbrar.

## Interfaz y permisos

`/items` muestra estado, ClaveProdServ, ClaveUnidad y ObjetoImp. La acción **Configuración fiscal** abre un formulario separado del formulario administrativo. ClaveProdServ y ClaveUnidad usan Select2 remoto por POST, exigen al menos tres caracteres, aplican una espera de 350 ms y paginan con máximo de 50 resultados; no se cargan catálogos grandes en HTML.

Permisos nuevos:

- `fiscal_items_view`: consultar configuración y readiness.
- `fiscal_items_manage`: guardar configuración.

La ausencia de clave deniega acceso, por lo que roles existentes no reciben permisos automáticamente. El administrador global conserva acceso conforme a RISE. El permiso administrativo de productos no concede por sí solo acceso SAT.

Rutas explícitas:

- `POST /fiscal/items/form`
- `POST /fiscal/items/save`
- `POST /fiscal/items/activate`
- `POST /fiscal/items/deactivate`
- `GET /fiscal/items/readiness/{item_id}`
- `POST /fiscal/catalogs/product-service/search`
- `POST /fiscal/catalogs/units/search`
- `GET /fiscal/invoices/review/{invoice_id}`

## Revisión fiscal de venta

La acción **Revisión fiscal** está disponible en la vista de venta para administradores o usuarios con permiso de consulta. `InvoiceFiscalReviewService` consume el readiness del perfil receptor y evalúa cada `invoice_item` por su `item_id`. Muestra cliente, total de partidas, listas/incompletas, total administrativo de referencia y estados `not_ready`, `ready_with_warnings` o `ready`.

Una partida manual (`item_id = 0`) se marca incompleta y requiere una captura fiscal específica futura. No recibe datos de otro producto. La revisión no bloquea la venta administrativa, no recalcula importes y no muestra una acción “Timbrar”.

## Snapshot futuro no implementado

Al preparar un CFDI futuro se deberán copiar a un snapshot inmutable la clave SAT, unidad SAT, descripción, cantidad, valor unitario, descuento, ObjetoImp, impuestos y NoIdentificacion. El documento fiscal no deberá depender de la configuración viva del producto. En este incremento no existen `fiscal_documents`, partidas fiscales, impuestos fiscales documentales, XML ni UUID.

## Pruebas y aislamiento

`tests/Increment03/database_integration.php` crea una base temporal con nombre aleatorio, clona el esquema local verificado, ejecuta fixtures sólo allí y la elimina mediante un handler de cierre. No consume IDs ni modifica datos de desarrollo. Comprueba catálogos, idempotencia, búsquedas, producto legacy, estados incomplete/ready/inactive, ObjetoImp 01/02, impuesto válido/inválido, duplicados, configuración predeterminada, permisos y revisión con partida manual.

`tests/Increment03/run.php` revisa migraciones, rutas, permisos, ausencia de tablas/acciones fiscales finales y confirma que el CRUD de impuestos no forma parte del diff.

Comandos:

```powershell
php tests\Increment03\run.php
php tests\Increment03\database_integration.php
php tests\Increment03\corrective_http.php
```

También deben ejecutarse las regresiones de Incrementos 0 y 2, lint PHP, `php spark routes`, `php spark migrate:status` y `git diff --check`.

## Revisión manual

### Producto sin configuración

1. Abrir `/items` y crear un producto sin datos SAT.
2. Confirmar el badge **Sin configuración fiscal**.
3. Usarlo en una cotización y aceptar/convertirla en venta.
4. Confirmar que cotización, venta y cobro administrativo no se bloquean.

### Producto listo

1. En `/items`, usar la acción **Configuración fiscal**.
2. Seleccionar Producto, una ClaveProdServ disponible, H87 (u otra unidad) y, si aplica, un IVA 16% fiscalmente listo.
3. Confirmar en el resumen la unidad y descripción administrativas, el ObjetoImp derivado (`01` sin impuestos o `02` con impuestos) y el estado calculado.
4. Guardar, recargar y confirmar **Completo para facturar**.

### Producto incompleto

1. Crear configuración sólo con ClaveProdServ.
2. Guardar y confirmar **Incompleto para facturar**.
3. Consultar `/fiscal/items/readiness/{item_id}` con una sesión autorizada y revisar los faltantes deterministas.

### Revisión de venta

1. Crear una venta administrativa con un producto listo, uno incompleto y opcionalmente una partida manual.
2. Abrir `/invoices/view/{invoice_id}`.
3. Pulsar **Revisión fiscal**.
4. Confirmar el estado por partida, el total administrativo informativo y que no existe botón **Timbrar** ni se genera CFDI.

### Permisos

1. Abrir `/roles` como administrador.
2. Conceder sólo “Ver configuración fiscal de productos” a un rol de prueba y verificar acceso de sólo lectura.
3. Conceder “Administrar configuración fiscal de productos” y verificar guardado.
4. Confirmar que un rol anterior sin ambas claves puede seguir usando productos administrativamente, pero no abrir la configuración SAT.

## Riesgos y pendientes

- Cotejar y versionar formalmente los catálogos importados desde FactuCare contra una fuente oficial SAT antes de producción.
- Definir con fuente normativa la validación detallada de ObjetoImp 03 y 04.
- Diseñar overrides fiscales para partidas manuales.
- Crear snapshots sólo en la fase de documento fiscal, con cálculo fiscal por concepto.
- Probar manualmente longitudes y responsividad del listado con el catálogo completo.

No se implementó PAC, conexión externa, CFDI, XML, CSD, certificados, series, folios fiscales ni timbrado.

## Dirección fiscal completa por perfil

La auditoría confirmó que `fiscal_profiles` ya almacenaba RFC, razón social, régimen, código postal fiscal, Uso CFDI, residencia fiscal extranjera, vigencias y estado, pero no una dirección complementaria completa. La dirección comercial permanece en `clients.address`, `city`, `state`, `zip` y `country`; no se reutiliza ni se copia en el servidor.

La migración correctiva `2026-07-23-030500_AddCompleteFiscalAddressToProfiles.php` agrega sólo cuando faltan:

- `fiscal_street`
- `fiscal_external_number`
- `fiscal_internal_number`
- `fiscal_neighborhood`
- `fiscal_locality`
- `fiscal_municipality`
- `fiscal_state`
- `fiscal_country_code`
- `fiscal_address_reference`

Se conserva `fiscal_postal_code`. Todas las columnas complementarias son nullable y no se copiaron datos desde `clients`. Cada perfil conserva sus propios valores, por lo que dos razones sociales del mismo cliente pueden tener domicilios distintos.

No existe todavía un catálogo SAT/ISO de países en el proyecto. `fiscal_country_code` conserva temporalmente una clave alfabética de tres letras. `MEX` aparece únicamente como valor inicial de un perfil nuevo; un perfil existente sin país continúa vacío. Antes de producción fiscal debe incorporarse un catálogo formal y versionado.

El readiness receptor mantiene como mínimos RFC, razón social, régimen fiscal, código postal fiscal y Uso CFDI. La falta de calle, colonia, municipio, estado o país agrega una advertencia de expediente, nunca un error fiscal ni un bloqueo administrativo.

El botón **Copiar dirección comercial** requiere un clic explícito y sólo rellena el formulario para revisión. Mapea `clients.address` a calle, `city` a municipio, `state` a estado y `zip` a código postal. No guarda automáticamente, no intenta separar números/colonia y no cambia el país fiscal.

Pruebas aisladas verifican dos perfiles del mismo cliente con domicilios diferentes, edición independiente, ausencia de copia automática, readiness mínimo con advertencias y apertura de perfiles anteriores con columnas nuevas en NULL.

## Corrección del formulario fiscal de productos

### Traza y causa

El request real es `POST /fiscal/items/form`, ruta explícita hacia `App\Controllers\Fiscal\ItemSettings::form()`. Para un producto legacy, `Item_fiscal_settings_model::activeForItem()` devolvía `null`; el controlador lo reemplazaba por `(object) []` y accedía inmediatamente a `$model->id`. PHP generó:

```text
ErrorException: Undefined property: stdClass::$id
APPPATH/Controllers/Fiscal/ItemSettings.php:13
ItemSettings->form()
CodeIgniter->runController()
```

La excepción quedó registrada como `CRITICAL` en `writable/logs/log-2026-07-22.log`. No era un problema de namespace, ruta, migración, seeder, permisos ni catálogo vacío.

### Solución

El controlador crea ahora un modelo vacío explícito con `id = 0` y obtiene el identificador mediante acceso null-safe. Antes de cargar catálogos comprueba que el artículo exista. Un ID inexistente devuelve HTML seguro con HTTP 404 y registra un warning; una excepción inesperada registra clase, mensaje, archivo y línea, y devuelve al modal un mensaje seguro sin stack trace.

Se probó mediante el controlador HTTP real: producto legacy, configuración inexistente/existente, ID inválido, creación incompleta, configuración completa, reapertura, selección de catálogos, impuesto fiscal listo, badge `ready`, desactivación, catálogos activos vacíos y denegación de permisos.

### Decisión sobre GET

El modal se abre mediante `modal_anchor`, que envía POST con `item_id`. Se mantiene únicamente `POST /fiscal/items/form`. `GET /fiscal/items/form` continúa devolviendo 404 deliberadamente; no existe navegación directa que justifique ampliar la superficie de rutas.

La advertencia `aria-hidden`, si el navegador la muestra, es secundaria al ciclo de foco del modal y no fue la causa del 500.

## Simplificación de configuración fiscal

### Auditoría de catálogos

La base local verificada contenía inicialmente 3 claves activas de producto/servicio, 3 claves activas de unidad y los 4 códigos de ObjetoImp. Aunque el repositorio de FactuCare sólo contiene los modelos, la instancia MySQL local sí conserva las tablas fuente completas disponibles. La importación elevó los conteos a 52,839 y 2,418 respectivamente; ObjetoImp permaneció en 4.

### Formulario reducido y datos derivados

El formulario captura exclusivamente:

- tipo de artículo: producto o servicio;
- ClaveProdServ;
- ClaveUnidad;
- impuestos fiscales aplicables.

Se retiraron como entradas editables unidad comercial, descripción fiscal, número de identificación, ObjetoImp y estado. La unidad y descripción administrativas se muestran en un resumen de sólo lectura. El backend aplica la misma lista permitida y descarta esos campos aunque un cliente manipule el POST.

ObjetoImp se deriva en el servidor: `01` cuando no hay impuestos relacionados y `02` cuando existe al menos uno. Una configuración histórica explícita `03` o `04` se conserva sin convertirla y muestra una advertencia avanzada. El estado también se calcula: **Sin configuración fiscal**, **Incompleto para facturar**, **Completo para facturar** o **Configuración inactiva**. Activar y desactivar son acciones explícitas independientes del guardado.

Los impuestos disponibles incluyen nombre administrativo, clave SAT, traslado/retención, factor y tasa o cuota XML. Sólo se admiten impuestos activos, marcados para uso fiscal y fiscalmente listos. El CRUD y los cálculos del catálogo `taxes` no fueron modificados.

### Búsqueda remota

Las búsquedas de ClaveProdServ y ClaveUnidad usan únicamente POST, requieren tres caracteres, tienen debounce de 350 ms y respuesta paginada compatible con Select2. La edición precarga la selección vigente aunque la clave haya quedado inactiva, para no perder trazabilidad histórica. No se añadió una ruta GET.

### Pruebas de simplificación

Las pruebas aisladas cubren ausencia de campos retirados, manipulación de POST, derivación `01`/`02`, preservación `03`/`04`, impuestos duplicados, búsqueda mínima y paginada, claves históricas inactivas, activación/desactivación explícita, catálogos vacíos y permisos. Las pruebas usan una base temporal que se elimina al terminar y no consumen IDs de desarrollo.

## Corrección de catálogos y Select2 remoto

### Versión y causa

RISE incluye un fork basado en **Select2 3.5.2**. La comparación se realizó contra el artefacto oficial 3.5.2; el archivo local conserva modificaciones propias y su encabezado no fue compilado (`Version: @@ver@@`), por lo que no debe describirse como el binario oficial intacto. Su API comprobable es 3.x: `quietMillis`, `results(data, page)` e `initSelection`.

El error `Option 'ajax' is not allowed for Select2 when attached to a <select> element` se producía porque el modal aplicaba AJAX a elementos `<select>` y además usaba opciones 4.x (`delay` y `processResults`). Select2 3.x sólo admite este origen remoto sobre un input. Los dos campos SAT ahora son `<input type="hidden">` y reutilizan el patrón de RISE: POST, `minimumInputLength: 3`, `quietMillis: 350`, `results` e `initSelection`. No se modificó `app.all.js` ni la biblioteca Select2.

### Catálogos e importación

Fuente encontrada:

- `factucare.clave_prod_serv`: 52,841 filas, columnas `id`, `clave`, `descripcion`, UTF-8/utf8mb4; 52,839 claves distintas, sin claves/descripciones vacías ni claves de longitud inválida. `53112000` aparece tres veces con la misma descripción.
- `factucare.clave_unidad`: 2,418 filas, columnas `id`, `clave`, `descripcion`, UTF-8; sin duplicados ni valores vacíos.

La fuente no incluye vigencias, nombre separado, símbolo ni versión SAT. `valid_from`, `valid_to` y `symbol` permanecen NULL; para unidades el texto fuente alimenta temporalmente `name` y `description`. La primera importación insertó 52,836 productos/servicios y 2,415 unidades, y actualizó los tres fixtures preexistentes de cada tabla. Una ejecución posterior comprobó 0 inserciones y 0 actualizaciones.

Los nombres lógicos usados por modelos y Query Builder son `sat_product_service_keys`, `sat_unit_keys` y `sat_tax_object_codes`. Con `DBPrefix=ikontrol_`, sus nombres físicos son `ikontrol_sat_product_service_keys`, `ikontrol_sat_unit_keys` e `ikontrol_sat_tax_object_codes`. No se codifica el prefijo físico, evitando duplicarlo.

### Endpoints, selección y ObjetoImp

- `POST /fiscal/catalogs/product-service/search`
- `POST /fiscal/catalogs/units/search`

La respuesta compatible con Select2 3.x contiene `results` y `more`. Las consultas sólo se ejecutan desde tres caracteres, filtran activos, limitan y paginan resultados, y ordenan coincidencia exacta, prefijo de clave, prefijo de descripción y coincidencia parcial. `initSelection` recibe exclusivamente el ID y texto ya resueltos por el servidor; no carga el catálogo completo y permite mostrar una clave histórica inactiva.

El resumen escucha el cambio real del multiselect de impuestos mediante sus opciones seleccionadas. Sin impuestos muestra ObjetoImp `01`; al seleccionar IVA muestra inmediatamente `02` como previsión. Al guardar, el servidor ignora cualquier ObjetoImp enviado, vuelve a derivarlo, actualiza `item_fiscal_taxes` y recalcula readiness. Los overrides históricos `03` y `04` se conservan.
