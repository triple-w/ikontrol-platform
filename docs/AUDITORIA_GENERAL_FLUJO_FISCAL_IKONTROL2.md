# Auditoría general del flujo fiscal de iKontrol 2.0

Fecha de corte: 24 de julio de 2026.

## 1. Resumen ejecutivo

El dominio fiscal ya contiene una base técnica amplia: perfiles de emisor y receptor, configuración fiscal de productos e impuestos, series, preparación de precios, snapshots inmutables, Pre-XML CFDI 4.0, validación XSD, cadena original, firma local, verificación criptográfica independiente, adaptador REST de TimbradorXpress, intento durable, validación de XML timbrado y código para almacenar el PDF Base64.

No está listo para operación diaria ni para declarar un timbrado completo estable. El flujo normal expone como mínimo seis acciones técnicas independientes. La base local tiene diez documentos, veinte artefactos y seis firmas, pero ningún timbre. Hay dos documentos en `stamping`, cuatro en `stamping_error` y un único intento durable restante en `sending`.

Los bloqueos principales son:

1. La contraseña del CSD **no se almacena**. Se solicita al cargar el certificado y otra vez al firmar cada documento. `fiscal.pacEncryptionKey` no cifra esa contraseña.
2. Las migraciones `090700` y `090800` están pendientes. La tabla de PDF Base64 no existe y las columnas PDF esperadas por el servicio tampoco existen.
3. El intento 11 quedó en `sending`, por lo que requiere conciliación antes de permitir cualquier reenvío.
4. La configuración bootstrap de `Config\Fiscal` contradice la configuración PAC efectiva: declara módulo deshabilitado/local/fake, mientras `Config\TimbradorXpress` está configurado para sandbox. Hoy el timbrado usa la segunda configuración y omite las guardas de la primera.
5. La interfaz de borrador expone cierre, generación, validación, firma, verificación, timbrado y consulta de intento como pasos normales.
6. La vista de resultado consulta incondicionalmente una tabla pendiente y por ello produce un error 500.

La recomendación es **estabilizar primero**, después introducir un orquestador transaccional por etapas y, al final, simplificar la interfaz y formalizar recuperación/reintentos. No debe ocultarse el flujo diagnóstico: debe moverse a “Herramientas fiscales avanzadas”.

## 2. Alcance y método

La auditoría fue de sólo lectura sobre código, rutas, migraciones, esquema, conteos y logs locales. Sólo se creó este documento.

No se llamó al PAC, no se modificaron datos, estados, XML, certificados, migraciones ni `.env`.

Fuentes principales:

- `app/Controllers/Fiscal/InvoiceReview.php`
- `app/Controllers/Fiscal/Stamping.php`
- `app/Config/FiscalRoutes.php`
- `app/Config/Fiscal.php`
- `app/Config/TimbradorXpress.php`
- `app/Services/Fiscal/FiscalDraftCreationService.php`
- `app/Services/Fiscal/Cfdi40/*`
- `app/Services/Fiscal/Signing/*`
- `app/Services/Fiscal/Pac/*`
- `app/Services/Fiscal/CsdCertificateService.php`
- `app/Views/fiscal/invoices/*`
- migraciones `060000` a `090800`
- `writable/logs`, sin reproducir secretos
- conexión real de CodeIgniter: base `ikontrol_new`, prefijo `ikontrol_`

## 3. Estado actual por componente

| Componente | Clasificación | Evidencia / observación |
|---|---|---|
| Perfiles receptor | Funcionando según implementación previa | Separados de clientes; snapshots por documento |
| Perfiles emisor | Parcialmente funcionando | Se usan para revisión, series y CSD; falta cerrar gestión automática del secreto CSD |
| Productos e impuestos fiscales | Funcionando para preparación | Alimentan readiness, simulación y snapshots |
| Readiness de venta | Funcionando en código y pruebas previas | `SaleFiscalReadinessService` bloquea datos incompletos |
| Preparación de precios | Funcionando | Es precondición obligatoria del snapshot |
| Snapshot fiscal | Funcionando | Diez documentos con snapshots relacionados, sin huérfanos detectados |
| Reemplazo de snapshot | Funcionando, visible en exceso | Usa `superseded`; requiere confirmación |
| Cierre | Funcionando | Cambia `ready` a `locked` y congela `issue_date` |
| Desbloqueo | No existe como operación normal | Una preparación cerrada se reemplaza; no se reabre |
| Cancelación interna | Funcionando para `draft/ready` | Estado `cancelled_internal` |
| Pre-XML | Funcionando | Ocho artefactos; almacenamiento privado e integridad SHA-256 |
| XSD | Implementado | Se valida en generación, acción manual y firma |
| Cadena original | Implementada | Se genera y persiste como artefacto |
| Firma local | Funcionando parcialmente | Seis firmas; depende de recapturar contraseña |
| Verificador independiente | Implementado | Recalcula cadena, certificado, RFC, número, sello y XSD |
| TimbradorXpress REST | Integración real implementada | `POST timbrar3`, sólo `apikey` y `xmlCFDI` |
| Intento durable | Parcialmente funcionando | Se crea antes de red; sólo queda el intento 11 en `sending` |
| Idempotencia | Implementada | Hash de documento, XML, proveedor, ambiente y operación; falta reconciliar estados heredados |
| XML timbrado | Implementado sin éxito local comprobado | Validador y persistencia existen; cero filas de timbre |
| PDF Base64 | Implementado pero roto en esta instalación | Migración pendiente; tabla inexistente |
| Resultado de timbrado | Roto | Consulta tabla inexistente y devuelve 500 |
| Conciliación | Implementada parcialmente | Recupera contingencia, pero no hay flujo completo probado para intento `sending` sin evidencia |
| Consulta SAT | Implementada sin prueba concluyente | Es otra llamada externa y debe permanecer avanzada |
| UI fiscal | Funcional para diagnóstico, inadecuada para operación | Expone demasiadas etapas técnicas |

## 4. Arquitectura existente

La separación interna por servicios es razonable y debe conservarse:

```text
Venta administrativa
  └─ SaleFiscalReadinessService
      └─ SaleTaxPricingSimulationService / preparación de precios
          └─ FiscalDraftCreationService
              ├─ snapshots emisor/receptor
              ├─ snapshots de conceptos/impuestos
              └─ fiscal_documents
                  └─ CfdiPreXmlArtifactService
                      ├─ CfdiDraftMapper
                      ├─ CfdiSemanticValidator
                      ├─ CfdiXmlBuilder
                      └─ CfdiXsdValidator
                          └─ CfdiSigningService
                              ├─ CsdCertificateService
                              ├─ CfdiOriginalChainGenerator
                              └─ SignedXmlVerifier
                                  └─ FiscalStampingService
                                      ├─ intento durable
                                      ├─ TimbradorXpressRestAdapter
                                      ├─ TimbradorXpressResponseParser
                                      ├─ StampedXmlValidator
                                      ├─ XML privado
                                      └─ PacPdfArtifactService
```

El problema no es la separación técnica. El problema es que el controlador y la vista trasladan esa misma granularidad al usuario.

## 5. Configuración

Valores lógicos observados; ninguna credencial fue leída o mostrada:

| Variable | Valor lógico actual | Consumidor real | ¿Conservar? |
|---|---|---|---|
| `fiscal.enabled` | `false` | Bootstrap legado; no gobierna el flujo de timbrado actual | Unificar o retirar como autoridad ambigua |
| `fiscal.environment` | `local` | Bootstrap legado | Sustituir por una sola fuente de ambiente |
| `fiscal.allowRealPac` | `false` | No bloquea `FiscalStampingService` | Convertir en guarda real o retirar |
| `fiscal.privateStoragePath` | Privado bajo `writable` | Configuración inicial; varios servicios usan rutas propias | Centralizar |
| `fiscal.pacAdapter` | `fake` | No selecciona el adaptador de timbrado actual | Retirar duplicidad o convertirlo en selector efectivo |
| `fiscal.pacEncryptionKey` | Configurada, longitud válida | `PacSecretVault`, sólo contingencia PAC | Conservar; no confundir con secreto CSD |
| `TIMBRADORXPRESS_ENVIRONMENT` | `sandbox` | `Config\TimbradorXpress` | Conservar |
| API key sandbox | Configurada | Adaptador REST | Conservar sólo en `.env` |
| API key production | No auditada como valor | Configuración | Debe permanecer vacía en esta etapa |
| Base URL sandbox | Host permitido `dev.timbradorxpress.mx` | Adaptador REST | Conservar allowlist |
| Base URL production | Allowlist fija | Configuración | Conservar, pero bloqueada |
| `TIMBRADORXPRESS_PRODUCTION_ENABLED` | `false` | Config y adaptador | Conservar |
| `app.appTimezone` | `America/Mexico_City` | Fechas de aplicación/snapshot | Conservar |
| `app.baseURL` | URL local esperada | Rutas y enlaces | Conservar por entorno |

Contradicción crítica: `Config\Fiscal` dice “deshabilitado/local/fake”, pero `FiscalStampingService` construye `Config\TimbradorXpress` directamente. Por ello la configuración efectiva es sandbox real aunque la configuración fiscal genérica parezca impedirlo.

Bloque propuesto para sandbox, sin secretos:

```dotenv
CI_ENVIRONMENT = development
app.baseURL = 'http://localhost/ikontrol2/ikon2.0/'
app.appTimezone = 'America/Mexico_City'

fiscal.enabled = true
fiscal.environment = sandbox
fiscal.allowRealPac = true
fiscal.pacAdapter = timbradorxpress
fiscal.pacEncryptionKey =

TIMBRADORXPRESS_ENVIRONMENT = sandbox
TIMBRADORXPRESS_APIKEY_SANDBOX =
TIMBRADORXPRESS_APIKEY_PRODUCTION =
TIMBRADORXPRESS_BASE_URL_SANDBOX = 'https://dev.timbradorxpress.mx/api/rest/servicio/'
TIMBRADORXPRESS_BASE_URL_PRODUCTION = 'https://app.timbradorxpress.mx/api/rest/servicio/'
TIMBRADORXPRESS_PRODUCTION_ENABLED = false
TIMBRADORXPRESS_CONNECT_TIMEOUT = 10
TIMBRADORXPRESS_REQUEST_TIMEOUT = 60
```

Antes de adoptar ese bloque debe decidirse si `Config\Fiscal` será la guarda maestra o será eliminado del camino PAC. No deben coexistir autoridades contradictorias.

## 6. Flujo actual real

```text
/invoices
  → revisión fiscal de la venta
  → simulación/preparación de precios
  → crear preparación fiscal (snapshot, reserva folio)
  → abrir preparación
  → cerrar preparación (congela Fecha)
  → generar Pre-XML
  → opcional: ver/descargar/validar Pre-XML
  → seleccionar CSD y volver a capturar contraseña
  → firmar localmente
  → opcional: ver/descargar/verificar XML firmado
  → timbrar en sandbox
  → intento durable
  → POST timbrar3
  → validar respuesta y XML timbrado
  → persistir timbre/XML
  → opcional: persistir PDF Base64
  → abrir resultado
```

| Paso | Acción del usuario | Acción interna | Estado | Problema |
|---|---|---|---|---|
| Revisar | Elegir emisor, receptor, serie y pagos | Readiness y simulación | Sin documento | Correcto como revisión |
| Preparar precios | Confirmar ajuste si aplica | Congela cálculo previo | Preparación | Puede integrarse al botón final |
| Crear borrador | Pulsar crear | Snapshot y folio | `ready` | Paso técnico innecesario en flujo normal |
| Cerrar | Pulsar cerrar | Congela `issue_date` | `locked` | Paso técnico innecesario |
| Generar Pre-XML | Pulsar generar | XML + XSD + artefacto | `locked` | Paso técnico innecesario |
| Validar Pre-XML | Pulsar validar | Repite XSD | `locked` | Duplicado en flujo normal |
| Firmar | Elegir CSD, capturar contraseña | Cadena, sello, XSD, verificación | `ready_to_stamp` | Contraseña repetida; paso técnico |
| Verificar firma | Pulsar verificar | Repite verificación independiente | `ready_to_stamp` | Útil sólo en diagnóstico |
| Timbrar | Pulsar timbrar | Intento + REST | `stamping` | Debe formar parte del orquestador |
| Resultado | Abrir modal | Consulta intento/timbre/PDF | técnico | Actualmente puede producir 500 |

## 7. Preparación, snapshots y estados

`FiscalDraftCreationService` valida readiness, preparación de precios, venta/pagos, serie y partidas. En una transacción crea:

- `fiscal_documents`;
- snapshot del emisor;
- snapshot del receptor;
- conceptos;
- impuestos por concepto;
- acumulados;
- metadatos;
- auditoría;
- avance de serie.

La creación devuelve `ready`. El cierre cambia a `locked` y reemplaza `issue_date` con la hora fiscal congelada. Una preparación activa distinta puede quedar `superseded`; una abierta puede quedar `cancelled_internal`.

No hay un “unlock” seguro. Eso es correcto para inmutabilidad: las correcciones deben crear una nueva versión, no editar un snapshot cerrado.

Estados observados en la base:

| Estado interno | Filas |
|---|---:|
| `cancelled_internal` | 1 |
| `locked` | 1 |
| `ready` | 1 |
| `stamping` | 2 |
| `stamping_error` | 4 |
| `superseded` | 1 |
| `stamped` | 0 |

Los dos `stamping` sin timbre son una condición de recuperación, no un estado operativo normal.

## 8. Datos fiscales

El snapshot conserva los datos fiscalmente relevantes sin depender de las filas vivas:

- emisor: RFC, razón social, régimen, código postal/lugar de expedición y dirección;
- receptor: RFC, razón social, régimen, código postal, uso CFDI, residencia y registro extranjero;
- conceptos: ClaveProdServ, identificación, cantidad, ClaveUnidad, descripción, valor, descuento y ObjetoImp;
- impuestos por concepto y totales;
- FormaPago, MetodoPago, moneda, tipo de cambio y Exportacion;
- totales y referencia administrativa.

La venta administrativa sigue siendo la fuente de inicio, no la autoridad del CFDI después de crear el snapshot.

## 9. Certificados y contraseña CSD

### Lo que sí se almacena

`fiscal_issuer_certificates` tiene una fila. Conserva:

- metadatos X.509 y vigencia;
- RFC y NoCertificado;
- SHA-256;
- ruta privada del `.cer`;
- ruta privada de la llave **original cifrada por su propia contraseña**;
- versión textual `password-v1`;
- estado/default.

El archivo `.key` cifrado se almacena en una ubicación privada y se verifica por hash. No se persiste una llave PEM descifrada.

### Lo que no se almacena

No existe columna para una contraseña cifrada. En `CsdCertificateService::import()` se valida la contraseña, se abre la llave y se descarta; el mensaje del propio servicio dice que no se almacenará. En `InvoiceReview::sign_xml()` y en la vista se pide `private_key_password` otra vez. `CfdiSigningService::sign()` la usa y ejecuta `unset` al terminar.

Por tanto, el contexto que afirma “contraseña del CSD cifrada” no coincide con el código ni con el esquema actual.

### Papel real de `fiscal.pacEncryptionKey`

`PacSecretVault` usa AES-256-GCM mediante OpenSSL para cifrar respuestas XML de contingencia PAC. Esa clave:

- no cifra la contraseña CSD;
- no abre la llave CSD;
- no cifra la API key del PAC;
- no debe reutilizarse sin una política de separación de claves.

### Diseño requerido

Debe agregarse posteriormente un secreto CSD cifrado por instalación, con:

- ciphertext autenticado;
- nonce/IV y tag;
- versión de llave;
- fecha de validación;
- posibilidad de rotación;
- servicio dedicado distinto del vault PAC;
- descifrado sólo en memoria;
- `try/finally` para reducir vida de variables;
- nunca logs, respuestas, fixtures ni archivos temporales.

El flujo normal debe redirigir a configuración CSD cuando falte el secreto; no debe pedir contraseña dentro de la factura.

## 10. XML, cadena, sello y XSD

| Artefacto | Filas | Estado |
|---|---:|---|
| `pre_xml` | 8 | Implementado, privado y con hash |
| `original_chain` | 6 | Implementado |
| `signed_xml` | 6 | Implementado |
| `stamped_xml` | 0 | Sin éxito persistido |

La firma local:

1. carga el snapshot cerrado;
2. valida semántica;
3. valida integridad del Pre-XML;
4. inserta Certificado y NoCertificado;
5. genera cadena original con XSLT;
6. firma con RSA/SHA-256;
7. verifica con la clave pública;
8. inserta Sello;
9. valida XSD;
10. ejecuta `SignedXmlVerifier`;
11. persiste cadena, XML firmado y firma;
12. cambia a `ready_to_stamp`.

La verificación independiente recalcula desde el XML: formato, ausencia de TFD, XSD, certificado, RFC, vigencia, NoCertificado, cadena y `openssl_verify`.

Hay repetición deliberada de XSD y firma entre generación, firma y botón de verificación. Esa defensa es válida internamente; el botón separado no debe ser obligatorio para el usuario.

## 11. PAC

Adaptador efectivo: `TimbradorXpressRestAdapter`, no el valor `fake` de `Config\Fiscal`.

Operación: `POST https://dev.timbradorxpress.mx/api/rest/servicio/timbrar3`.

Campos:

- `apikey`;
- `xmlCFDI`.

No se envían `.key`, `.cer`, contraseña, `keyPEM` ni `cerPEM`.

El parser exige `code`, `message`, `data`; después `TimbradorXpressStampDataParser` admite XML, UUID, fecha, certificados, cadenas, sellos, QR y PDF. El XML timbrado es obligatorio. `StampedXmlValidator` compara encabezado, sello y contenido canónico removiendo sólo el TFD.

### Idempotencia

La clave combina documento, hash del XML firmado, proveedor, ambiente y `timbrar3`. Antes de red se crea un intento `sending` y el documento pasa a `stamping`.

### Timeout

El adaptador convierte transporte/timeout en respuesta desconocida; el servicio debe dejar `stamp_status_unknown`, impedir reenvío y exigir conciliación.

### Hallazgo actual

El intento 11, documento 10, está en `sending` desde el 24 de julio de 2026 y no tiene respuesta, código HTTP ni UUID. No debe reenviarse automáticamente. El documento 9 también está en `stamping` sin fila de intento actual.

## 12. PDF

El diseño implementado recibe PDF Base64 del PAC, valida Base64 estricto, cabecera PDF, tamaño y SHA-256, y pretende guardarlo en `fiscal_document_binary_artifacts`. Preview y descarga decodifican en memoria y responden `application/pdf`.

Estado real:

- migración `090700` pendiente;
- tabla `ikontrol_fiscal_document_binary_artifacts` inexistente;
- columnas `pac_pdf_artifact_id`, `pdf_status` y `pdf_template` inexistentes en `fiscal_document_stamps`;
- cero PDFs;
- la ruta de resultado consulta la tabla sin comprobar su existencia.

La migración `090700` falló previamente al registrar un índice mediante una firma de `addKey` incompatible. No debe considerarse aplicada ni funcional hasta corregirse en un incremento autorizado.

## 13. Base de datos

Base activa: `ikontrol_new`. Prefijo: `ikontrol_`.

| Tabla lógica | Tabla física | Filas | Uso | Problema |
|---|---|---:|---|---|
| `fiscal_documents` | `ikontrol_fiscal_documents` | 10 | Cabecera/snapshot y estado | 2 `stamping` sin timbre |
| `fiscal_document_artifacts` | `ikontrol_fiscal_document_artifacts` | 20 | Pre-XML, cadena, XML firmado/timbrado | Sin `stamped_xml` |
| `fiscal_stamp_attempts` | `ikontrol_fiscal_stamp_attempts` | 1 | Intento durable | ID 11 en `sending`; faltan físicamente 1–10 |
| `fiscal_document_stamps` | `ikontrol_fiscal_document_stamps` | 0 | UUID/TFD | Sin éxito; faltan columnas PDF pendientes |
| `fiscal_pac_configurations` | `ikontrol_fiscal_pac_configurations` | 0 | Legado de credenciales DB | Obsoleta; no se usa como autoridad |
| `fiscal_document_audit` | `ikontrol_fiscal_document_audit` | 157 | Trazabilidad | Conserva eventos de intentos 1–10 |
| `fiscal_issuer_certificates` | `ikontrol_fiscal_issuer_certificates` | 1 | CSD privado y metadatos | No guarda contraseña |
| `fiscal_document_issuers` | `ikontrol_fiscal_document_issuers` | 10 | Snapshot emisor | Sin huérfanos |
| `fiscal_document_receivers` | `ikontrol_fiscal_document_receivers` | 10 | Snapshot receptor | Sin huérfanos |
| `fiscal_document_items` | `ikontrol_fiscal_document_items` | 10 | Conceptos snapshot | Sin huérfanos detectados |
| `fiscal_document_item_taxes` | `ikontrol_fiscal_document_item_taxes` | 10 | Impuestos por concepto | En uso |
| `fiscal_document_tax_totals` | `ikontrol_fiscal_document_tax_totals` | 10 | Resumen fiscal | En uso |
| `fiscal_document_metadata` | `ikontrol_fiscal_document_metadata` | 10 | Evidencia/cambios | En uso |
| `fiscal_document_signatures` | `ikontrol_fiscal_document_signatures` | 6 | Firma y hashes | En uso |
| `fiscal_document_binary_artifacts` | `ikontrol_fiscal_document_binary_artifacts` | — | PDF Base64 | No existe; migración pendiente |

Índices relevantes observados:

- documento: folio único, invoice/status y snapshot;
- artefacto: idempotencia y activo;
- intento: idempotencia única y documento/status;
- timbre: documento, UUID y artefacto únicos;
- firma: una firma por documento/artefacto y búsqueda por documento.

No se detectaron artefactos, firmas o intentos huérfanos respecto de `fiscal_documents`.

### Por qué “hay intentos vacíos”

No están vacíos en sentido de esquema: actualmente queda una fila `sending`. Los intentos rechazados 1–9 y el intento 10 aparecen en auditoría, pero ya no están en `fiscal_stamp_attempts`; el ID siguiente es 11. No se encontró en el código de aplicación una eliminación normal de intentos. La causa exacta de su desaparición no puede afirmarse con la evidencia disponible; es compatible con limpieza de fixtures/pruebas o manipulación local anterior. Debe auditarse SQL general/binlog si se necesita atribución.

Además, cualquier fallo anterior a `FiscalStampingService::prepare()` (configuración, documento, firma, artefacto o verificación independiente) ocurre antes de insertar el intento. Eso explica por qué algunos clics fallidos pueden no producir fila alguna.

## 14. Rutas y controladores

| Método | Ruta | Controlador | Propósito | Visibilidad futura |
|---|---|---|---|---|
| GET/POST | `fiscal/invoices/review/{invoice}` | `InvoiceReview::show` | Revisión fiscal | Normal |
| POST | `fiscal/invoices/pricing/apply` | `InvoiceReview::apply` | Ajuste administrativo confirmado | Normal sólo si aplica |
| POST | `fiscal/invoices/drafts/create` | `InvoiceReview::create_draft` | Crear snapshot | Ocultar detrás del orquestador |
| GET | `fiscal/invoices/drafts/{document}/view` | `InvoiceReview::draft` | Ver preparación | Normal resumida / avanzada completa |
| POST | `fiscal/invoices/drafts/action` | `InvoiceReview::draft_action` | Cerrar/cancelar | Cierre interno; cancelación normal |
| POST | `fiscal/invoices/prexml/generate` | `InvoiceReview::generate_prexml` | Generar Pre-XML | Avanzada |
| GET | `fiscal/invoices/prexml/view/{artifact}` | `InvoiceReview::view_prexml` | Ver Pre-XML | Avanzada |
| GET | `fiscal/invoices/prexml/download/{artifact}` | `InvoiceReview::download_prexml` | Descargar Pre-XML | Avanzada |
| POST | `fiscal/invoices/prexml/validate` | `InvoiceReview::validate_prexml` | XSD manual | Avanzada |
| POST | `fiscal/invoices/sign` | `InvoiceReview::sign_xml` | Firma local | Ocultar detrás del orquestador |
| GET | `fiscal/invoices/signed/view/{document}` | `InvoiceReview::view_signed_xml` | Ver XML firmado | Avanzada |
| GET | `fiscal/invoices/signed/download/{document}` | `InvoiceReview::download_signed_xml` | Descargar XML firmado | Avanzada |
| POST | `fiscal/stamping/verify-signed` | `Stamping::verifySigned` | Verificación independiente | Avanzada |
| POST | `fiscal/stamping/stamp` | `Stamping::stamp` | Timbrar | Ocultar detrás del orquestador |
| POST | `fiscal/stamping/result/{document}` | `Stamping::result` | Resultado | Normal, pero corregir dependencia PDF |
| POST | `fiscal/stamping/status` | `Stamping::satStatus` | Consultar SAT/PAC | Avanzada |
| POST | `fiscal/stamping/reconcile` | `Stamping::reconcile` | Conciliar intento | Avanzada técnica |
| GET | `fiscal/stamping/xml/view/{document}` | `Stamping::viewXml` | XML timbrado | Normal tras timbre |
| GET | `fiscal/stamping/xml/download/{document}` | `Stamping::downloadXml` | Descargar XML | Normal |
| GET | `fiscal/documents/{document}/pdf/preview` | `Stamping::viewPdf` | Preview PDF | Normal tras PDF |
| GET | `fiscal/documents/{document}/pdf/download` | `Stamping::downloadPdf` | Descargar PDF | Normal |

No se observó una duplicidad exacta de rutas fiscales. Sí hay dos convenciones de ID: las rutas Pre-XML reciben `artifact_id`; las demás reciben `document_id`, y conciliación recibe `attempt_id`. Debe hacerse explícito en nombres y DTOs para evitar errores.

La ruta de resultado usa `document_id`, no `attempt_id`. La ruta de conciliación usa correctamente `attempt_id`.

## 15. Errores reales e historial

| Prueba / periodo | Resultado | Error | Causa comprobada | Situación |
|---|---|---|---|---|
| Firma inicial | Rechazos locales | XSL/cadena/firma | Dependencias/transformación en desarrollo | Código actual ya incluye generador y verificador |
| Render PAC | Advertencias repetidas | Config PAC no disponible | Configuración inválida o incompleta en ese momento | Config actual carga sandbox |
| Prueba de rutas | Error fatal | Ruta nombrada `fiscal_invoice_draft_view` no encontrada | Registro/nombre no cargado en esa ejecución | Ruta nombrada existe hoy |
| Resultado de timbrado | 500 | Tabla binary artifacts inexistente | `Stamping::result()` consulta migración pendiente | Sigue roto |
| Migración PDF | Falló | Uso incompatible de `Forge::addKey` | Firma de API/argumentos en `090700` | Sigue pendiente |
| Documento 2 | Rechazado | `CFDI40139` | Código PAC; el detalle no quedó disponible en fila actual | Historial sólo en auditoría |
| Documentos 5/6 | Rechazados | `CFDI40147` | Código PAC relacionado con datos emisor/receptor; no atribuir texto sin catálogo oficial persistido | Historial |
| Documento 7 | Rechazado | código `401` | Rechazo PAC; coincidió con iteraciones de datos/fecha | Historial |
| Fecha fiscal | Rechazo observado | Fecha seis horas adelantada | Fecha UTC usada en snapshot anterior | Servicio actual congela hora fiscal local |
| Artefacto | Error fatal local | Ruta de artefacto inválida | Incompatibilidad de ruta/validador en prueba | Requiere regresión |
| Intento 10 | Quedó incompleto | Sin final persistido | Excepción previa/posterior no reconciliada | Documento 9 en `stamping` |
| Intento 11 | `sending` | Sin HTTP/código/UUID | Operación no finalizada en DB | Requiere conciliación, no reenvío |
| Clave de contingencia | PAC status no disponible | `pacEncryptionKey` ausente/inválida durante iteraciones | Configuración local incompleta entonces | Hoy se reporta configurada |

### Causa de los 500 observables hoy

La causa reproducida en logs es concreta: `Stamping::result()` línea 21 consulta `fiscal_document_binary_artifacts`, pero la tabla no existe porque `090700` está pendiente. El framework lanza `DatabaseException` y devuelve 500.

También hubo 500 de desarrollo por ruta nombrada ausente, zona horaria vacía y ruta de artefacto inválida. No son un único defecto; son fallos históricos distintos.

## 16. Flujo simplificado propuesto

### Experiencia normal

```text
Venta
  → Revisar datos fiscales
      → Guardar borrador
      → Generar factura
          → Procesando
              ├─ Timbrado → XML y PDF
              ├─ Error corregible → Corregir datos
              └─ Estado desconocido → Conciliar
```

Pantalla de revisión:

- emisor;
- receptor;
- conceptos e impuestos;
- forma/método de pago;
- moneda;
- total;
- CSD activo/configurado;
- PAC sandbox/configurado.

Acciones normales:

- `Generar factura`;
- `Guardar borrador`;
- `Cancelar`.

### Orquestador propuesto

`FiscalInvoiceGenerationService` no debe reemplazar servicios especializados. Debe coordinarlos:

1. adquirir lock lógico de venta/documento;
2. validar permisos, readiness, CSD y PAC;
3. crear/reutilizar preparación de precios;
4. crear versión de snapshot;
5. cerrar y congelar fecha;
6. generar Pre-XML;
7. validar semántica y XSD;
8. obtener y descifrar temporalmente el secreto CSD;
9. firmar;
10. verificar independientemente;
11. crear intento durable;
12. confirmar transacción corta;
13. llamar al PAC fuera de transacción;
14. validar respuesta/XML;
15. persistir XML/UUID/timbre;
16. persistir PDF si es válido;
17. devolver un resultado tipado por etapa.

La operación HTTP debe devolver un identificador de proceso/documento y un resultado estable; no una mezcla de HTML, excepciones y estados técnicos.

## 17. Estados simplificados

| Estado visible | Estados internos posibles | Significado |
|---|---|---|
| Borrador | `draft`, `ready` | Se puede corregir |
| Listo para facturar | `locked`, `xml_generated`, `signed`, `ready_to_stamp` | Validaciones completas o proceso preparado |
| Procesando | `stamping` | Existe intento durable activo |
| Timbrado | `stamped`, `stamped_pdf_pending`, `stamped_pdf_error` | UUID/XML válidos; PDF es secundario |
| Error corregible | `stamping_error`, `response_invalid` | Requiere corregir y nueva versión |
| Estado desconocido | `stamp_status_unknown`, `persistence_error` | No reenviar; conciliar |
| Cancelado internamente | `cancelled_internal`, `superseded` | Fuera del flujo activo |

Los estados `xml_generated`, `signed`, `ready_to_stamp`, `response_invalid` y `persistence_error` pueden conservarse como etapas/eventos internos aunque no todos sean valores persistidos hoy.

## 18. Errores y reintentos

La pantalla debe mostrar:

- etapa;
- código interno/PAC;
- mensaje sanitizado;
- campo afectado cuando sea seguro;
- acción recomendada;
- enlace `Corregir datos`.

Reglas:

| Clase de error | Acción |
|---|---|
| Datos fiscales | Crear nueva versión de snapshot, XML, sello e intento |
| Autenticación PAC | Bloquear; corregir servidor, no reintentar desde UI |
| Timeout/estado desconocido | Conciliar, nunca reenvío automático |
| PDF ausente/inválido con UUID/XML válidos | Mantener timbrado; recuperar sólo PDF si existe operación documentada |
| Persistencia después de respuesta PAC | Recuperar contingencia; no volver a timbrar |
| Duplicado PAC | Conciliar por UUID/estado; no crear otro documento |

El sistema debe distinguir “fallo antes de enviar”, “rechazo confirmado”, “respuesta fiscal exitosa”, y “resultado incierto”.

## 19. Herramientas fiscales avanzadas

Sólo para administrador técnico:

- ver snapshot y diferencias con venta;
- ver/descargar Pre-XML;
- ejecutar/ver XSD;
- ver cadena original resumida/hash;
- verificar firma;
- ver/descargar XML firmado;
- ver intento y línea de tiempo;
- ver código/respuesta sanitizada PAC;
- conciliar estado desconocido;
- recuperar contingencia;
- ver/descargar XML timbrado;
- ver estado/integridad del PDF;
- historial/auditoría.

No deben incluir credenciales, contraseña, llave privada, certificado completo, XML completo en logs ni PDF Base64 en HTML.

## 20. Plan de refactorización

### Incremento A — Estabilizar configuración, persistencia e intentos

Objetivo: dejar el sistema coherente antes de ocultar pasos.

Archivos/áreas:

- `Config\Fiscal` y `Config\TimbradorXpress`;
- migración correctiva nueva para binary artifacts/columnas PDF;
- `Stamping::result`;
- `FiscalStampingService`;
- reconciliación de documentos `stamping`;
- vault dedicado de contraseña CSD y esquema nuevo;
- pruebas de migración, estados, integridad, secretos e idempotencia.

No modificar migraciones ya aplicadas. La migración `090700` no está aplicada, pero debe decidirse si corregirla antes de liberar o sustituirla por una nueva compatible, documentando el estado local.

Criterios:

- una sola autoridad de ambiente/adaptador;
- resultado no devuelve 500 sin PDF;
- contraseña CSD cifrada, rotatable y nunca recapturada en factura;
- intento durable nunca queda indefinidamente `sending` sin mecanismo de conciliación;
- PDF schema aplicado;
- producción bloqueada;
- cero secretos en DB/logs salvo ciphertext CSD autenticado.

### Incremento B — Orquestador único e interfaz normal

Objetivo: `Revisar → Generar factura`.

Archivos/áreas:

- nuevo `FiscalInvoiceGenerationService`;
- DTOs/resultados tipados;
- controlador endpoint único;
- vista de revisión;
- componente de progreso;
- reutilización de Draft, XML, firma y stamping existentes;
- pruebas HTTP/E2E con fake PAC y transacciones aisladas.

Criterios:

- una acción ejecuta todas las etapas;
- ninguna contraseña en el formulario;
- no hay doble timbrado por doble clic;
- cada etapa deja evidencia;
- error antes de red no crea intento “enviado”;
- timeout no se reenvía;
- herramientas técnicas quedan fuera del flujo normal.

### Incremento C — Errores, recuperación y herramientas avanzadas

Objetivo: operación mantenible ante fallos.

Archivos/áreas:

- catálogo de errores y presentador;
- pantalla avanzada;
- conciliación;
- recuperación de contingencia;
- recuperación exclusiva de PDF si la API lo soporta;
- auditoría y permisos;
- pruebas de rechazo, timeout, persistencia, duplicado y permisos.

Criterios:

- cada error indica etapa y acción;
- `Corregir datos` crea nueva versión sin editar XML firmado;
- estados desconocidos sólo se concilian;
- PDF no provoca retimbrado;
- historial completo e inmutable;
- acceso técnico restringido.

## 21. Riesgos

1. Un intento `sending` y dos documentos `stamping` pueden representar operación externa incierta.
2. Cero timbres locales impide afirmar un ciclo real exitoso completo.
3. La contraseña CSD no está automatizada ni cifrada en DB.
4. Código PDF consulta esquema todavía inexistente.
5. La configuración duplicada puede permitir decisiones distintas según servicio.
6. Historial de intentos 1–10 fue eliminado de la tabla aunque permanece parcialmente en auditoría.
7. Mensajes de excepción son devueltos por varios endpoints AJAX y requieren clasificación/sanitización uniforme.
8. Varios controladores compactados en una línea dificultan trazabilidad, revisión y mantenimiento.
9. El estado técnico está distribuido entre documento, firma, intento, timbre, artefacto y PDF sin una proyección única para UI.
10. No debe aplicarse automáticamente ninguna migración ni conciliación sobre esta base sin respaldo y revisión de los documentos 9 y 10.

## 22. Próximo paso recomendado

Ejecutar sólo el Incremento A. Antes de cualquier llamada PAC:

1. respaldar base y almacenamiento privado;
2. preservar intento 11 y documentos 9/10;
3. resolver la migración PDF pendiente;
4. hacer que la vista de resultado tolere ausencia de PDF;
5. definir una única autoridad de configuración;
6. diseñar el vault CSD separado;
7. implementar una consulta/conciliación sin reenvío;
8. probar todo con adaptador falso y base aislada.

No conviene crear todavía el botón único: hacerlo sobre estados y persistencia inconsistentes sólo ocultaría los fallos actuales.

### Seguimiento

La implementación de estabilización derivada de esta auditoría se documenta en
`docs/INCREMENTO_A1_ESTABILIZACION_FISCAL.md`. Los hallazgos históricos de esta
auditoría se conservan sin reinterpretarlos.

## 23. Confirmaciones

- No se ejecutó ninguna llamada al PAC.
- No se modificó ningún XML.
- No se cambió ningún certificado o secreto.
- No se creó factura ni preparación.
- No se cambiaron estados ni datos.
- No se aplicaron ni modificaron migraciones.
- No se modificó `.env`.
- No se hizo commit ni push.
