# Contexto fiscal técnico de FactuCare 2

**Fecha de auditoría:** 2026-07-21  
**Repositorio auditado:** `D:\GitHub\factucare2-0`  
**Alcance:** lectura estática exclusivamente fiscal. No se ejecutaron timbrados, servicios PAC/SAT, validación remota de CSD ni pruebas con credenciales.

## Convenciones de evidencia

- **Hecho:** conducta visible directamente en el código citado.
- **Inferencia:** conclusión razonable que no puede confirmarse sin el esquema o entorno de producción.
- **No comprobado:** falta evidencia en el repositorio.
- **Recomendación:** diseño futuro; no describe la conducta actual.
- Todas las rutas son absolutas bajo `D:\GitHub\factucare2-0`.
- Se inspeccionó el código fuente, vistas Blade/JavaScript embebido, configuración, rutas, migraciones, modelos, pruebas y manifiestos. No se leyó ni se documenta ningún valor de un `.env` real.

## Resumen técnico

FactuCare 2 es una aplicación **Laravel 11**, no CodeIgniter. El requisito de CodeIgniter 4.6.1 corresponde a iKontrol2.0, el futuro destino. La implementación fiscal vigente se concentra en controladores muy grandes: `FacturasController` (CFDI 4.0 de ingreso/egreso/traslado) y `ComplementosController` (Pagos 2.0). Ambos construyen XML localmente con `DOMDocument`; el cliente `MultiPac` envía el XML, el certificado y la llave privada PEM por SOAP a TimbradorXpress/MultiPac mediante `timbrarConSello`. El XML sale con `Sello` vacío: el PAC realiza el sellado y devuelve el XML timbrado.

La parte más sólida es la construcción explícita de nodos CFDI 4.0, la agrupación de impuestos redondeados por concepto y, en Pagos 2.0, los helpers de aritmética decimal basada en enteros. Las partes más frágiles son la ausencia de idempotencia antes de llamar al PAC, credenciales del servicio PDF hardcodeadas, CSD y PEM dentro de `public/uploads`, contraseña del CSD persistida aparentemente en claro, esquema fiscal legado sin DDL versionado, lógica duplicada/legada y varias validaciones SAT incompletas.

## 1. Identidad técnica del proyecto

| Punto | Hecho comprobado | Evidencia |
|---|---|---|
| Framework | Laravel `^11.0`; estructura MVC Laravel, Eloquent y Query Builder | `D:\GitHub\factucare2-0\composer.json`; `D:\GitHub\factucare2-0\artisan`; `D:\GitHub\factucare2-0\app` |
| PHP | Requisito Composer `^8.2`; CLI auditado 8.2.12. La versión del servidor desplegado no se comprobó | `D:\GitHub\factucare2-0\composer.json` |
| Base de datos | MySQL es el predeterminado; PDO y `utf8mb4`, modo estricto. El entorno puede reemplazarlo mediante `DB_CONNECTION` | `D:\GitHub\factucare2-0\config\database.php`; `D:\GitHub\factucare2-0\.env.example` |
| Arquitectura | MVC, pero el dominio fiscal reside principalmente en controladores y JavaScript de vistas; acceso a datos mayormente con `DB::table` | `D:\GitHub\factucare2-0\app\Http\Controllers\FacturasController.php`; `...\ComplementosController.php` |
| Rutas | Rutas web Laravel, agrupadas por `auth:sanctum` y `verified`; APIs fiscales internas usan sesión web | `D:\GitHub\factucare2-0\routes\web.php`; `...\routes\api.php` |
| Autenticación | Jetstream/Fortify/Sanctum; usuario legacy por `username`; rutas fiscales requieren autenticación y verificación | `...\composer.json`; `...\app\Providers\FortifyServiceProvider.php`; `...\app\Models\User.php`; `...\routes\web.php` |
| Roles/permisos | Existe `users.rol`, pero no se encontró autorización fiscal por rol ni policies; se protege por `users_id` y middleware | `...\app\Models\User.php`; `...\app\Providers\AuthServiceProvider.php`; controladores fiscales |
| Archivos | CSD/PEM y logos se guardan bajo webroot `public/uploads`; XML/PDF/acuse se guardan en BD | `...\ConfiguracionController.php::storeDocumentoArchivo`; `...\FacturasController.php::guardarFacturaTimbrada`; `...\ComplementosController.php::guardarComplementoTimbrado` |
| Colas | Configuración Laravel disponible, `sync` por defecto; no se encontraron Jobs fiscales | `...\config\queue.php`; `...\database\migrations\2019_08_19_000000_create_failed_jobs_table.php` |
| Programación | `Console\Kernel::schedule` vacío; sólo existe comando manual de backfill de total | `...\app\Console\Kernel.php`; `...\app\Console\Commands\BackfillFacturasTotalCommand.php` |
| SOAP | Extensión nativa `SoapClient`; TimbradorXpress y WSTools33 | `...\app\Extensions\MultiPac\MultiPac.php` |
| REST | Laravel HTTP client para validador CSD; Guzzle es dependencia transitiva/directa | `...\ConfiguracionController.php::validarCsd`; `...\composer.json` |
| XML | `DOMDocument`, `DOMXPath`, `SimpleXMLElement`; referencia obsoleta a `LSS\Array2XML` no declarada en Composer | controladores fiscales; `...\MultiPac.php` |
| PDF | `barryvdh/laravel-dompdf ^3.1` y servicio SOAP WSTools33 | `...\composer.json`; `...\FacturasController.php::generarPdfBase64FallbackDompdf`; `...\MultiPac.php::generatePDFV33` |
| Criptografía | Binario OpenSSL invocado con `exec`; certificado DER convertido a PEM y key PKCS#8 desencriptada a PEM | `...\ConfiguracionController.php::convertCerToPem`, `convertKeyToPem` |
| PAC | MultiPac/TimbradorXpress para timbrado/cancelación; servicio distinto `facturaloplus.com/WSTools33` para PDF | `...\app\Extensions\MultiPac\MultiPac.php` |

Dependencias principales adicionales: Livewire `^3.4`, Alpine.js, Laravel Sanctum `^4.0`, Jetstream `^5.0`, Guzzle `^7.2`, `verot/class.upload.php ^2.1`, Vite y Tailwind. Sus constraints están en `composer.json` y `package.json`; no fue necesario instalar nada.

## 2. Inventario fiscal completo

### Componentes críticos

| Ruta/clase | Tipo y métodos principales | Responsabilidad, dependencias y tablas | Reutilización/acoplamiento/riesgo |
|---|---|---|---|
| `D:\GitHub\factucare2-0\app\Http\Controllers\FacturasController.php` — `App\Http\Controllers\FacturasController` | Controlador. `create`, `preview`, `timbrar`, `generarXmlCfdi40DesdePayload`, `calcularResumenFactura`, `guardarFacturaTimbrada`, `cancelar`, descargas y PDF | CFDI 4.0, cálculos, PAC, persistencia, folios. Tablas: `facturas`, `factura_detalles`, `facturas_impuestos`, `clientes`, `folios`, `users`, `users_perfil`, `users_info_factura`, `users_info_factura_documentos`, catálogos SAT | Lógica fiscal útil, fuertemente acoplada; riesgo alto por tamaño, duplicación, floats e idempotencia |
| `...\app\Http\Controllers\ComplementosController.php` — `App\Http\Controllers\ComplementosController` | Controlador. `facturasPendientes`, `normalizePayloadPagos`, `extractPago20SourceTaxesFromFacturaXml`, helpers decimales, `generarXmlPagos20DesdePayload`, `timbrar`, `cancelar` | Pagos 2.0. Tablas: `complementos`, `complementos_pagos`, `facturas`, `clientes`, `folios`, `users`, `users_perfil`, `users_info_factura`, `informacion` | Helpers decimales reutilizables con adaptación; controlador completo de alto acoplamiento y riesgo alto |
| `...\app\Http\Controllers\Traits\PacMultiPacTrait.php` — `PacMultiPacTrait` | Trait. `cargarCsdParaTimbrado`, `timbrarConPacMultipac`, `generarPdfBase64DesdePacV33` | Adaptador PAC usado por Complementos. Duplica métodos de Facturas | Referencia/adaptación; riesgo alto de divergencia |
| `...\app\Extensions\MultiPac\MultiPac.php` — `MultiPac` | Librería/cliente SOAP. `callTimbrarCFDI`, `callCancelarPEM`, `generatePDFV33`; `generarFacturaWhitData` legado | Crea clientes SOAP, autentica por API key, llama `timbrarConSello`/`cancelarPEM`; contiene implementación CFDI 3.3 legacy y credenciales PDF hardcodeadas | Sólo referencia para contrato PAC; riesgo crítico de seguridad y código obsoleto |
| `...\app\Http\Controllers\ConfiguracionController.php` | Controlador. `uploadCsd`, `validarCsd`, conversiones PEM, perfil | Carga CSD, llama validador REST, persiste metadatos/contraseña. Tablas `users_perfil`, `users_info_factura`, `users_info_factura_documentos` | Referencia; alto riesgo por webroot, TLS deshabilitado y secreto en BD |
| `...\app\Support\CsdStatus.php` — `CsdStatus` | Helper/servicio de presentación | Lee vigencia y banderas `validado`; no valida criptográficamente en cada uso | Adaptable; riesgo medio |
| `...\app\Http\Controllers\FoliosController.php`; `...\Models\Folio.php`; `...\Api\SeriesController.php` | Controlador/modelo/API | CRUD y lectura del contador en `folios`; incremento real ocurre al persistir timbrado | Adaptable con rediseño transaccional/idempotente |
| `...\app\Models\Factura.php`, `FacturaDetalle.php`, `FacturaImpuesto.php` | Modelos | Mapeo legacy y casts; tablas homónimas | Sólo referencia de datos; esquema incompleto y cantidad casteada a entero |
| `...\app\Models\Cliente.php`; `...\ClientesController.php`; `...\views\clientes\_form.blade.php` | Modelo/controlador/vista | Datos fiscales del receptor en `clientes` | Adaptable; validaciones SAT insuficientes |
| `...\app\Models\Producto.php`; `ClaveProdServ.php`; `ClaveUnidad.php`; controladores/API y `...\views\productos\_form.blade.php` | Modelos/controladores/vista | Producto, claves SAT y búsquedas | Adaptable; catálogos sin versión/fuente |
| `...\resources\views\facturas\create.blade.php` | Vista + JavaScript Alpine embebido | Captura conceptos, impuestos, relacionados y totales; construye payload JSON | Sólo referencia; frontend participa en cálculo y envía datos fiscales |
| `...\resources\views\facturas\preview.blade.php`, `invoice.blade.php`, `pdf.blade.php`, `partials\rows.blade.php`, `index.blade.php` | Vistas | Vista previa, representación, descarga/cancelación y fallback PDF | Plantillas adaptables; acopladas a estructura legacy |
| `...\resources\views\documentos\complementos\create.blade.php` | Vista + JavaScript Alpine embebido | Selección de facturas, saldos, impuestos DR/P, payload | Referencia; cálculo duplicado con servidor |
| `...\resources\views\documentos\complementos\preview.blade.php`, `invoice.blade.php`, `pdf.blade.php`, `partials\rows.blade.php`, `index.blade.php` | Vistas | Representación y fallback PDF de Pagos 2.0 | Adaptable con DTOs nuevos |
| `...\config\sat.php` | Configuración | Regímenes, tipos, métodos y formas de pago parciales | No reutilizar como catálogo autoritativo; incompleto y sin versión |
| `...\config\timbradorxpress_errors.php` | Configuración | Diccionario de errores PAC/SAT | Referencia útil; nombres de operación no siempre coinciden con llamadas |
| `...\routes\web.php` | Configuración de rutas | Puntos de entrada fiscales | Sólo mapa de flujo |
| `...\database\migrations\2026_03_10_180000_add_total_to_facturas_table.php` | Migración | Agrega `facturas.total DECIMAL(18,2)` | Evidencia parcial; no reconstruye esquema fiscal |
| `...\app\Console\Commands\BackfillFacturasTotalCommand.php` | Comando | Recupera `Total` desde XML para filas antiguas | Útil como referencia de reparación, no como dominio |

### Archivos obsoletos, duplicados o desconocidos

- **Duplicado:** métodos PAC/CSD/PDF de `FacturasController` y `PacMultiPacTrait`.
- **Obsoleto:** `MultiPac::generarFacturaWhitData` genera CFDI 3.3, usa clases/modelos no presentes (`UsersInfoFacturaDocumentos`, `Flash`, `Array2XML`) y escribe `storage/logs/xml_debug_cfdi.xml`. No es invocado por el flujo CFDI 4.0 localizado.
- **Ruta inválida probable:** `routes/web.php` declara `App\Http\Controllers\Users\FacturasController::rows`, clase inexistente en el repositorio.
- **Duplicación de rutas API:** series/productos/SAT aparecen en dos grupos dentro de `routes/web.php`.
- No se encontraron Jobs, Requests dedicados, stored procedures, comandos de PAC, migraciones fiscales completas, archivos `.cer/.key/.pem`, WSDL/XSD locales, XML/PDF de prueba, Postman ni mocks.

## 3. Arquitectura fiscal real

```text
Vista Blade + Alpine (payload JSON y cálculo visual)
        ↓
POST preview / sesión factura_draft o complemento_draft
        ↓
Normalización y recálculo en controlador
        ↓
DOMDocument construye CFDI 4.0 / Pagos 2.0
        ↓
Lectura de CER + KEY.PEM desde public/uploads/users_documentos
        ↓
Inyección de Certificado/NoCertificado y Sello vacío
        ↓
MultiPac SOAP timbrarConSello(apikey, XML, keyPEM)
        ↓
XML timbrado + UUID; PDF por WSTools33 o Dompdf fallback
        ↓
Transacción local: cabecera/detalles/impuestos, folio y timbre
        ↓
XML/PDF/acuse almacenados en BD y descarga autenticada
```

| Etapa | Archivo/clase/método | Entrada → salida | Errores y persistencia |
|---|---|---|---|
| Captura | vistas `facturas/create` o `documentos/complementos/create` | Inputs → JSON | Validación sólo cliente/folio/importe en JS; draft en sesión al hacer preview |
| Validación usuario | `routes/web.php`, controladores | sesión autenticada → `auth()->id()` | Middleware `auth:sanctum`,`verified`; aislamiento por `users_id` |
| Emisor/receptor | generadores XML | BD + payload → nodos Emisor/Receptor | Exige cliente; factura exige CP/régimen receptor; pagos exige datos completos |
| Conceptos/impuestos | generadores y helpers | payload → nodos y totales | Floats en factura; enteros escalados principalmente en pagos |
| CSD | controlador/trait `cargarCsdParaTimbrado` | documentos BD → cert base64/noCert/key PEM | Falla si faltan archivos; no revalida vigencia/correspondencia al timbrar |
| PAC | `MultiPac::callTimbrarCFDI` | XML + PEM + API key → objeto/string SOAP | SoapFault retorna respuesta cruda; sin retry/timeout total explícito |
| PDF | `generatePDFV33` o Dompdf | XML timbrado → PDF base64 | El fallo de PDF no revierte timbrado; se permite BD sin PDF |
| Persistencia | `guardarFacturaTimbrada`/`guardarComplementoTimbrado` | respuesta PAC + payload → filas | Transacción posterior al PAC; si falla, el PAC ya pudo timbrar sin registro local |

No se encontraron servicios de dominio independientes, Jobs, stored procedures ni validación XSD local. Hay lógica en controladores, trait, cliente tercero/legacy y JavaScript.

## 4. Integración con el PAC

**PAC identificado:** MultiPac/TimbradorXpress. Existe además WSTools33 de FacturaLoPlus para render PDF y un validador CSD de TotalNot. No se comprobó contractualmente si las tres marcas pertenecen al mismo proveedor.

### Operaciones del PAC

| Operación | Clase y método | Entrada | Salida | Persistencia | Errores |
|---|---|---|---|---|---|
| Timbrar/sellar | `MultiPac::callTimbrarCFDI` → SOAP `timbrarConSello` | API key, XML CFDI con certificado y sello vacío, key PEM | objeto con `data/xml/cfdi`, `uuid`, posible acuse/PDF; o string crudo | factura/complemento almacena XML, UUID, solicitud | SoapFault convertido a lastResponse; wrappers rechazan XML vacío |
| Cancelar | `MultiPac::callCancelarPEM` → SOAP `cancelarPEM` | key PEM, cert PEM, UUID, RFCs, total, motivo, sustitución | status/code/message/data/acuse | estatus `CANCELADA`, acuse; consume timbre | Sólo éxito `status=success` o `code=0`; no modela aceptación/pendiente |
| PDF | `MultiPac::generatePDFV33` → SOAP `generarPDF` | credenciales tools, XML base64, plantilla, JSON base64, logo | PDF base64; éxito esperado código 210 | columna `pdf` | Fallback Dompdf; catch usa cliente posiblemente no inicializado |
| Consulta de estatus | No implementada en flujo | — | — | — | Sólo hay diccionario de códigos de consulta y método legado de autorizaciones, sin ruta/uso |
| Descarga | No es operación PAC | — | — | Se sirve desde BD | Autorización por usuario |

### Configuración del PAC

| Dato | Variable/campo | Ubicación | ¿Secreto? | Recomendación |
|---|---|---|---|---|
| Ambiente | `MULTIPAC_MODE` (`prod`/`dev`) | `MultiPac::__construct`, entorno | No | Config tipada por ambiente, no `env()` directo fuera de config |
| WSDL producción | `MULTIPAC_WSDL_PROD` | entorno; default `https://app.timbradorxpress.mx/ws/servicio.do?wsdl` | No | Config validada y allowlist HTTPS |
| WSDL pruebas | `MULTIPAC_WSDL_DEV` | entorno; default `https://dev.timbradorxpress.mx/ws/servicio.do?wsdl` | No | Separar credenciales y bloquear producción en test |
| API keys | `MULTIPAC_APIKEY_PROD`, `MULTIPAC_APIKEY_DEV` | entorno | Sí | Secret manager, rotación y redacción de logs |
| PDF WSDL | literal WSTools33 por HTTP | `MultiPac::__construct` | No | HTTPS, config externa y evaluar eliminación |
| Usuario/password PDF | propiedades `usuarioTools`, `passwordTools`, valores literales | `MultiPac::__construct` | Sí | Revocar/rotar y mover a secret manager; nunca migrar el valor |
| Timeout | `connection_timeout=30`; `default_socket_timeout=600000` sólo método legado | `MultiPac` | No | Timeouts de conexión/lectura totales, circuit breaker y métricas |
| CSD password | `users_info_factura.password` | `ConfiguracionController::uploadCsd` | Sí | No persistir o cifrar con KMS; PEM cifrado en almacenamiento privado |

No hay retry automático, token bearer REST del PAC ni API REST de timbrado. La única llamada REST fiscal es la validación de CSD; usa `Http::withoutVerifying()` y envía RFC, contraseña, CER y KEY a un endpoint externo, lo cual es riesgo crítico.

## 5. Proceso de timbrado

1. `GET /facturas/create` carga clientes/folios/catálogos y ventana de 72 horas.
2. Alpine construye un payload JSON. `POST /facturas/preview` normaliza folio e impuestos y guarda `factura_draft` en sesión.
3. `POST /facturas/timbrar` toma payload POST o draft; no usa Form Request ni reglas servidor exhaustivas.
4. `generarXmlCfdi40DesdePayload` consulta cliente/emisor, crea CFDI 4.0, conceptos, descuentos, impuestos y relacionados.
5. La fecha viene del payload; lugar de expedición del CP del perfil; serie/folio del payload normalizado desde `folios`.
6. `cargarCsdParaTimbrado` lee `.cer` y `.key.pem`; inyecta certificado/noCertificado y deja `Sello` vacío.
7. SOAP `timbrarConSello` recibe XML y `keyPEM`; el PAC sella/timbra.
8. Se exige XML timbrado; UUID se toma de respuesta o TimbreFiscalDigital.
9. Se intenta PDF remoto; si falla, Dompdf local; si ambos fallan, continúa sin PDF.
10. En una transacción local se inserta factura/detalles/impuestos, bloquea e incrementa folio y descuenta un timbre.
11. Se limpia draft y redirige a la representación.

Respuestas explícitas:

- El sistema construye **XML**, no JSON fiscal; JSON es sólo payload UI/PDF.
- El PAC no construye el XML base; recibe XML local.
- El sistema **no realiza el sello CFD** en el flujo vigente: deja `Sello=""`.
- El PAC realiza el sellado mediante `timbrarConSello` y el timbrado.
- Se envía certificado incorporado en XML y llave PEM como parámetro; no se envía el `.key` DER crudo. Para cancelación se envían key PEM y cert PEM.
- Se envía XML todavía no firmado y se recibe XML timbrado.
- Timbrado no garantiza PDF. Se solicita después a WSTools33; existe fallback local Dompdf.
- XML/PDF/acuse no se guardan como archivos: se almacenan en columnas BD (`facturas`/`complementos`). CSD sí queda como archivo en `public/uploads/users_documentos`.
- La relación administrativa es la propia fila de `facturas`, más `factura_detalles` y `facturas_impuestos`; no existe una entidad de venta separada encontrada.
- No hay protección robusta contra doble timbrado: no se reserva folio ni se registra intento antes de llamar al PAC; no hay idempotency key ni UUID único comprobado.

## 6. Datos del emisor

Tablas lógicas observadas: `users_perfil`, `users_info_factura`, `users_info_factura_documentos`, `users`; formularios en `resources/views/configuracion/index.blade.php`; controlador `ConfiguracionController`.

- RFC, razón social, régimen y CP se duplican entre `users_perfil` y `users_info_factura` mediante upserts.
- Facturas toman emisor de `users_perfil`; complementos prefieren `users_info_factura` con fallbacks a `users_perfil`, `informacion` y `users.username`.
- Lugar de expedición es CP del perfil/info fiscal. No se encontró entidad de sucursal.
- Series son por `users_id` y tipo; no hay FK comprobada a RFC/sucursal.
- Un conjunto vigente de certificado/llave por tipo se conserva: `replaceDocumento` elimina registros/archivos anteriores. No soporta múltiples certificados simultáneos de forma comprobada.
- El número y vigencia provienen del validador externo y se guardan en documentos; `CsdStatus` sólo lee esos metadatos.
- La contraseña se guarda en `users_info_factura.password`; no se encontró cifrado/cast encrypted.
- `.cer`, `.key`, `.cer.pem` y `.key.pem` quedan bajo webroot. La llave PEM resultante se genera desencriptada.
- Se valida correspondencia RFC/CER/KEY sólo por respuesta del servicio externo; no hay comprobación local ni al timbrar.
- Logos también están en webroot; plantilla remota es fija `1` para factura y `pagos2` para complemento.
- No se encontraron campos de ambiente/credenciales PAC por emisor ni correos fiscales dedicados.

**Inferencia:** un `users_id` representa un emisor operativo. La sesión contempla `rfc_activo`, pero las consultas fiscales continúan filtrando sólo por usuario; soporte multirRFC real no está comprobado.

## 7. Datos del receptor

`clientes`/`Cliente` guardan directamente RFC, razón social, régimen, CP, email, domicilio y contacto. No existe entidad fiscal separada. La validación CRUD sólo exige RFC/razón social/régimen de lista; CP es nullable, aunque el generador de XML lo exige. No valida patrón/longitud SAT del RFC, compatibilidad régimen/uso, nombre SAT ni CP de catálogo.

La factura sí conserva snapshot parcial del cliente en `facturas`: RFC, razón social, domicilio, teléfono y contacto. Los detalles fiscales `regimen_fiscal` y `uso_cfdi` no aparecen en el `$fillable` base; `uso_cfdi` se persiste sólo si la columna existe, y el régimen del receptor queda comprobablemente en el XML. Por ello, el **XML timbrado es el snapshot fiscal autoritativo**; vistas/persistencia auxiliar pueden depender de datos actuales o carecer de campos.

No se encontró soporte explícito de residencia fiscal, número de registro tributario, receptor extranjero, validación de RFC genéricos ni reglas especiales de público general. Sólo existe un fallback `XAXX010101000` en un método de persistencia alternativo/duplicado, no en el generador XML vigente.

## 8. Productos, servicios y conceptos

`productos` guarda clave interna, unidad comercial, precio `decimal:4` a nivel de cast, descripción, observaciones y FK lógicas a `clave_prod_serv`/`clave_unidad`. La vista copia al payload clave SAT, unidad SAT, unidad, descripción, cantidad, precio, descuento, identificador e impuestos.

`factura_detalles` conserva clave, unidad, precio, cantidad, importe/base, descripción, IVA y claves SAT. El XML conserva el snapshot fiscal completo emitido. Riesgos:

- Modelo `FacturaDetalle` castea `cantidad` como integer, pero el generador admite float y el insert comenta que MySQL podría truncar.
- `importe` persistido es la base después de descuento, no el `Importe` bruto del concepto.
- No se persisten por línea todos los traslados/retenciones/tasas/ObjetoImp; `facturas_impuestos` es agregado.
- No hay soporte localizado para predial, cuenta de terceros, partes o aduana.
- `ObjetoImp` se deriva: `02` si se creó un impuesto, `01` si no. No se implementa explícitamente `03` (sí objeto sin desglose) ni `04`.

## 9. Catálogos SAT

| Catálogo | Implementación | Fuente/versión/actualización | Uso real |
|---|---|---|---|
| Productos/servicios | `clave_prod_serv` + modelos/APIs | DDL, fuente, fecha e importador ausentes | Búsqueda y `ClaveProdServ` |
| Unidades | `clave_unidad` | Igual | Búsqueda y `ClaveUnidad` |
| Regímenes | array `config/sat.php` | Hardcodeado, sin versión | CRUD emisor/receptor |
| Usos CFDI | opciones en `facturas/create.blade.php` | Hardcodeado/incompleto | Payload/Receptor |
| Formas de pago | `config/sat.php` y catálogos privados duplicados en controladores | Hardcodeado/parcial | CFDI/Pago |
| Métodos de pago | `PUE`,`PPD` hardcodeados | Sin versión | CFDI y DoctoRelacionado |
| Monedas | catálogo privado Complementos; factura fuerza MXN en UI | Hardcodeado | Pagos/CFDI |
| Tipos relación | opciones en vista | Hardcodeado | `CfdiRelacionados` |
| Objeto impuesto | boolean/derivado | No catálogo | Conceptos y DoctoRelacionado |
| Exportación | opciones `01`,`02`; default `01` | Hardcodeado | Comprobante |
| Impuestos/tasas | IVA/ISR/IEPS y tasa editable | Hardcodeado | XML/calculadora |
| Tipo comprobante | `config/sat.php` | Hardcodeado | Folios/UI |
| Motivos cancelación | validación `01`-`04` y vista | Hardcodeado | Cancelación |
| Países, CP, colonias, periodicidad, meses | No se encontraron catálogos | No comprobado | No implementado |

No hay índices, FK, fuente SAT ni versión verificables porque faltan migraciones/DDL. No existe proceso de actualización de catálogos.

## 10. Series y folios

Tabla `folios`: campos comprobados por modelo/código `id`, `users_id`, `tipo`, `serie`, `folio`. El CRUD evita duplicado por consulta, pero no hay índice único comprobado. `SeriesController::next` toma el primer registro por usuario/tipo.

- El folio se presenta antes de timbrar; los drafts de sesión lo conservan.
- No se consume al preview ni al fallo PAC.
- Tras recibir timbrado, dentro de transacción local, `avanzarFolioYConsumirTimbre` usa `lockForUpdate` y aumenta `folio`.
- Complementos usan flujo análogo con `avanzarFolioComplemento` y `consumirTimbre`.
- No hay reserva previa. Dos peticiones pueden enviar simultáneamente el mismo serie/folio al PAC antes de que cualquiera adquiera el lock local.
- Si PAC timbra y falla persistencia, folio no avanza; el retry puede producir timbre previo/duplicado.
- No hay series por sucursal/RFC; sí por usuario y tipo. Cancelados/fallidos no revierten folio.

## 11. Cálculos fiscales y precisión

| Cálculo | Ubicación | Precisión/redondeo | Riesgo |
|---|---|---|---|
| Importe concepto | `FacturasController::generarXmlCfdi40DesdePayload` | `float`, cantidad×precio, `round(...,2)` por concepto | Alto: SAT admite escalas mayores; pérdida anticipada |
| Descuento/base | mismo | descuento y base a 2 decimales antes de impuesto | Alto: acumulación/centavo según precisión original |
| Tasa | mismo | porcentaje convertido a razón, `round`/formato 6 | Medio: heurística `>=1` interpreta `1` como 1%, no 100% |
| Impuesto línea | mismo | base 2 × tasa, redondeado 2 por concepto | Medio: consistente internamente, pero restrictivo |
| Impuesto global | mismo | suma de importes ya redondeados por clave | Bajo respecto a CFDI40221; decisión explícita |
| Total factura | mismo y `calcularResumenFactura` | redondeo incremental y final a 2 | Alto: rutas duplicadas pueden divergir |
| Frontend factura | `facturas/create.blade.php` | JavaScript Number + `Math.round` a 2 | Alto: visual/payload y servidor pueden divergir; impuestos locales no se incorporan al XML localizado |
| Saldos pagos | `normalizePayloadPagos` | primero float/round a 2 | Medio: se pierde precisión antes de helpers exactos |
| Helpers Pagos | `moneyString`, `decimalToScaledInt`, `roundDivide`, etc. | strings → enteros escalados (centavos/micros), half-up | Bajo/medio: buena técnica, limitada a enteros PHP y entrada ya redondeada |
| Prorrateo pago | `prorateMoney` | centavos×centavos/saldo, half-up a centavo | Medio: distribuir varios impuestos puede dejar residuo |
| Impuesto pago | `taxAmountFromBase` | centavos×micros/1e6, half-up | Bajo |
| Frontend pagos | vista create | JavaScript Number/Math.round; caso especial monto/1.16 | Alto: cálculo duplicado y base presumida |
| Persistencia | inserts con casts float; tipos DDL desconocidos salvo `facturas.total DECIMAL(18,2)` | no comprobable | Alto |

No se encontraron BCMath (`bcadd`, `bcsub`, `bcmul`, `bcdiv`). Hay abundantes casts `(float)` y `number_format`. Caso típico de diferencia de centavo: tres líneas con bases que generan IVA fraccional; el sistema suma cada impuesto redondeado, mientras otro sistema podría redondear sólo el agregado. Otro: precio/cantidad con más de dos decimales se redondea antes de calcular base.

## 12. Impuestos

- Traslados y retenciones se representan por concepto en el payload/XML; agregados en `facturas_impuestos` con impuesto, tipo, tasa entera y monto.
- Mapeo SAT: ISR `001`, IVA `002`, IEPS `003`.
- Base = importe bruto redondeado menos descuento redondeado.
- Tasa y cuota comparten estructura; no se observó tratamiento matemático diferenciado para cuota.
- Exento crea traslado sin tasa/importe.
- Sin impuestos produce `ObjetoImp=01`; con impuestos, `02`. No hay opciones explícitas 03/04.
- Múltiples tasas se agrupan por impuesto/factor/tasa en XML.
- Retención global agrupa sólo por impuesto, no por tasa; el nodo global SAT sólo exige impuesto/importe, pero la persistencia agregada puede perder desglose.
- `iva` de cabecera se llena con traslados menos retenciones, semánticamente ambiguo.
- Impuestos locales se calculan en resumen/UI pero no se encontró nodo `ImpuestosLocales` incorporado al XML CFDI 4.0.

## 13. Factura de ingreso

Creación directa: soportada. Desde venta/cotización: no se encontraron entidades/rutas. Borrador: sólo sesión, no tabla/estado persistente. Edición fiscal posterior: no existe ruta; sólo regenerar PDF/cancelar. Ingreso/egreso/traslado comparten el mismo generador, según `tipo_comprobante`.

Estados comprobados: inserción directa `TIMBRADA`; cancelación cambia a `CANCELADA`. No se persiste `BORRADOR`, `ERROR`, `TIMBRANDO` ni `PENDIENTE_CANCELACION`. Descarga y representación usan la fila timbrada. Envío por correo no se encontró en el flujo vigente. Público general no está implementado de forma específica.

## 14. CFDI de egreso y notas de crédito

La UI permite tipo `E`; el mismo generador produce `TipoDeComprobante=E`, conceptos, impuestos y `CfdiRelacionados`. La vista ofrece relación `01` nota de crédito y `03` devolución. No existe controlador/modelo separado de nota de crédito ni efecto administrativo sobre saldo/factura original. Por tanto, la nota fiscal de egreso y una devolución administrativa **no están implementadas como proceso integrado comprobable**. Timbrado/cancelación/estados son los mismos de factura. La relación depende exclusivamente del payload y no se persiste en tabla dedicada; queda en XML.

## 15. Complementos de pago

```text
GET complementos/create
  ↓ cliente
GET complementos/facturas-pendientes
  ↓ facturas no canceladas por RFC con UUID/XML
Saldo = último saldo_insoluto de complementos_pagos o total XML
  ↓ selección de una o varias facturas
Frontend calcula pago/saldo/impuestos sugeridos
  ↓ POST preview (draft de sesión)
Servidor normaliza y vuelve a obtener impuestos de XML original
  ↓ prorratea bases y recalcula impuestos con enteros escalados
Construye CFDI 4.0 + complemento Pagos 2.0
  ↓ SOAP timbrarConSello
Transacción: complemento + doctos + folio + timbre
```

Hechos relevantes:

- Un complemento modela **un nodo Pago** con múltiples `DoctoRelacionado`. No se encontró soporte para varios nodos Pago en un CFDI.
- Un documento puede aparecer en varios complementos: parcialidad se calcula como cantidad previa + 1 y saldo del último registro.
- Fecha CFDI y fecha de pago se capturan por separado. Forma, moneda, tipo de cambio y datos bancarios son del único Pago.
- Factura aporta UUID, total, moneda, método, serie/folio e impuestos originales desde XML. Cliente aporta receptor. Producto no participa directamente; se leen impuestos de conceptos del XML.
- Para pago parcial, las bases originales agrupadas se prorratean por `monto_pago/saldo_anterior` y el impuesto se recalcula desde base×tasa. Para pago total conserva base original, pero también recalcula importe en lugar de reutilizar `importe_original`.
- `ObjetoImpDR` es booleano 01/02; los impuestos DR y P se generan/agrupan.
- Totales SAT explícitos sólo reconocen IVA 16%, 8%, 0%, exento y retenciones IVA/ISR/IEPS. Otras tasas pueden aparecer en `ImpuestosP` sin atributos de Totales equivalentes.
- `EquivalenciaDR` se fija siempre a `1`; si monedas difieren además agrega un atributo `TipoCambioDR`, lo cual debe validarse contra el estándar vigente antes de migrar. Es un riesgo crítico multimoneda.
- La vista sugiere base `monto/1.16` para IVA 16 incluido; el servidor normalmente reemplaza con impuestos extraídos del XML original.
- Persistencia `complementos_pagos` sólo conserva UUID/documento, parcialidad y saldos; impuestos quedan en XML, no en tabla propia.
- Cancelar un complemento restablece `saldo_insoluto=saldo_anterior` en sus líneas.
- No existe sustitución automática ni consulta/aceptación; sólo motivo y UUID sustituto al cancelar.

## 16. Público general e Información Global

No se encontró flujo de Información Global, campos `Periodicidad`, `Meses`, `Año`, agrupación de ventas, selección de tickets ni prevención de duplicados. Tampoco se encontró configuración explícita del receptor público general `XAXX010101000` en el generador activo. Hay un fallback de RFC genérico en un método alternativo de persistencia, insuficiente para afirmar soporte. Por tanto, esta funcionalidad debe considerarse **no implementada/no comprobada**, no migrable desde este repositorio.

## 17. CFDI relacionados

No hay tabla/modelo/controlador dedicado. La vista `facturas/create.blade.php` permite arreglo uno-a-muchos `{tipo_relacion, uuid}`; `generarXmlCfdi40DesdePayload` agrupa por tipo y crea uno o varios `CfdiRelacionados`. No valida formato UUID, existencia, propiedad, compatibilidad del tipo ni duplicados. La relación sólo queda en XML/solicitud. Cancelaciones aceptan UUID sustituto separadamente. Complementos relacionan documentos mediante Pagos 2.0, no `CfdiRelacionados`.

## 18. Cancelaciones

Facturas y complementos aceptan motivos `01`-`04`; motivo `01` exige sustitución. Extraen UUID/RFCs/total del XML y llaman SOAP `cancelarPEM`. Si respuesta es éxito (`status=success` o código 0), marcan inmediatamente `CANCELADA`, guardan acuse y consumen timbre.

No se implementan: cancelación que requiere aceptación, estado pendiente, polling/consulta SAT, rechazo posterior, reintento controlado, folio de seguimiento ni descarga separada de acuse para complementos. Facturas sí tienen descarga de acuse almacenado. La cancelación administrativa y fiscal están mezcladas: el cambio administrativo sólo ocurre después de una respuesta PAC considerada exitosa, pero el modelo no representa estados SAT intermedios. Hay guard contra estatus ya cancelado, no contra dos solicitudes concurrentes.

Error localizado: mensaje de Facturas dice “motivo 04” cuando la condición valida motivo `01`. El diccionario usa claves `cancelacion`, pero el controlador invoca `traducirCodigoPac('cancelar',...)`, por lo que códigos específicos pueden no traducirse.

## 19. XML, PDF y archivos fiscales

- XML se genera con DOM y se almacena completo en BD; no hay validación XSD ni cadena original local.
- El sello CFD, sello SAT, certificado SAT, fecha de timbrado, UUID y QR dependen del XML/PDF devuelto/generado. El código local no construye QR ni cadena original.
- PDF remoto se genera desde XML base64, JSON y logo; fallback Dompdf usa `facturas/pdf.blade.php` o `documentos/complementos/pdf.blade.php`.
- Nombres de descarga: `SerieFolio - UUID.xml/pdf`.
- Descargas pasan por autenticación y filtro `users_id`; los XML/PDF no son archivos públicos.
- CSD/logos sí están en `public/uploads`; no se comprobó regla del servidor que impida descarga directa.
- Puede existir BD con XML sin PDF: explícitamente permitido. No debe existir fila timbrada sin XML en el flujo normal, pero datos legacy pueden tenerla. Un XML no puede quedar como archivo huérfano porque no se escribe a disco en flujo activo.
- No se encontró correo fiscal ni regeneración de XML; sí regeneración de PDF.

## 20. Borradores, errores e idempotencia

Borradores viven en sesión (`factura_draft`, `complemento_draft`) y pueden editarse/reintentarse; no tienen ID, estado, TTL propio ni folio reservado. Escenarios:

1. **Doble clic/doble petición antes del primer commit:** ambas pueden llamar PAC con mismo folio; no hay lock previo/idempotency key.
2. **Timeout con PAC que sí timbró:** se recibe error/string y no persiste; retry puede devolver timbre previo o crear inconsistencia.
3. **PAC exitoso, PDF falla:** se persiste con PDF vacío; regeneración posterior disponible.
4. **PAC exitoso, insert/folio/timbre falla:** transacción local revierte, pero timbre fiscal externo permanece; no hay reconciliación por UUID.
5. **Insert exitoso, archivo falla:** no aplica a XML/PDF porque están en BD; sí puede haber base64 inválido no validado.
6. **Folio/timbre concurrente:** lock evita pérdida de incremento dentro de transacción, pero llega después del PAC.
7. **Cancelación simultánea:** ambos requests pueden pasar guard antes del primer update y llamar PAC.
8. **Respuesta PAC incompleta:** XML vacío se trata como error; UUID vacío se intenta extraer.
9. **Draft desactualizado:** folio puede normalizarse por serie, pero si trae `folio_id` válido no se refresca el contador.

No se encontraron llaves únicas fiscales, transacción que englobe servicio externo, tabla de intentos, outbox, idempotency key, retry/backoff ni reconciliador. Logs estructurados existen principalmente en Complementos; Facturas devuelve el mensaje al usuario y no registra sistemáticamente.

## 21. Tablas fiscales

El repositorio **no contiene DDL/migraciones originales** para las tablas siguientes. Sólo pueden documentarse columnas utilizadas; PK/FK/índices/tipos/restricciones/borrado lógico son no comprobados salvo indicación.

| Tabla | Finalidad y columnas observadas | Relaciones/riesgos |
|---|---|---|
| `facturas` | id, users_id, receptor, estatus, fechas, xml, pdf, solicitud_timbre, acuse, descuento, uuid, tipo, serie/folio y totales opcionales | PK lógica id; user lógico. `total` sí es DECIMAL(18,2) por migración. Sin unique UUID comprobado |
| `factura_detalles` | id, users_facturas_id, clave/unidad/precio/cantidad/importe/descripcion, iva, claves SAT | FK lógica a facturas; tipo cantidad desconocido; snapshot incompleto |
| `facturas_impuestos` | id, users_facturas_id, impuesto/tipo/tasa/monto | tasa se castea integer; pierde tasas fraccionarias |
| `complementos` | id, users_id, cliente, UUID, estatus, XML/PDF/acuse/solicitud, serie/folio/fechas/datos de pago opcionales | Sin modelo/migración/unique comprobado |
| `complementos_pagos` | id, users_complementos_id, documento_id, factura_id opcional, parcialidad, saldos, moneda/método | FK lógicas; no snapshot de impuestos |
| `clientes` | datos fiscales/personales y users_id | Sin FK/índices comprobados; datos sensibles |
| `productos` | catálogo propio y FK lógicas SAT | precio cast decimal:4, DDL desconocido |
| `clave_prod_serv`, `clave_unidad` | id, clave, descripción; unidad opcional | Catálogos sin metadata/versión |
| `folios` | id, users_id, tipo, serie, folio | Lock usado; unique compuesto no comprobado |
| `users_perfil` | emisor/domicilio/régimen/CP | Duplicado con info_factura |
| `users_info_factura` | emisor, contraseña CSD, CP/régimen | Secreto sensible aparentemente claro |
| `users_info_factura_documentos` | tipo, nombre/ruta/path, no certificado, vigencia, validado/revisado | Archivos en webroot; FK lógica a info_factura |
| `users` | cuenta, rol, timbres_disponibles | contador bloqueado al consumir |
| `informacion` | fallback emisor sólo en Complementos | Función/esquema no comprobados |

No se observaron soft deletes ni timestamps fiscales consistentes. No se puede afirmar precisión de columnas monetarias legacy; esto es un bloqueo documental que requiere dump de esquema sanitizado.

## 22. Seguridad

Riesgos comprobados:

- CSD y llave DER/PEM desencriptada en `public/uploads/users_documentos`.
- Password del CSD se escribe en BD sin cifrado visible.
- Validador CSD externo recibe contraseña, CER y KEY con verificación TLS deshabilitada.
- Credenciales WSTools33 hardcodeadas en fuente. Sus valores se omiten deliberadamente.
- Cliente SOAP tiene `trace=true`, lo que puede retener requests con API key/key PEM en memoria; no se vio log explícito de lastRequest, pero aumenta exposición.
- `Log::error` de complementos incluye trace y metadatos fiscales; log de validación de pagos registra bases/importes/UUID.
- XML/PDF contienen PII fiscal, aunque sus endpoints filtran por usuario.
- No hay autorización por rol; sólo dueño/usuario.
- No se encontró cifrado de XML/PDF/acuse en BD ni de archivos en disco.
- El código legado escribe un XML de depuración a `storage/logs`, aunque el método parece no usado.

## 23. Código reutilizable

| Elemento | Clase/método | Clase | Motivo/estrategia |
|---|---|---|---|
| Helpers enteros Pagos | `ComplementosController::decimalToScaledInt`, `formatScaledInt`, `roundDivide`, `taxAmountFromBase` | A/B | Desacoplados en esencia; extraer a servicio Money/Decimal y probar límites/overflow |
| Parser de XML original | `extractPago20SourceTaxesFromFacturaXml` | B | Regla útil, acoplada a DB/helpers; convertir a parser puro y preservar escalas SAT |
| Constructor CFDI 4.0 | `generarXmlCfdi40DesdePayload` | B/C | Demuestra estructura y PAC, pero usa DB/auth/floats/defaults; rediseñar con DTO/validador |
| Constructor Pagos 2.0 | `generarXmlPagos20DesdePayload` | B/C | Valioso fiscalmente; corregir multimoneda, separar cálculos y validar SAT |
| Agrupación/redondeo impuestos | cierres `$addTras/$addRet` y `calculatePagos20Totals` | B | Reutilizar reglas con pruebas doradas, no copiar controlador |
| Adaptador PAC | `PacMultiPacTrait`, `MultiPac` | C | Contrato SOAP imprescindible; reescribir como servicio CI4 con secretos/config/timeouts |
| Diccionario errores | `config/timbradorxpress_errors.php` | B/C | Migrar tras alinear nombres de operación y confirmar códigos actuales |
| Carga CSD | `ConfiguracionController`, trait | C/D | Evidencia de formatos; no conservar almacenamiento webroot, TLS débil ni PEM claro |
| `MultiPac::generarFacturaWhitData` | método legacy CFDI 3.3 | D | Dependencias rotas, prácticas inseguras y versión obsoleta |
| Vistas/JS fiscales | create Blade | C | Útiles para campos/payload; no trasladar cálculos como fuente de verdad |
| Modelos legacy | modelos fiscales | C | Referencia de compatibilidad; diseñar esquema fiscal versionado nuevo |

## 24. Dependencias mínimas para migrar el PAC

**Pertenecen al PAC:** WSDL de ambiente; API key de ambiente; operación SOAP `timbrarConSello(apikey, xmlCFDI, keyPEM)`; `cancelarPEM(...)`; shapes de respuesta tolerados; opcional WSTools33 para PDF.

**Decisiones FactuCare:** XML por DOM, almacenamiento BD base64/texto, tablas, drafts de sesión, contador de timbres/folios, plantilla PDF, fallback Dompdf, redondeo a 2 y estructura de controladores.

Mínimo futuro:

1. Cliente SOAP con extensión PHP SOAP, timeouts y TLS estricto.
2. Config/secretos por ambiente: mode, WSDL, API key; credenciales PDF sólo si se mantiene ese servicio.
3. Emisor válido (RFC/nombre/régimen/CP), CSD DER y llave privada desencriptable en memoria, no en webroot.
4. Constructor/validador CFDI 4.0 y Pagos 2.0, cadena de tipos decimales y catálogos SAT versionados.
5. Request de timbrado: XML con certificado/noCertificado y sello vacío + key PEM.
6. Normalizador de response, parser UUID/XML y catálogo de errores.
7. Persistencia mínima: documento fiscal, intento PAC, UUID unique, request hash, XML, acuse, estatus y relación administrativa.
8. Idempotencia/reconciliación, logs redactados y métricas.
9. Cancelación con estados SAT pendientes/aceptación y consulta.
10. PDF local preferente; WSTools33 es opcional y no requisito probado del timbrado.

## 25. Pruebas existentes

La carpeta `tests` contiene pruebas genéricas de autenticación, Jetstream/Sanctum y ejemplos. No hay unit/feature/integration tests fiscales, fixtures, XML, payloads, respuestas, mocks PAC, Postman, WSDL/XSD, scripts aislados ni pruebas seguras de sandbox. `BackfillFacturasTotalCommand` es diagnóstico/reparación manual, no prueba. No se ejecutó PHPUnit porque no aporta cobertura fiscal y podía depender del entorno.

Partes sin pruebas: todos los cálculos, XML CFDI/Pagos, PAC, CSD, folios, doble timbrado, cancelación, PDF, catálogos y seguridad.

## 26. Casos de prueba recomendados

| Grupo | Casos y aserciones esenciales |
|---|---|
| Ingreso | PUE/PPD; IVA 16/0/exento/no objeto; descuento; múltiples conceptos/tasas; retenciones; IEPS; moneda extranjera/TC; XML contra XSD y totales exactos |
| Egreso/relación | Nota crédito; relación 01/03; varios UUID; sustitución 04/01; efecto administrativo explícito |
| Público general | RFC genérico, régimen/uso/CP; Información Global diaria/semanal/mensual, meses/año, no duplicar ventas |
| Pagos | simple; varias facturas; parcialidades; múltiples complementos por factura; varias monedas; EquivalenciaDR; IVA 16/8/0/exento; retenciones; residuo de centavo |
| Cancelación | motivos 01-04; aceptación/pendiente/rechazo; previamente cancelado; sustitución; acuse y polling |
| Precisión | cantidades/precios de 6 decimales; 3 líneas con fracción de centavo; límites; tasas cuota; prorrateo cuyo reparto no suma |
| Resiliencia | timeout antes/después de timbre; respuesta duplicada; retry; doble clic; dos workers mismo folio; XML sin PDF; UUID sin commit; commit sin PDF; respuesta cruda SOAP |
| Seguridad | descarga cruzada; acceso directo a CSD; logs sin secretos; clave incorrecta; CER/RFC no correspondiente; vencido/revocado |

Cada prueba de PAC debe usar mock contractual o sandbox explícita con credenciales no productivas; nunca endpoints/secretos reales en CI.

## 27. Riesgos técnicos

| Prioridad | Riesgo | Evidencia | Impacto | Recomendación |
|---|---|---|---|---|
| Crítica | CSD/PEM en webroot | `ConfiguracionController::storeDocumentoArchivo` | Compromiso de identidad fiscal | Almacenamiento privado cifrado, permisos mínimos, no PEM persistente |
| Crítica | Password CSD y credencial PDF inseguras | `uploadCsd`; `MultiPac::__construct` | Fuga de secretos | Rotar, secret manager, cifrado/KMS |
| Crítica | Validador externo sin TLS | `validarCsd::withoutVerifying` | Intercepción de KEY/password | Eliminar o TLS estricto/validación local |
| Crítica | Doble timbrado/no idempotencia | PAC antes de lock/insert | CFDI huérfano/duplicado | Intento durable + hash/idempotency + UUID unique + reconciliación |
| Alta | `float` y redondeo temprano | generador Facturas | Centavos/CFDI rechazado | Decimal string/entero con escalas SAT |
| Alta | Frontend calcula/envía totales fiscales | vistas create | Manipulación/divergencia | Servidor único autoritativo |
| Alta | Multimoneda Pagos simplificada | EquivalenciaDR fija 1 | Complemento incorrecto | Implementar fórmula SAT y casos cruzados |
| Alta | Falta DDL fiscal | migraciones | Migración ciega, tipos/índices desconocidos | Exportar esquema sanitizado y perfilar datos |
| Alta | Datos fiscales/versionado parcial | cliente + XML | Historial inconsistente | Snapshot fiscal explícito por documento |
| Alta | Controladores monolíticos/duplicación | dos controladores/trait | Regresiones/divergencia | Servicios puros/DTO/adaptador PAC |
| Alta | Sin pruebas fiscales | `tests` | Cambios no verificables | Suite golden XML y contratos PAC mock |
| Alta | Respuesta exitosa sin persistencia | transacción después PAC | CFDI perdido localmente | State machine/outbox/reconciliador |
| Alta | Cancelación simplificada | estado directo CANCELADA | Estado SAT falso | Estados pendiente/aceptación/consulta |
| Media | Folios concurrentes | lock posterior PAC | Mismo folio enviado | Reserva transaccional previa y unique |
| Media | Catálogos hardcodeados | config/vistas | Reglas desactualizadas | Catálogos SAT versionados |
| Media | Manejo SOAP deficiente | SoapFault → string raw | Diagnóstico/retry inseguro | Excepciones tipadas, códigos, timeout/backoff |
| Media | PDF externo HTTP | WSTools33 | Confidencialidad/indisponibilidad | PDF local y HTTPS |
| Media | XML/PDF accesibles | BD endpoints protegidos, CSD público | Descarga no autorizada si falla servidor | Storage privado y signed/authorized responses |
| Media | Reintentos no idempotentes | drafts/manual retry | Timbre previo | Idempotency keys y consulta por hash/UUID |

## 28. Conclusiones

La implementación tiene madurez **media en conocimiento práctico del payload** y **baja en arquitectura operativa/seguridad**. El constructor CFDI 4.0, la experiencia con CFDI40221 y los cálculos enteros de Pagos 2.0 muestran reglas comprobadas valiosas. Sin embargo, no debe copiarse el diseño actual: el PAC, BD, UI y cálculos están mezclados; secretos y CSD se manejan de forma insegura; no hay idempotencia ni pruebas.

Conviene reutilizar conceptualmente: contrato SOAP, estructura de XML, parsers, agrupación de impuestos, helpers decimales y mapa de respuestas. Sólo como referencia: controladores, trait, modelos y vistas. Deben descartarse/rediseñarse: cliente legacy CFDI 3.3, almacenamiento CSD, validador sin TLS, credenciales hardcodeadas, flujo de estado y folios.

No pudieron comprobarse: DDL/índices/tipos de casi todas las tablas; versión/fuente de catálogos; comportamiento real de endpoints/response PAC; vigencia de WSDL/operaciones; entorno productivo; roles reales; Información Global; aceptación/consulta SAT; correspondencia criptográfica local; envío por correo; pruebas exitosas históricas.

Archivos imprescindibles para migración: `FacturasController.php`, `ComplementosController.php`, `PacMultiPacTrait.php`, `MultiPac.php`, `ConfiguracionController.php`, `config/timbradorxpress_errors.php`, vistas create y un futuro dump sanitizado del esquema/ejemplos XML válidos.

## 29. Anexo de archivos críticos priorizados

| Prioridad | Ruta, clase/método | Responsabilidad/tablas | Riesgo/reutilización |
|---|---|---|---|
| 1 | `...\FacturasController.php::generarXmlCfdi40DesdePayload`, `timbrar`, `cancelar` | CFDI/`facturas*`, `folios`, CSD | Alto; B/C |
| 1 | `...\ComplementosController.php::normalizePago20DocumentTaxes`, helpers, `generarXmlPagos20DesdePayload`, `timbrar` | Pagos/`complementos*`, `facturas` | Alto; B/C |
| 1 | `...\Extensions\MultiPac\MultiPac.php::{__construct,callTimbrarCFDI,callCancelarPEM,generatePDFV33}` | Contrato SOAP | Crítico; C |
| 1 | `...\Traits\PacMultiPacTrait.php` | Adaptación PAC/CSD | Alto; C |
| 1 | `...\ConfiguracionController.php::{uploadCsd,validarCsd,convertKeyToPem}` | CSD/`users_info_factura*` | Crítico; C/D |
| 2 | `...\resources\views\facturas\create.blade.php` | Payload factura | Alto; C |
| 2 | `...\resources\views\documentos\complementos\create.blade.php` | Payload Pagos | Alto; C |
| 2 | `...\config\timbradorxpress_errors.php` | Respuestas | Medio; B/C |
| 2 | `...\FoliosController.php`, `...\Api\SeriesController.php` | Folios | Alto concurrencia; B |
| 3 | modelos `Factura*`, `Cliente`, `Producto`, catálogos | Mapa legacy | Medio; C |
| 3 | plantillas PDF | Representación | Medio; B/C |

## 30. Anexo de variables y configuración

| Variable/campo | Archivo/entorno | Uso | Secreto | Recomendación iKontrol2.0 |
|---|---|---|---|---|
| `MULTIPAC_MODE` | entorno, leído en `MultiPac.php` | Selección dev/prod | No | Enum validado |
| `MULTIPAC_WSDL_PROD` | entorno | SOAP producción | No | Config CI4 y allowlist |
| `MULTIPAC_WSDL_DEV` | entorno | SOAP pruebas | No | Separación fuerte de ambiente |
| `MULTIPAC_APIKEY_PROD` | entorno | Auth PAC prod | Sí | Secret manager/rotación |
| `MULTIPAC_APIKEY_DEV` | entorno | Auth PAC dev | Sí | Secret manager separado |
| `usuarioTools` | literal en `MultiPac.php` | PDF SOAP | Sí | Revocar/mover o eliminar servicio |
| `passwordTools` | literal en `MultiPac.php` | PDF SOAP | Sí | Revocar/mover; nunca copiar valor |
| `users_info_factura.password` | BD | Password CSD | Sí | No persistir o cifrar KMS |
| `_path`, `path`, `_name` | `users_info_factura_documentos` | Ubicación CSD | Sensible | ID opaco + storage privado |
| `numero_certificado`, `vigencia`, `validado` | misma tabla | Metadatos CSD | No/sensible | Extraer localmente y auditar |
| `DB_*` | `.env.example`/entorno | BD | usuario/password sí | Secret manager |
| `APP_KEY` | entorno | cifrado Laravel | Sí | Gestionar por ambiente |
| `FILESYSTEM_DISK` | entorno | storage | No | Disk fiscal privado dedicado |
| `QUEUE_CONNECTION` | entorno | cola, hoy sync | No | Cola durable para reconciliación, no para duplicar llamadas |
| Endpoint validador CSD | literal en `ConfiguracionController.php` | Validación remota | No | Eliminar o configurar con TLS estricto |
| Endpoint WSTools33 | literal en `MultiPac.php` | PDF | No | Eliminar/HTTPS/configurar |

---

### Cobertura de revisión

Se revisaron: `composer.json`, `composer.lock`, `package.json`, `.env.example`; `app/Http/Controllers` fiscales y APIs; `app/Extensions/MultiPac`; `app/Models`; `app/Support`; `app/Console`; `routes`; `config`; todas las migraciones; vistas de facturas, complementos, clientes, productos, folios, configuración y reportes; JavaScript embebido; `resources/js`; `tests`; nombres de artefactos fiscales en el repositorio. El único archivo creado por esta auditoría es este documento; no se modificó código funcional ni configuración.
