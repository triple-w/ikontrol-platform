# FC2 — Mapa completo del flujo fiscal

Fecha de auditoría: 2026-07-25  
Proyecto revisado: `C:\Users\iKontrol\Documents\iKontrol\proyectos\iKontrolMyAdmin\ikontrolNew`  
Método: inspección estática de código. No se ejecutaron operaciones PAC, consultas de base de datos ni modificaciones en FC2.

## Convenciones de evidencia

- **Confirmado activo**: existe ruta activa y el controlador invoca el código.
- **Legado/no enlazado**: existe código, pero no se encontró ruta o llamada activa.
- **Inferencia**: conclusión razonable que no pudo comprobarse con ejecución.
- Los nombres de credenciales se documentan; sus valores fueron deliberadamente omitidos.

## 1. Resumen ejecutivo

FC2 concentra la funcionalidad fiscal activa en dos controladores Laravel:

- `FacturasController`: CFDI 4.0 de ingreso, egreso y traslado mediante un constructor genérico.
- `ComplementosController`: CFDI 4.0 tipo `P` con complemento Pagos 2.0.

La extensión `App\Extensions\MultiPac\MultiPac` encapsula dos servicios SOAP:

1. TimbradorXpress, para `timbrarConSello` y `cancelarPEM`.
2. WSTools33, para `generarPDF`.

El flujo activo no sella localmente. FC2 agrega el certificado y número de certificado al XML, deja `Sello` vacío y envía al PAC el XML junto con `keyPEM`. El PAC sella y timbra. Esta arquitectura es funcionalmente distinta de la decisión de iKontrol2.0 de firmar localmente.

El XML timbrado y el PDF Base64 se guardan directamente en base de datos (`facturas.xml`, `facturas.pdf`, y equivalentes en `complementos`). El PDF se solicita después del timbrado; si falla, facturas usa Dompdf como fallback y complementos intenta un fallback local con una inconsistencia de nombre de método.

La cancelación activa usa `cancelarPEM`, enviando llave y certificado PEM junto con UUID y datos del comprobante. Si el PAC responde éxito, FC2 marca inmediatamente el registro como `CANCELADA` y almacena el acuse. No se encontró una máquina de estados durable para solicitudes pendientes, aceptación del receptor, conciliación o timeout incierto.

La persistencia fiscal principal es heredada: las tablas `facturas`, `factura_detalles`, `facturas_impuestos`, `complementos`, `complementos_pagos`, `folios`, `users_info_factura`, `users_info_factura_documentos`, `users_perfil` y campos de `users` no están construidos por las migraciones disponibles. Sólo se encontró una migración fiscal que agrega `facturas.total`.

Riesgos principales:

- credenciales WSTools33 declaradas en código;
- WSTools33 sobre HTTP sin TLS;
- archivos CSD y PEM bajo rutas resolubles desde `public_path`;
- llave PEM descifrada persistente;
- respuesta SOAP cruda devuelta como string y mostrada parcialmente al usuario;
- ausencia de idempotencia y de intentos durables;
- folio y timbre se consumen después de la llamada externa, dentro de una transacción que no puede revertir el timbrado;
- importes fiscales calculados con `float`;
- cancelación marcada como final con una condición de éxito demasiado amplia;
- falta de consulta SAT/PAC activa y de seguimiento de cancelaciones.

## 2. Arquitectura general

```text
Captura (Blade + sesión draft)
  ├─ Factura: FacturasController::create/preview
  └─ Pago: ComplementosController::create/preview
        ↓
Validación y normalización en controlador
        ↓
Construcción DOMDocument
  ├─ FacturasController::generarXmlCfdi40DesdePayload
  └─ ComplementosController::generarXmlPagos20DesdePayload
        ↓
Carga CSD desde users_info_factura_documentos
  └─ cargarCsdParaTimbrado
        ↓
Certificado + NoCertificado; Sello vacío
        ↓
MultiPac::callTimbrarCFDI → SOAP timbrarConSello(apikey, xmlCFDI, keyPEM)
        ↓
XML timbrado + UUID
        ↓
WSTools33::generarPDF(usuario, claveAcceso, xmlB64, plantilla, json, logo)
        ├─ éxito: PDF Base64
        └─ error: fallback Dompdf
        ↓
Transacción local
  ├─ guardar cabecera, partidas e impuestos/pagos
  ├─ guardar XML/PDF/UUID/acuse
  ├─ avanzar folio
  └─ descontar timbre
        ↓
Vista / descarga XML / descarga PDF
        ↓
POST cancelar
  └─ cancelarPEM(apikey, keyPEM, cerPEM, uuid, RFCs, total, motivo, sustitución)
        ↓
estatus=CANCELADA + acuse
```

Correo existe únicamente en el método legado `MultiPac::generarFacturaWhitData`; no se encontró invocación desde las rutas activas.

## 3. Matriz de archivos

| Archivo | Clase/método | Responsabilidad | Entrada | Salida | Tablas | Externo | Estado/observación |
|---|---|---|---|---|---|---|---|
| `routes/web.php:34,123-164` | rutas protegidas | Expone facturas, complementos, descargas, PDF y cancelación | HTTP | Controlador | — | — | Activo; grupo `auth:sanctum,verified`, middleware web/CSRF |
| `FacturasController.php` | `create`, `preview`, `timbrar` | Captura, borrador, XML, PAC y persistencia | request/sesión | redirect/HTML/XML debug | múltiples | TimbradorXpress, WSTools33 | Activo |
| mismo | `generarXmlCfdi40DesdePayload` | Construye CFDI 4.0 genérico | payload | XML | `clientes`, `users_perfil` | — | Activo |
| mismo | `cargarCsdParaTimbrado` | Resuelve `.cer` y `.key.pem` | user ID | certificado Base64, key PEM, número | `users_info_factura*` | filesystem | Activo |
| mismo | `timbrarConPacMultipac` | Inyecta CSD y timbra | XML | XML/UUID/PDF/acuse/code | — | `timbrarConSello` | Activo |
| mismo | `guardarFacturaTimbrada` | Persiste factura completa | payload + artefactos | factura ID | `facturas`, detalles, impuestos | — | Activo |
| mismo | `regenerarPdf` | Recupera PDF desde XML timbrado | factura ID | redirect | `facturas` | WSTools33/Dompdf | Activo; no retimbra |
| mismo | `cancelar` | Cancela y almacena acuse | ID, motivo, sustitución | redirect | `facturas` | `cancelarPEM` | Activo |
| `ComplementosController.php` | `timbrar` | Flujo Pagos 2.0 | payload/sesión | redirect | complementos/pagos | mismos servicios | Activo |
| mismo | `generarXmlPagos20DesdePayload` | Construye CFDI P + Pagos 2.0 | payload | XML | emisor, facturas | — | Activo |
| mismo | `generarPdfBase64ComplementoPagos2` | PDF plantilla `pagos20` | XML | Base64 | — | WSTools33 | Activo |
| mismo | `cancelar` | Cancela complemento y restaura saldos | ID/motivo | redirect | complementos/pagos | `cancelarPEM` | Activo |
| `Traits/PacMultiPacTrait.php` | métodos CSD/PAC/PDF | Copia reutilizable del flujo | XML/user | respuesta normalizada | CSD | SOAP | Referenciado por `ComplementosController`; duplica código de facturas |
| `Extensions/MultiPac/MultiPac.php` | adaptador SOAP | Credenciales, WSDL y operaciones | arrays | objeto/string | — | SOAP | Activo y legado mezclados |
| mismo | `generarFacturaWhitData` | Flujo antiguo sello/timbre/PDF/correo | user/data | XML | no confirmado | SOAP + Mail | Legado/no enlazado |
| `Models/Factura.php` | modelo | Cabecera y relaciones | ORM | ORM | `facturas` | — | Activo parcial; controladores usan Query Builder |
| `Models/FacturaDetalle.php` | modelo | Conceptos | ORM | ORM | `factura_detalles` | — | Activo indirecto |
| `Models/FacturaImpuesto.php` | modelo | Impuestos agrupados | ORM | ORM | `facturas_impuestos` | — | Activo indirecto |
| `Models/Folio.php` | modelo | Serie/folio | ORM | ORM | `folios` | — | Activo indirecto |
| `config/sat.php` | configuración | Catálogos mínimos | — | arrays | — | — | Activo en UI; catálogo parcial/hardcodeado |
| `config/timbradorxpress_errors.php` | catálogo | Traducción de códigos | operación/código | mensaje | — | — | Activo en cancelación; cobertura documental mayor al código activo |
| `resources/views/facturas/*` | Blade | Captura, preview, listado, detalle, PDF | datos | HTML | — | — | Activo |
| `resources/views/documentos/complementos/*` | Blade | Captura/preview/listado/PDF pago | datos | HTML | — | — | Activo |
| migración `2026_03_10_180000_add_total_to_facturas_table.php` | migración | Agrega `total decimal(18,2)` | esquema | esquema | `facturas` | — | Única migración fiscal encontrada |

## 4. Rutas y endpoints

Todas las rutas siguientes están bajo el grupo de `routes/web.php:34` con `auth:sanctum` y `verified`. Laravel aplica CSRF a POST mediante el grupo web; las vistas POST revisadas incluyen `@csrf`.

| Método y URI | Método | Parámetros | Respuesta | Efectos |
|---|---|---|---|---|
| GET `documentos/facturas` | `FacturasController::index` | `q`, paginación | HTML/AJAX | lectura |
| GET `documentos/facturas/nueva` | `nueva` | — | redirect | limpia draft |
| GET `documentos/facturas/create` | `create` | — | HTML | carga catálogos |
| POST `documentos/facturas/preview` | `preview` | payload | HTML/redirect | guarda draft en sesión |
| GET `documentos/facturas/preview` | `previewGet` | sesión | HTML | lectura |
| POST `documentos/facturas/timbrar` | `timbrar` | `modo`, `payload` | XML debug o redirect | PAC, persistencia, folio, timbre |
| GET `documentos/facturas/{id}/ver` | `show` | ID | HTML | lectura |
| GET `documentos/facturas/{id}/xml` | `downloadXml` | ID | XML attachment | lectura |
| GET `documentos/facturas/{id}/pdf` | `downloadPdf` | ID | PDF attachment | Base64 decode |
| GET `documentos/facturas/{id}/acuse` | `downloadAcuse` | ID | XML attachment | lectura |
| POST `documentos/facturas/{id}/regenerar-pdf` | `regenerarPdf` | ID | redirect | llama PDF; actualiza `pdf` |
| POST `documentos/facturas/{id}/cancelar` | `cancelar` | motivo, folio sustitución | redirect | PAC, estado, acuse, consume timbre |
| GET `documentos/facturas/chunk` | `indexChunk` | cursor/offset | HTML/JSON | lectura |
| GET `documentos/complementos` | `index` | filtros | HTML/AJAX | lectura |
| GET `documentos/complementos/create` | `create` | — | HTML | limpia/carga draft |
| POST `documentos/complementos/preview` | `preview` | payload | HTML | guarda draft |
| POST `documentos/complementos/timbrar` | `timbrar` | modo/payload | XML debug o redirect | PAC y persistencia |
| GET `documentos/complementos/{id}/ver` | `ver` | ID | HTML | lectura |
| GET `documentos/complementos/{id}/xml` | `downloadXml` | ID | XML attachment | lectura |
| GET `documentos/complementos/{id}/pdf` | `downloadPdf` | ID | PDF attachment | Base64 decode |
| POST `documentos/complementos/{id}/regenerar-pdf` | `regenerarPdf` | ID | redirect | actualiza PDF |
| POST `documentos/complementos/{id}/cancelar` | `cancelar` | motivo/sustitución | redirect | PAC, estado/acuses/saldos |
| GET `documentos/complementos/facturas-pendientes` | `facturasPendientes` | cliente | JSON | calcula saldo |

No se encontró ruta activa de guardar borrador en base, envío de correo, consulta SAT, consulta de acuse o preview PDF inline. El borrador vive en sesión.

## 5. Tipos de documento

| Tipo | Evidencia | XML/PDF/cancelación | Tablas | Estado |
|---|---|---|---|---|
| Ingreso (`I`) | constructor genérico y `config/sat.php` | flujo factura | facturas/detalles/impuestos | Activo |
| Egreso (`E`) / nota de crédito | tipo genérico y `CfdiRelacionados` | mismo flujo factura | mismas | Implementación genérica; no hay controlador específico |
| Traslado (`T`) | catálogo y constructor genérico | mismo flujo | mismas | Aparentemente soportado, no validado por ejecución |
| Pago (`P`) | `ComplementosController` + Pagos 2.0 | flujo dedicado, plantilla `pagos20` | complementos/pagos | Activo |
| Nómina (`N`) | ruta `nominas` a “coming soon”; miembros legacy en MultiPac | no hay flujo activo | no confirmadas | No implementado activamente |
| Retenciones | sólo catálogo de errores | no hay ruta/controlador | no encontrada | No implementado |
| Público general | no se encontró flujo dedicado | posible captura genérica | mismas | No confirmado |

PUE/PPD son atributos de facturas. PPD se usa como fuente de documentos para el complemento, pero no existe un job automático.

## 6. Construcción del XML

### Facturas

`FacturasController::generarXmlCfdi40DesdePayload` (`:1384`) usa `DOMDocument`, CFDI 4.0 y el namespace `http://www.sat.gob.mx/cfd/4`.

- Emisor: `users_perfil`.
- Receptor: `clientes`.
- Conceptos: payload de sesión/request.
- CFDI relacionados: se agrega `CfdiRelacionados` cuando el payload lo incluye.
- Impuestos: traslados y retenciones por concepto; agrega agrupados globales.
- Redondeo: closures con `round(..., 2)` y `round(..., 6)`, usando `float`.
- Descuento: suma descuentos de concepto; subtotal suma importes antes del descuento.
- Totales: se calculan en el controlador y se reproducen en `calcularResumenFactura`.
- Fecha: `date('Y-m-d\TH:i:s', strtotime(payload.fecha))`; depende de timezone PHP.
- Validación previa: comprobaciones manuales de cliente, emisor, conceptos y atributos. No se encontró validación XSD activa.
- El XML no se guarda como borrador persistente; `solicitud_timbre` se guarda sólo después del éxito.

### Pagos

`ComplementosController::generarXmlPagos20DesdePayload` (`:2138`) construye CFDI 4.0 tipo `P` y complemento Pagos 2.0.

- `SubTotal=0`, `Moneda=XXX`, `Total=0`, concepto estándar `84111506/ACT/Pago`.
- `UsoCFDI=CP01`.
- Calcula `MontoTotalPagos`, impuestos DR y P.
- Incluye datos bancarios opcionales.
- Usa helpers de enteros escalados para dinero y tasas en gran parte del cálculo, pero persiste saldos como `float`.
- Registra datos del emisor incompleto y validación tributaria; esos logs pueden contener RFC y datos fiscales.
- No se encontró ejecución de XSD.

## 7. Sellado

Flujo activo observado:

1. `cargarCsdParaTimbrado` busca `users_info_factura`.
2. Busca documentos `validado=1` en `users_info_factura_documentos`.
3. Localiza `ARCHIVO_CERTIFICADO` `.cer` y `ARCHIVO_LLAVE`.
4. Resuelve rutas absolutas o relativas a `public_path`.
5. Deriva una ruta `.key.pem` persistente.
6. Lee el certificado y lo codifica Base64.
7. Lee la llave PEM en texto.
8. Toma `numero_certificado` desde base.
9. `inyectarCertificadoEnXml` agrega `Certificado` y `NoCertificado`, y vacía `Sello`.
10. `MultiPac::callTimbrarCFDI` envía `apikey`, XML y `keyPEM` a `timbrarConSello`.

Por tanto, **el PAC realiza el sellado**. Los métodos `generateSello` y `generateSelloV33` existen en `MultiPac`, pero sólo se localizaron dentro del método legado `generarFacturaWhitData`, sin ruta activa.

No se encontró uso activo de contraseña CSD en el flujo de timbrado; la existencia de PEM descifrado evita tener que descifrar la llave durante la operación, pero aumenta el riesgo de exposición.

## 8. Timbrado

- Clase: `MultiPac`.
- Método activo: `callTimbrarCFDI`.
- WSDL dev/prod: variables `MULTIPAC_WSDL_DEV` y `MULTIPAC_WSDL_PROD`; defaults HTTPS de TimbradorXpress.
- Selección: `MULTIPAC_MODE`; default prod si `APP_ENV=production`, dev en otro caso.
- Operación: `timbrarConSello`.
- Parámetros exactos: `apikey`, `xmlCFDI`, `keyPEM`.
- Credencial: `MULTIPAC_APIKEY`, con variantes DEV/PROD leídas en constructor.
- Respuesta tolerada: objeto con `code/message/data`, alias de XML, UUID, PDF o acuse.
- Si llega string: el controlador lanza excepción con hasta 800 caracteres.
- Éxito práctico: XML timbrado no vacío; no exige código `200`.
- UUID: respuesta o extracción de `TimbreFiscalDigital`.
- Persistencia: XML, UUID, solicitud original, PDF y acuse se insertan después de generar PDF.
- Momento “timbrado”: fiscalmente ocurre al recibir el XML; localmente no existe registro hasta terminar PDF y entrar a la transacción.

`SoapFault` se captura en `MultiPac` y se transforma en `__getLastResponse()`, perdiendo tipo, certeza de envío y semántica de timeout. No hay idempotency key ni conciliación.

## 9. Generación de PDF

### PDF PAC activo

`FacturasController::generarPdfBase64DesdePacV33` y `ComplementosController::generarPdfBase64ComplementoPagos2` llaman `MultiPac::generatePDFV33`.

Operación:

```text
WSTools33::generarPDF(
  usuario,
  claveAcceso,
  xmlB64,
  plantilla,
  json,
  logo
)
```

- WSDL: `http://facturaloplus.com/ws/WSTools33.php?wsdl`.
- Éxito esperado: `code=210` y campo `pdf` Base64.
- Facturas: plantilla por defecto observada en el método; no existe resolver por emisor/tipo.
- Pagos: plantilla hardcodeada `pagos20`.
- `json`: Base64 de JSON con tipo, receptor, comentarios, serie y folio.
- `logo`: Base64 leído desde branding del usuario.
- PDF: se guarda como Base64 en la misma fila fiscal.

### Otros caminos

- `MultiPac::generatePDF`: operación de cuatro argumentos sobre `$clientTools`; el cliente no se inicializa en el constructor mostrado. Es legado/frágil.
- `generatePDFV33`: activo, pero su `catch` devuelve `$this->clientTools->__getLastResponse()` en lugar de `$clientToolsV33`; defecto confirmado.
- Fallback factura: `generarPdfBase64FallbackDompdf`, vista `facturas.pdf`.
- Fallback complemento: existe `generarPdfBase64FallbackDompdfComplemento`, pero el `catch` de `timbrar` pregunta por `generarPdfBase64FallbackDompdf`; la condición resulta falsa salvo otro trait/método, dejando PDF vacío.
- Regeneración: POST independiente usa XML timbrado, no retimbra; sobrescribe `pdf` sin historial.

No se valida estrictamente Base64 ni encabezado `%PDF-` antes de persistir. WSTools33 usa HTTP sin TLS.

## 10. Persistencia

| Tabla/campo | Contenido/formato |
|---|---|
| `facturas` | receptor, estado, fechas, serie/folio opcionales, forma/método/uso/moneda, subtotal/IVA/total |
| `facturas.xml` | XML timbrado en texto |
| `facturas.solicitud_timbre` | XML previo enviado al PAC |
| `facturas.uuid` | UUID texto |
| `facturas.pdf` | PDF Base64 texto |
| `facturas.acuse` | acuse XML/texto |
| `facturas.estatus` | `TIMBRADA`/`CANCELADA` |
| `factura_detalles` | partidas desnormalizadas |
| `facturas_impuestos` | impuestos globales |
| `complementos` | UUID, XML, PDF Base64, acuse, solicitud, estado y datos del pago |
| `complementos_pagos` | UUID relacionado, parcialidad, saldos, monto y factura origen |
| `folios` | serie y folio/consecutivo |
| `users.timbres_disponibles` | contador comercial |
| `users_info_factura` | datos fiscales/CSD del emisor |
| `users_info_factura_documentos` | metadatos/rutas de `.cer` y `.key`, validación, número |
| `users_perfil` | perfil emisor alterno |

No se encontró tabla de intentos, artefactos versionados, sellos SAT separados, cadena original, historial de estados, solicitud de cancelación o auditoría de correo. El esquema completo no es reproducible desde las migraciones disponibles.

## 11. Descargas y vistas

- XML: devuelve texto con `Content-Type: application/xml` y `Content-Disposition: attachment`.
- PDF: `base64_decode(..., true)` y devuelve bytes con `application/pdf`, attachment.
- Acuse factura: XML attachment.
- Complementos no tienen ruta de descarga de acuse.
- No hay endpoint de preview PDF inline; la UI enlaza descarga.
- El nombre se deriva de UUID o ID.
- Si falta contenido o Base64 es inválido, se responde 404/error.
- `facturaOrFail` y `complementoOrFail` filtran por `users_id`, lo cual aporta aislamiento por propietario. No existe permiso fiscal granular en las rutas revisadas.

## 12. Envío por correo

No se encontró envío desde los controladores activos. Existe código legado en `MultiPac::generarFacturaWhitData`:

- llama una vista `emails.facturacion.factura_generada`;
- adjunta XML y, si se pudo generar, PDF mediante `attachData`;
- usa configuración Laravel Mail (`MAIL_*`);
- se ejecutaría después del timbrado.

No se encontró la plantilla de email en la ubicación esperada ni una referencia que llame al método. No hay cola, reintento durable, bitácora ni columna confirmada de correo enviado. En el flujo legado, un fallo de correo ocurre después del timbrado, pero el tratamiento exacto requiere revisar el bloque completo en ejecución.

## 13. Cancelación

Rutas: POST `facturas/{id}/cancelar` y `complementos/{id}/cancelar`.

Flujo:

1. valida motivo `01`, `02`, `03` o `04`;
2. para `01`, exige UUID de sustitución;
3. carga XML timbrado;
4. extrae UUID, RFC emisor, RFC receptor y total;
5. carga key PEM y certificado;
6. arma `cerPEM` desde certificado Base64;
7. invoca `cancelarPEM` con:
   - `apikey`;
   - `keyPEM`;
   - `cerPEM`;
   - `uuid`;
   - `rfcEmisor`;
   - `rfcReceptor`;
   - `total`;
   - `motivo`;
   - `folioSustitucion`.
8. considera éxito si `status=success` o `code=0`;
9. actualiza `estatus=CANCELADA` y `acuse`;
10. consume un timbre.

En complementos también restaura `saldo_insoluto=saldo_anterior`.

No distingue solicitud aceptada, pendiente de aceptación o cancelación efectiva SAT. Los códigos documentados `201/202` no coinciden con la condición `code=0`, por lo que existe riesgo de interpretar incorrectamente respuestas reales. No hay estados intermedios, reintentos controlados ni conciliación.

## 14. Acuses de cancelación

- Fuente: `data`, `acuse` o `ACUSE` de la respuesta PAC.
- Formato: tratado como texto/XML sin validación.
- Persistencia: columna `acuse` del documento.
- Descarga: sólo facturas tienen `downloadAcuse`.
- Relación: implícita en la misma fila; no hay hash, fecha, tipo ni historial.
- Ausencia: aun con respuesta considerada exitosa puede conservar acuse anterior o vacío.
- Regeneración/consulta: no encontrada.

## 15. Consulta de estatus

`MultiPac::callConsultarAutorizacionesPendientes` y códigos de consulta existen, pero no se encontró ruta ni llamada activa. Tampoco se localizaron jobs/listeners que consulten SAT o PAC, actualicen vigencia/cancelación o recuperen acuses.

Conclusión: la consulta de estatus es **código legado/no enlazado**, no una capacidad activa confirmada.

## 16. Regeneraciones y reintentos

| Acción | Implementación actual | Riesgo |
|---|---|---|
| Regenerar PDF | POST independiente desde XML guardado | sobrescribe PDF, sin versiones ni intento durable |
| Reenviar correo | no encontrado | no disponible |
| Retimbrar | botón POST sin idempotencia durable | doble clic/request puede timbrar dos veces |
| Reintentar cancelación | el usuario puede repetir salvo estado local CANCELADA | timeout puede duplicar solicitud |
| Consultar acuse | no encontrado | estado incierto permanente |
| Consultar estatus | método legado no enlazado | sin conciliación |

La llamada PAC ocurre antes de insertar el documento. Si el PAC timbra y luego falla PDF, base o folio, no queda UUID local y un reintento puede duplicar. La transacción DB no cubre la llamada externa.

## 17. Credenciales y configuración

Variables encontradas:

- `MULTIPAC_MODE`
- `MULTIPAC_WSDL_DEV`
- `MULTIPAC_WSDL_PROD`
- `MULTIPAC_APIKEY`
- `MULTIPAC_APIKEY_DEV`
- `MULTIPAC_APIKEY_PROD`
- `APP_ENV`
- `APP_URL`
- `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`

WSTools33 usa propiedades `usuarioTools` y `passwordTools` declaradas en `MultiPac.php`; se detectaron valores embebidos y no se reproducen aquí. Timbrado/cancelación y PDF pueden pertenecer al mismo proveedor comercial, pero usan mecanismos técnicos distintos: API key para TimbradorXpress y usuario/clave para WSTools33.

CSD:

- metadatos en `users_info_factura_documentos`;
- archivos en rutas absolutas o relativas a `public_path`;
- certificado `.cer`;
- llave `.key.pem` descifrada persistente;
- no se observó vault de contraseña en el flujo activo.

## 18. Ambientes

- Timbrado/cancelación: `MULTIPAC_MODE=dev|prod` elige WSDL dev/prod.
- API key: variantes general/dev/prod.
- PDF: un único WSDL HTTP WSTools33, sin separación visible por ambiente.
- `APP_ENV=production` selecciona prod por defecto si no hay `MULTIPAC_MODE`.

Riesgo: una variable ausente puede llevar a producción; PDF no evidencia ambiente; XML de un ambiente podría enviarse al PDF de otro; las credenciales se resuelven en un constructor que crea ambos clientes aun si sólo se necesita uno.

## 19. Manejo de errores

| Operación/código | Origen | Comportamiento actual | Riesgo / recomendación |
|---|---|---|---|
| Timbrado `200` | PAC/config | no se exige; basta XML | validar contrato y XML/TFD |
| `300` | API key | mensaje catálogo | no exponer respuesta cruda |
| `301-308`, `401-402` | timbrado | excepción/flash | guardar intento sanitizado |
| PDF `210` | WSTools33 | acepta PDF; validación débil | Base64 estricto, `%PDF-`, hash |
| SoapFault | adaptador | devuelve última respuesta string | conservar categoría y certeza de envío |
| Cancelación `201/202` | PAC/config | condición activa espera `0/success` | corregir normalización contractual |
| `203/205`, `CA*` | cancelación | traducción parcial | estados durables y conciliación |
| Consulta `100/101/997/999` | catálogo | no hay flujo activo | implementar servicio explícito |
| Error PDF | controlador | fallback local o vacío | separar timbrado de PDF |
| Error DB post-PAC | aplicación | rollback local | riesgo crítico de CFDI huérfano |

El archivo `config/timbradorxpress_errors.php` incluye timbrado, retenciones, cancelación y consulta, pero la presencia de códigos no prueba que esas operaciones estén implementadas.

## 20. Seguridad

- **Credenciales hardcodeadas:** detectadas en `MultiPac.php` para WSTools33.
- **HTTP sin TLS:** WSTools33.
- **CSD:** rutas pueden caer bajo `public/uploads`; llave PEM descifrada persistente.
- **Errores:** respuestas SOAP pueden mostrarse parcialmente al usuario.
- **Logs:** complementos registra RFC, UUID, mensaje PAC y stack trace; no se observó XML completo en los eventos revisados, pero sí datos fiscales.
- **XML/PDF:** guardados en base, no cifrados a nivel de aplicación.
- **CSRF:** POST Blade usa `@csrf`; middleware web activo.
- **Permisos:** autenticación, verificación y filtro `users_id`; sin permisos fiscales granulares.
- **Uploads:** `ConfiguracionController` manipula y envía `.cer/.key`; requiere auditoría específica adicional de validación.
- **SOAP:** `trace=true` conserva request/response en memoria y puede facilitar exposición accidental.
- **Temporales:** se deriva y reutiliza `.key.pem`; no se confirmó borrado seguro.

## 21. Problemas técnicos encontrados

### Críticos

1. `FacturasController::timbrar` y `ComplementosController::timbrar`: PAC antes de persistencia durable. Impacto: CFDI timbrado huérfano y duplicidad. Evidencia: llamada externa precede `DB::transaction`.
2. `MultiPac.php:23-29,59-60`: credenciales WSTools33 en código. Impacto: exposición y rotación difícil.
3. `cargarCsdParaTimbrado`: llave PEM descifrada persistente y rutas públicas posibles. Impacto: compromiso del CSD.

### Altos

1. `MultiPac.php:56`: WSTools33 HTTP.
2. `MultiPac::generatePDFV33`: catch consulta cliente SOAP incorrecto.
3. Cancelación: `status=success|code=0` no concuerda con catálogo `201/202`.
4. Sin idempotencia, intentos durables ni estado desconocido.
5. Sin XSD/firma independiente antes o después del PAC.

### Medios

1. Cálculos de factura con `float`.
2. Código PAC/CSD duplicado entre controlador y trait.
3. PDF Base64 sin validación estructural ni historial.
4. Esquema fiscal no reproducible por migraciones.
5. Fecha depende de timezone global.
6. Complemento invoca nombre de fallback incorrecto.
7. Logs de complemento incluyen datos fiscales y stack.

### Bajos

1. Codificación mojibake visible en comentarios/mensajes.
2. Métodos y propiedades legacy no usados.
3. Comentarios que ya no reflejan el flujo real.
4. Catálogos SAT hardcodeados y parciales.

## 22. Qué debemos copiar a iKontrol

| Comportamiento FC2 | Reutilizar | Rediseñar | No copiar | Motivo |
|---|---:|---:|---:|---|
| Separación timbrado/PDF | sí | — | — | correcta frontera operativa |
| Construcción DOM CFDI/Pagos | como referencia | sí | — | útil, pero requiere snapshots/XSD/decimales |
| `timbrarConSello` con keyPEM | — | — | sí | iKontrol firma localmente |
| Persistir XML timbrado/UUID inmediatamente | concepto | sí | — | debe ser durable antes de PDF |
| WSTools33 `generarPDF` | contrato | sí | — | adapter seguro, TLS/allowlist/intentos |
| Fallback Dompdf fiscal | — | — | sí | contradice PDF PAC como autoridad visual decidida |
| Cancelación con motivos | concepto | sí | — | necesita estados y conciliación |
| Acuse en misma fila | — | sí | — | artefacto inmutable/versionado |
| Sesión como borrador | — | — | sí | falta trazabilidad |
| Folios + consumo transaccional local | idea | sí | — | reservar antes y reconciliar |
| Credenciales en clase | — | — | sí | usar `.env`/vault |
| Correo inline | — | sí | — | cola separada, no revierte fiscal |

## 23. Flujo recomendado para iKontrol

Lo siguiente es **recomendación**, no comportamiento observado:

```text
Documento administrativo
→ preparación fiscal con snapshots
→ cierre inmutable
→ XML CFDI con decimales exactos
→ XSD + cadena original + firma local + verificación independiente
→ intento durable e idempotency key
→ PAC fuera de transacción
→ persistencia inmediata e inmutable de XML/UUID/timbre
→ PDF PAC como operación separada y reintentable sin retimbrar
→ correo como job separado
→ cancelación durable (requested/pending/cancelled/rejected/unknown)
→ acuse como artefacto
→ consulta SAT/PAC y conciliación
```

FC2 aporta los contratos operativos y el modelo funcional; iKontrol no debe copiar su manejo de secretos, llave PEM, transacciones, floats ni respuestas SOAP crudas.

## 24. Datos faltantes

- DDL original de la mayoría de tablas fiscales.
- Respuestas reales verificadas de los WSDL.
- Contrato exacto de cancelación usado por la cuenta.
- Estado SAT posterior a `cancelarPEM`.
- Flujo de aceptación del receptor.
- Uso activo de `callConsultarAutorizacionesPendientes`.
- Tabla/flujo de nómina y retenciones.
- Flujo dedicado de público general.
- Plantilla de correo referenciada por código legado.
- Validación XSD, cadena original y verificación criptográfica activa.
- Significado contractual de consumo de timbre al cancelar.
- Límites de tamaño/tipo de columnas XML/PDF/acuse.
- Permisos adicionales definidos fuera de las rutas.

## 25. Anexos

### Archivos revisados

- `routes/web.php`
- `app/Http/Controllers/FacturasController.php`
- `app/Http/Controllers/ComplementosController.php`
- `app/Http/Controllers/Traits/PacMultiPacTrait.php`
- `app/Http/Controllers/ConfiguracionController.php` (referencias CSD)
- `app/Extensions/MultiPac/MultiPac.php`
- `app/Models/Factura.php`
- `app/Models/FacturaDetalle.php`
- `app/Models/FacturaImpuesto.php`
- `app/Models/Folio.php`
- `app/Models/Cliente.php`
- `app/Models/Producto.php`
- `config/sat.php`
- `config/timbradorxpress_errors.php`
- `.env.example` (sólo nombres de variables)
- `composer.json`, `composer.lock`
- vistas `resources/views/facturas/**`
- vistas `resources/views/documentos/complementos/**`
- migraciones de `database/migrations/**`
- comandos de `app/Console/Commands/**`

### Métodos fiscales principales

`timbrar`, `generarXmlCfdi40DesdePayload`, `generarXmlPagos20DesdePayload`, `cargarCsdParaTimbrado`, `inyectarCertificadoEnXml`, `timbrarConPacMultipac`, `callTimbrarCFDI`, `guardarFacturaTimbrada`, `guardarComplementoTimbrado`, `generatePDFV33`, `regenerarPdf`, `downloadXml`, `downloadPdf`, `downloadAcuse`, `cancelar`, `callCancelarPEM`, `facturasPendientes`.

### Tablas encontradas

`facturas`, `factura_detalles`, `facturas_impuestos`, `complementos`, `complementos_pagos`, `folios`, `clientes`, `users`, `users_perfil`, `users_info_factura`, `users_info_factura_documentos`.

### Operaciones externas

- `timbrarConSello`
- `cancelarPEM`
- `generarPDF` (WSTools33, variante activa de seis argumentos)
- `generarPDF` legacy de cuatro argumentos
- métodos genéricos/legacy `generateSello`, `generateSelloV33`
- `callConsultarAutorizacionesPendientes` (no enlazado)

### Códigos inventariados

- General: `300`.
- Timbrado: `200`, `301-308`, `401-402`, equivalentes `T*`.
- PDF: `210`.
- Cancelación: `201-205`, `CA*`, `CR*`.
- Consulta: `100-102`, `997`, `999`.

### Clasificación final

- **Confirmado activo:** factura CFDI 4.0, complemento Pagos 2.0, timbrado `timbrarConSello`, PDF WSTools33, fallback local, descarga, regeneración y cancelación.
- **Parcial:** egreso/traslado mediante constructor genérico, acuse sólo para factura, error handling.
- **Legado/no enlazado:** correo, generación de sello por Tools, consulta de autorizaciones.
- **No implementado confirmado:** nómina, retenciones, consulta SAT durable, aceptación de cancelación, jobs de conciliación.

