# Incremento 09: TimbradorXpress REST y timbrado controlado

## Estado final

iKontrol prepara y sella localmente el CFDI 4.0. El PAC recibe únicamente el XML
firmado y la API key. No recibe llave privada, certificado, contraseña, JSON fiscal ni
TXT fiscal. El XML timbrado es la autoridad fiscal; el PDF es sólo una representación
impresa.

La configuración técnica se obtiene exclusivamente de `.env`. No existe una pantalla
para elegir proveedor, ambiente, endpoint, timeout, plantilla o credenciales.

Producción permanece bloqueada. No se realizó una llamada PAC real durante este
desarrollo.

## Auditoría de la implementación

Se conservaron:

- `fiscal_stamp_attempts`, sus bloqueos e idempotencia;
- `fiscal_document_stamps`;
- el adaptador REST y los parsers seguros;
- la validación del XML timbrado;
- artefactos privados para XML;
- conciliación y clasificación de errores.

Se retiraron:

- la dependencia de credenciales PAC en base de datos;
- rutas y vistas para editar o mostrar una API key;
- permisos para administrar secretos;
- generación local de PDF y QR.

`fiscal_pac_configurations` queda obsoleta y sin credenciales. La migración correctiva
limpia campos sensibles existentes; el código de ejecución no consulta esa tabla.

## Configuración de servidor

```ini
TIMBRADORXPRESS_ENVIRONMENT=sandbox
TIMBRADORXPRESS_APIKEY_SANDBOX=
TIMBRADORXPRESS_APIKEY_PRODUCTION=
TIMBRADORXPRESS_BASE_URL_SANDBOX=https://dev.timbradorxpress.mx/api/rest/servicio/
TIMBRADORXPRESS_BASE_URL_PRODUCTION=https://app.timbradorxpress.mx/api/rest/servicio/
TIMBRADORXPRESS_CONNECT_TIMEOUT=10
TIMBRADORXPRESS_REQUEST_TIMEOUT=60
TIMBRADORXPRESS_PRODUCTION_ENABLED=false
fiscal.pacEncryptionKey=
```

Los hosts están en una lista permitida. Incluso con credencial productiva, el servicio
y el adaptador rechazan producción. La interfaz sólo informa proveedor, ambiente,
plantilla y estado configurado/sin configurar; nunca muestra la API key.

### Llaves con responsabilidades diferentes

| Variable | Responsabilidad | Se transmite al PAC |
|---|---|---:|
| `TIMBRADORXPRESS_APIKEY_SANDBOX` | Autenticación contra sandbox | Sí, como `apikey` |
| `TIMBRADORXPRESS_APIKEY_PRODUCTION` | Autenticación productiva, actualmente bloqueada | Sólo en producción habilitada |
| `fiscal.pacEncryptionKey` | Cifrado local de evidencia persistente de contingencia | No |

`Config\Fiscal` es la única autoridad que lee `fiscal.pacEncryptionKey`.
`PacSecretVault` deriva de ella la llave del cifrador usado por
`PacContingencyStorageService`. Actualmente cifra el XML timbrado conservado
temporalmente para conciliación cuando la persistencia principal puede fallar. No cifra
la API key PAC, no abre el CSD y no sustituye la contraseña de la llave privada.

La llave local debe tener al menos 32 caracteres. En instalaciones reales se recomienda
`bin2hex(random_bytes(32))`, que produce 64 caracteres hexadecimales. Debe respaldarse
fuera del repositorio: perderla impide descifrar contingencias existentes. Nunca debe
rotarse automáticamente una vez que existan datos cifrados.

`Config\TimbradorXpress` continúa siendo la única autoridad para ambiente, API key,
URLs y timeouts. El flujo nuevo no consulta `fiscal_pac_configurations`;
`pac_configuration_id` permanece nullable únicamente para historial.

## Operación PAC y evidencia

La operación implementada es:

```text
POST https://dev.timbradorxpress.mx/api/rest/servicio/timbrar3
Content-Type: application/x-www-form-urlencoded

apikey={secreto de .env}
xmlCFDI={XML CFDI 4.0 ya firmado}
```

| Operación | Recibe XML firmado | Recibe llave/CER | Devuelve XML | PDF Base64 | Plantilla |
|---|---:|---:|---:|---:|---:|
| `timbrar3` | Sí | No | Sí | No demostrado públicamente | No documentada |
| `TimbrarJSON` | No, recibe JSON | Sí | Sí | Sí | 1–8 |
| Generador PDF anunciado | XML mencionado | No comprobado | No aplica | Servicio anunciado | Contrato no publicado |

La documentación pública revisada sólo demuestra PDF Base64 para
`TimbrarJSON(credential, json, key, cer, plantilla)`. Esa variante contradice las reglas
del proyecto porque usa JSON, llave y certificado. No se utiliza.

Se aplica el Caso C: no se inventa un endpoint ni parámetros de PDF. Si la respuesta
real de `timbrar3` incluye un campo opcional `PDF`, iKontrol lo valida y persiste. Si no
lo incluye, el timbrado puede quedar válido con PDF pendiente, sin reenviar el CFDI.

Fuentes:

- <https://timbradorxpress.mx/>
- <https://www.timbradorxpress.mx/xml.html>
- <https://timbradorxpress.mx/pdf/brochure_solucionjson.pdf>

## Verificación independiente del XML sellado

`SignedXmlVerifier` relee el `signed_xml` inmutable y recalcula:

1. XML bien formado y CFDI 4.0;
2. ausencia de `TimbreFiscalDigital`;
3. certificado X.509 incrustado y legible;
4. RFC, vigencia y NoCertificado;
5. cadena original mediante la XSLT SAT local;
6. sello RSA-SHA256 mediante `openssl_verify`;
7. XSD CFDI 4.0;
8. SHA-256 del XML.

El firmador lo ejecuta antes de `ready_to_stamp`. El servicio de timbrado lo repite
antes de crear el intento durable. `POST fiscal/stamping/verify-signed` devuelve sólo
indicadores y errores sanitizados.

## Intento durable, idempotencia y timeout

Una transacción corta bloquea `fiscal_documents`, confirma `ready_to_stamp`, ausencia
de UUID, timbre e intento activo, crea `fiscal_stamp_attempts` y cambia a `stamping`.
La llamada HTTP ocurre fuera de la transacción.

La clave idempotente usa documento, hash del XML, proveedor, ambiente y operación.
Un documento timbrado, en envío o con estado desconocido no puede reenviarse.

Un timeout o transporte ambiguo produce `stamp_status_unknown` y requiere
conciliación. Nunca se reintenta automáticamente.

## Validación y persistencia del XML timbrado

`StampedXmlValidator` exige:

- `cfdi:Comprobante` y un `TimbreFiscalDigital` 1.1 válido;
- UUID, FechaTimbrado, RfcProvCertif, NoCertificadoSAT, SelloCFD y SelloSAT;
- coincidencia de emisor, receptor, serie, folio, totales, moneda, conceptos,
  impuestos y sello;
- igualdad canónica con el XML enviado después de retirar únicamente el timbre.

Una modificación fiscal genera `response_invalid`. El XML válido se guarda como
artefacto privado `stamped_xml`, con hash, tamaño, UUID, proveedor, ambiente e intento.
No se reconstruye ni sobrescribe.

## PDF Base64 del PAC

La migración `090700_CreateFiscalBinaryArtifacts` crea
`fiscal_document_binary_artifacts` con `LONGTEXT` para el Base64 limpio. No se almacena
una data URI ni se escribe un PDF permanente.

`PacPdfValidator`:

- decodifica Base64 en modo estricto;
- limita tamaño codificado y binario;
- exige `%PDF-` y terminación `%%EOF`;
- rechaza HTML, JSON, vacío y contenido excesivo;
- calcula tamaño y SHA-256 sobre los bytes.

Los endpoints protegidos:

```text
GET fiscal/documents/{id}/pdf/preview
GET fiscal/documents/{id}/pdf/download
```

decodifican en memoria, vuelven a comprobar hash y tamaño, y responden
`application/pdf` con `inline` o `attachment`. No exponen Base64 en HTML y no crean
archivos temporales permanentes.

El estado del PDF se guarda separado del timbrado:

- `valid`: PDF PAC validado;
- `pending`: XML timbrado válido, PDF no recibido;
- `error`: XML timbrado válido, PDF inválido.

Una falla del PDF no revierte el timbre ni permite reenviar el CFDI.

## Permisos

- `fiscal_pac_status_view`;
- `fiscal_stamp_sandbox`;
- `fiscal_stamp_status`;
- `fiscal_stamp_reconcile`;
- `fiscal_stamp_error_details`;
- `fiscal_stamped_xml_view`;
- `fiscal_stamped_xml_download`;
- `fiscal_pdf_view`;
- `fiscal_pdf_download`.

No existen permisos para editar credenciales PAC. `090800` migra concesiones anteriores
de PDF a los nombres definitivos.

## Migraciones

1. `090000_CreateFiscalPacConfigurations`;
2. `090100_CreateFiscalStampAttempts`;
3. `090200_CreateFiscalDocumentStamps`;
4. `090300_PrepareFiscalStampingStates`;
5. `090400_DeprecateDatabasePacCredentials`;
6. `090500_ExtendTimbradorXpressStampMetadata`;
7. `090600_AddPacErrorGuidance`;
8. `090700_CreateFiscalBinaryArtifacts`;
9. `090800_MigrateFiscalPdfPermissions`.

No modifican ventas, pagos, partidas, impuestos ni snapshots. No almacenan API keys ni
usan `FLOAT`/`DOUBLE` para datos fiscales nuevos.

## Pruebas

Las pruebas usan una base clonada aislada, filesystem temporal, cliente HTTP falso y
fixtures sin secretos. Cubren:

- contrato `timbrar3` con exactamente `apikey` y `xmlCFDI`;
- TLS, timeouts, host permitido y producción bloqueada;
- verificación independiente, firma alterada, XSD y TFD previo;
- parser JSON, HTML de proxy, respuesta vacía y XML modificado;
- PDF válido, Base64 inválido, no-PDF, data URI, HTML y tamaño excesivo;
- persistencia Base64 en base e integridad en lectura;
- cero archivos PDF permanentes;
- rechazo, timeout, idempotencia y cero proyectos.

Resultado final: 24 scripts de prueba ejecutables pasaron sin fallos. Los archivos
`fixture_factory.php` y `update_golden.php` son herramientas, no pruebas; la segunda se
niega correctamente a sobrescribir golden files sin `--confirm`.

## Llamada sandbox real

La configuración local comprobada está en sandbox, tiene una clave de 32 caracteres,
usa el host permitido y mantiene producción deshabilitada. No se muestra el secreto.

No se ejecutó la llamada real porque no está demostrado un contrato seguro para obtener
PDF Base64 desde XML firmado y tampoco se confirmó un documento simple con CSD/RFC
inequívocamente de pruebas que supere todas las precondiciones. Resultado:

- llamadas sandbox: 0;
- llamadas producción: 0;
- `keyPEM` enviado: no;
- `cerPEM` enviado: no;
- contraseña enviada: no.

## Revisión manual

1. Ejecutar `php spark migrate`.
2. Confirmar `.env` con sandbox y producción deshabilitada.
3. Abrir `/fiscal/pac/status`; comprobar TimbradorXpress, Pruebas, Principal y estado.
4. Abrir un documento firmado con CSD y RFC inequívocamente de pruebas.
5. Pulsar **Verificar XML sellado**.
6. Confirmar XML, XSD, certificado, RFC, vigencia, NoCertificado, cadena y sello.
7. Confirmar estado `ready_to_stamp`.
8. Revisar el resumen: operación `timbrar3`, emisor, receptor, serie, folio, total,
   NoCertificado, hash parcial y tres confirmaciones de envío de llaves en **No**.
9. Pulsar **Timbrar CFDI de prueba** una sola vez.
10. Confirmar código PAC, UUID y `TimbreFiscalDigital`.
11. Ver y descargar el XML timbrado.
12. Si el PAC entrega PDF, abrir preview y descargar; comprobar que es un PDF válido.
13. Confirmar que no existe PDF físico bajo `public` o `writable`.
14. Intentar timbrar otra vez y confirmar que se bloquea antes de HTTP.
15. Revisar logs y confirmar ausencia de API key, XML completo y PDF Base64.

## Limitaciones

- La recuperación independiente del PDF queda pendiente de un contrato oficial seguro.
- No existe recuperación PAC demostrada por hash, serie o folio para un timeout.
- No se implementan producción, cancelación, complemento de pago ni timbrado masivo.
- No se afirma timbrado real ni PDF real hasta ejecutar y aprobar la prueba sandbox.

## Corrección de Comprobante.Fecha

La causa del rechazo por fecha fue `FiscalDraftCreationService`: utilizaba
`get_current_utc_time()` como `fiscal_documents.issue_date`, y `CfdiXmlBuilder` copiaba
esa cadena directamente a `Comprobante.Fecha`.

`FiscalIssueDateService` define explícitamente `America/Mexico_City`, sin depender de
la zona del proceso PHP ni aplicar offsets manuales. La fecha se congela nuevamente al
cerrar la preparación y se guarda como `Y-m-d H:i:s`; el XML la representa como
`Y-m-d\TH:i:s`, sin sufijo de zona.

Los timestamps técnicos (`created_at`, auditoría y vigencias) conservan su semántica
UTC. La corrección sólo afecta la fecha fiscal de emisión.

No se modifica un XML firmado ni se elimina un intento anterior. Una preparación nueva
produce un nuevo Pre-XML, cadena original y sello. La regresión automatizada verifica:

- conversión desde un instante UTC a Ciudad de México;
- independencia de la zona configurada en el servidor;
- formato y cercanía de cinco minutos;
- cambio de XML, cadena y sello al cambiar Fecha;
- inmutabilidad del XML anterior;
- cero llamadas PAC.

En la base local se creó el documento fiscal 9, serie `A`, folio `9`, con fecha fiscal
`2026-07-23 23:36:06`, y el Pre-XML privado 15. La preparación 8 quedó como
`superseded`; no fue borrada ni editada. El documento 9 permanece `locked` porque la
contraseña del CSD no se almacena y debe introducirse manualmente para firmarlo.
