# Incremento C1.3 — Módulo formal de Facturas

Fecha: 2026-07-27

## Resultado

Se creó el módulo independiente `Facturación → Facturas`. Su listado, detalle,
descarga XML, generación PDF fake, preview y descarga PDF reutilizan el dominio
fiscal existente y no dependen del controlador de ventas.

No se realizó timbrado, cancelación, consulta PAC/SAT ni conexión externa.
`fiscal.allowRealPac` permanece en `false`, `fiscal.pacAdapter` en `fake`,
`fiscal.pdf.provider` en `fake` y WSTools33 deshabilitado.

## Arquitectura reutilizada

- `FiscalInvoiceCenterQueryService`: proyección de listado y detalle sin cargar
  XML ni Base64.
- `FiscalDocumentStatusPresenter`: estado fiscal visible.
- `FiscalPacPdfGenerationService`: intento durable, idempotencia y persistencia.
- `FakePacPdfGenerationAdapter`: representación PDF local válida.
- `PacPdfArtifactService` y `PacPdfValidator`: persistencia y validación.
- `FiscalPdfTemplateResolver`: plantilla por emisor/tipo/proveedor.
- `Stamping`: descarga XML y endpoints PDF protegidos.
- Tablas fiscales existentes de documentos, snapshots, intentos y artefactos.

La fábrica PDF permite ahora seleccionar el adaptador fake sin habilitar
`MULTIPAC_TOOLS_ENABLED`; esa bandera sigue siendo obligatoria para el adaptador
`timbradorxpress-tools`.

## Rutas

- `GET fiscal/invoices` — listado formal.
- `POST fiscal/invoices/list` — datos filtrados del listado.
- `GET fiscal/invoices/{document}` — detalle.
- `GET fiscal/stamping/xml/download/{document}` — XML timbrado.
- `POST fiscal/documents/{document}/pdf/generate` — generación PDF con CSRF.
- `GET fiscal/documents/{document}/pdf/preview` — PDF inline.
- `GET fiscal/documents/{document}/pdf/download` — PDF adjunto.
- `GET fiscal/pdf-templates` — plantillas PDF.

## Controlador y vistas

`App\Controllers\Fiscal\InvoiceModule` contiene sólo responsabilidades del
módulo fiscal: listado, detalle, presentación de estados y disponibilidad de
acciones. No renderiza ni delega el listado al controlador de ventas.

Vistas:

- `app/Views/fiscal/invoices/module_index.php`;
- `app/Views/fiscal/invoices/show.php`.

La interfaz conserva el estilo de RISE: card, tabla responsive/appTable,
filtros colapsables, badges, dropdown de acciones y confirmación antes de
generar PDF.

## Listado y filtros

Columnas:

- serie, folio, tipo, fecha;
- cliente y RFC receptor;
- subtotal, impuestos y total;
- UUID;
- estado fiscal, PDF y cancelación;
- acciones.

Filtros:

- búsqueda general;
- serie, folio, UUID, cliente y RFC;
- fecha desde/hasta;
- tipo CFDI;
- estado fiscal;
- estado PDF;
- estado de cancelación.

La consulta tiene límite paginado acotado y appTable conserva los filtros
durante la paginación. Su `SELECT` excluye expresamente XML, `content_base64` y
cualquier secreto.

## Estados

La presentación mapea los estados implementados:

- fiscal: borrador, listo, enviando, timbrado, error, desconocido y cancelado;
- PDF: pendiente, procesando, disponible, error y desconocido;
- cancelación: no solicitada, solicitada, pendiente, aceptada, rechazada,
  cancelada y desconocida.

Los estados futuros están preparados en la capa visual, sin crear transiciones
ni persistir estados artificiales.

## Acciones y permisos

Permisos:

- `fiscal_invoices_view`;
- `fiscal_invoice_view`;
- `fiscal_xml_download`;
- `fiscal_pdf_generate`;
- `fiscal_pdf_view`;
- `fiscal_pdf_download`;
- `fiscal_cancel_request`;
- `fiscal_cancellation_receipt_view`;
- `fiscal_status_query`.

El menú sólo muestra Facturas o Plantillas PDF cuando el usuario tiene el
permiso correspondiente. Las acciones de cada fila también se omiten sin
permiso.

Generar PDF aparece únicamente con UUID, XML timbrado, sin PDF activo, estado
recuperable y sin intento PDF desconocido. Cancelar y Consultar estatus se
muestran deshabilitados y no invocan servicios.

## Flujo PDF fake validado

Sobre el fixture C1.2, documento 21:

- intento PDF durable: 1;
- proveedor: `fake`;
- código: 210;
- artefacto PDF: 5;
- tamaño: 60,729 bytes;
- SHA-256: `47dacdefc8d8b5dc5c78db140a10a5d0ff1859598f9ca038d8d0bbff50381425`;
- UUID sin cambios;
- hash XML sin cambios;
- stamp attempts: 6 → 6.

Una segunda ejecución devolvió `existing`; no creó otro intento ni artefacto.
Preview y download fueron validados por la regresión C1, incluyendo MIME,
disposición, longitud y bytes idénticos.

## Pruebas

- C1.3: 42 aprobadas.
- A1: 8 aprobadas.
- A2: 16 aprobadas.
- B: 20 aprobadas.
- C1: 42 aprobadas.
- C1.1: 38 aprobadas.
- Total ejecutado: 166 aprobadas, 0 fallidas.

Las pruebas cubren permisos, rutas, filtros, ausencia de Base64 en consultas,
reglas del botón, CSRF, doble clic, idempotencia, PDF fake, preview/download,
integridad UUID/XML, ausencia de stamp attempts y cero conexiones externas.

## Limitaciones y preparación para servidor

- Cancelación y consulta de estado no se activan desde este módulo.
- WSTools33 permanece preparado pero deshabilitado.
- El detalle no carga XML completo; ofrece la descarga protegida.
- Antes de desplegar deben asignarse los nuevos permisos a roles concretos.
- En servidor debe mantenerse el proveedor `fake` hasta autorizar y configurar
  expresamente un transporte real seguro.

No se realizó commit ni push.

## Corrección funcional del listado (2026-07-27)

### Causa raíz

El listado vacío no se debía a ausencia de documentos. Se encontraron tres
defectos independientes:

1. Los subqueries de los joins de cancelación e intentos PDF usaban nombres
   físicos sin el prefijo configurado. La consulta intentaba acceder a tablas
   sin `ikontrol_` y terminaba con una excepción.
2. La autorización de cada fila reutilizaba la relación comercial
   `invoice_id`; por ello un documento fiscal importado sin venta, como el 21,
   quedaba excluido para usuarios no administradores.
3. Las claves del menú C1.3 estaban después de `return $lang;`, por lo que
   nunca formaban parte del arreglo de idioma cargado.

### Fuente y consulta corregida

La fuente principal es `ikontrol_fiscal_documents`. La consulta corregida:

- parte directamente de `fiscal_documents d`;
- conserva únicamente `d.deleted = 0` como condición inicial;
- usa `LEFT JOIN` para receptor, timbre, cancelación e intento PDF;
- usa nombres prefijados en los subqueries;
- no exige venta, cliente administrativo, pago, UUID ni PDF;
- identifica fixtures con `EXISTS`, sin cargar metadata, XML o Base64.

El diagnóstico local devolvió 17 documentos, con IDs:
`1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 21`.

| ID | Emisor | Tipo | Estado | PDF | UUID | XML | PDF activo |
|---:|---:|---|---|---|---|---|---|
| 9 | 2 | income | stamping | — | no | no | no |
| 10 | 2 | income | stamping | — | no | no | no |
| 12 | 2 | income | ready_to_stamp | — | no | no | no |
| 13 | 2 | income | stamped | pending | sí | sí | no |
| 14 | 2 | income | stamped | valid | sí | sí | sí |
| 21 | 2 | income | stamped_pdf_pending | pending | sí | sí | no |

El documento 21 lleva el badge `Prueba importada`, no tiene intento PDF
desconocido y no depende de una venta. Cumple la regla visible de generación;
con el proveedor local se etiqueta `Generar PDF de prueba`.

### Interfaz corregida

- Menú traducido: `Facturación`, `Facturas` y `Plantillas PDF`.
- Un solo buscador etiquetado; la búsqueda propia de DataTables está
  deshabilitada.
- Tipo CFDI es un select inicialmente en `Todos`; envía `I/E/P/T/N`.
- Fechas renderizadas como `dd/mm/yyyy`; los inputs nativos conservan
  `yyyy-mm-dd`.
- Tabla reducida a las columnas operativas y UUID abreviado.
- Estado vacío explicativo y botón `Limpiar filtros`.
- El menú `Acciones` permanece visible; cancelar y consultar estatus siguen
  deshabilitados.

### Prueba visible del PDF fake

Se restauró primero el fixture 21 a pendiente retirando exclusivamente el
intento/artefacto fake previo. Después se ejecutó una generación local por el
mismo servicio usado por `POST /fiscal/documents/21/pdf/generate`:

- adaptador: `FakePacPdfGenerationAdapter`;
- resultado: éxito, código local 210;
- intento PDF temporal: 2;
- artefacto PDF temporal: 6;
- PDF válido: 60,729 bytes;
- preview y download: validados por C1;
- UUID y hash XML: idénticos antes/después;
- stamp attempts: 6 → 6;
- timbres consumidos: 0;
- llamadas externas: 0.

Al terminar se eliminaron transaccionalmente sólo el intento 2 y el artefacto
6. El documento 21 quedó otra vez en `stamped_pdf_pending`, sin PDF activo y
con la acción de generación disponible. XML y UUID no fueron modificados.

### Pruebas posteriores a la corrección

- C1.3: 53 aprobadas.
- C1.1: 38 aprobadas.
- C1: 42 aprobadas.
- B: 20 aprobadas.
- A2: 16 aprobadas.
- A1: 8 aprobadas.
- Total: 177 aprobadas, 0 fallidas.

La ejecución mantuvo el proveedor PDF fake, `fiscal.allowRealPac=false`,
WSTools33 deshabilitado y HTTP inseguro deshabilitado. No hubo commit ni push.

## Seguimiento definitivo del listado vacío

Una segunda verificación desde Apache (`apache2handler`) confirmó que navegador
y CLI usan `development`, grupo `default`, host `localhost`, base
`ikontrol_new`, prefijo `ikontrol_` y tabla
`ikontrol_fiscal_documents`. Ambos procesos observaron 17 documentos.

La caída definitiva no estaba en la base ni en los `LEFT JOIN`. La vista
entregaba a `appTable.filterParams` un mapa de selectores CSS:
`search=#fi-search`, `series=#fi-series`, `status=#fi-status`, etc.
`appTable` trata `filterParams` como valores, no resuelve selectores. El POST
enviaba esas cadenas literalmente y el query builder producía condiciones
como:

```sql
AND d.series LIKE '%#fi-series%'
AND d.status = '#fi-status'
AND s.pdf_status = '#fi-pdf-status'
```

Seguimiento por etapas:

- tabla fiscal sin filtros: 17;
- scope de seguridad efectivo: 17;
- después de `LEFT JOIN`: 17;
- estado vacío correcto: 17;
- bindings CSS anteriores: 0;
- bindings vacíos corregidos: 17;
- proyección final enviada por el controlador: 17.

La corrección se limitó a la fuente de parámetros en
`module_index.php`: `currentFilters()` obtiene `.val()` de cada control tanto
al inicializar como antes de cada recarga. Los selectores ya no se transmiten
como filtros SQL.

La prueba de integración de sólo lectura autenticó al usuario administrador
local 1 y confirmó 17 filas en la respuesta del controlador, incluido el
fixture 21 sin venta, con `Prueba importada` y la acción PDF correspondiente
al proveedor efectivo. No se
insertaron documentos porque la ejecución fue expresamente no mutante.

## Restauración del fixture FC2-A 14 para prueba real

El documento 21 fue restaurado de forma controlada después de una generación
fake. La transacción retiró exclusivamente:

- intento PDF fake `id=4`;
- artefacto PDF fake `id=8`.

Se conservaron el documento, UUID, metadata importada y el artefacto XML
timbrado `id=46`. El resultado final es `pdf_status=pending`,
`status=stamped_pdf_pending`, sin PDF activo y sin intentos WSTools33.

La interfaz distingue desde ahora:

- proveedor `fake`: `Prueba local`, artefacto `PDF de prueba` y acción
  `Generar PDF de prueba`;
- proveedor `timbradorxpress-tools`: `WSTools33 / PAC`, artefacto
  `PDF del PAC` y acción `Generar PDF del PAC`.

El modal muestra proveedor efectivo, plantilla y documento antes de confirmar.
En local muestra `Prueba local`, `factura` y `FC2-A 14`.

Para la futura prueba autorizada en servidor deben configurarse, sin
versionar ni documentar sus valores sensibles:

```dotenv
fiscal.pdf.provider=timbradorxpress-tools
MULTIPAC_TOOLS_ENABLED=true
MULTIPAC_TOOLS_WSDL=<WSDL configurado>
MULTIPAC_TOOLS_USER=<credencial vigente>
MULTIPAC_TOOLS_PASSWORD=<credencial vigente>
```

Deben mantenerse:

```dotenv
fiscal.allowRealPac=false
fiscal.pacAdapter=fake
```

Esta combinación habilita únicamente la generación PDF autorizada y conserva
bloqueado el timbrado real.

## Flujo operativo PDF simplificado

La tabla muestra una acción PDF en toda fila autorizada:

- sin PDF activo: `Generar PDF`;
- con PDF activo: `Ver PDF`, `Descargar PDF` y `Regenerar PDF`.

La acción no depende de venta, UUID proyectado, estado PDF previo ni intento
anterior. El endpoint realiza las validaciones definitivas:

1. carga el documento y el artefacto `stamped_xml` activo;
2. valida XML bien formado sin DTD ni entidades;
3. localiza exactamente un `TimbreFiscalDigital`;
4. extrae y valida su UUID;
5. resuelve plantilla, metadata y logo;
6. selecciona exclusivamente el adaptador configurado;
7. exige código 210, PDF Base64 y estructura PDF válida;
8. activa la nueva versión sólo después de validarla.

Generar y regenerar usan `FiscalPacPdfGenerationService`. La regeneración
conserva el PDF anterior vinculado si el proveedor falla; la versión anterior
sólo se marca superseded dentro de la transacción que enlaza la nueva.

El flujo operativo no crea automáticamente `FakePacPdfGenerationAdapter`.
Cuando `fiscal.pdf.provider=fake`, la acción continúa visible pero devuelve
`Servicio PDF deshabilitado`. El fake queda disponible únicamente mediante
inyección explícita en pruebas automatizadas.

No se invoca `FiscalStampingService`, timbrado ni cancelación. El adaptador
WSTools33 continúa enviando sólo los seis parámetros de `generarPDF`; el UUID
se obtiene del XML y no se envía como parámetro SOAP separado.
