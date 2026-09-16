# Incremento 08: CSD, cadena original y sellado local

## Alcance

Este incremento prepara el sellado local de un CFDI 4.0 a partir exclusivamente de un
`fiscal_document` bloqueado, su Pre-XML privado vigente y una validación semántica
aprobada. El resultado continúa siendo un artefacto técnico sin timbre, UUID ni validez
fiscal definitiva.

No existe conexión con PAC, timbrado, cancelación SAT, complemento de pago, PDF fiscal
definitivo ni envío por correo. El proceso no modifica ventas, pagos, proyectos, series,
folios ni snapshots.

## Auditoría criptográfica

- PHP probado: 8.2.12.
- OpenSSL de PHP: 3.0.11.
- DOM y libxml disponibles; libxml reportó 2.10.3.
- La extensión XSL está instalada en `C:\xampp\php\ext\php_xsl.dll`, pero no estaba
  habilitada en el CLI auditado. Las pruebas se ejecutan con
  `php -d extension=xsl ...`. En cada instalación debe habilitarse `extension=xsl` en
  el `php.ini` utilizado por Apache y CLI, y reiniciar Apache.
- Firma utilizada: RSA con SHA-256 mediante OpenSSL.
- Se aceptan certificados X.509 DER o PEM y llaves privadas DER o PEM protegidas.
  Cuando es necesario, la conversión se realiza en memoria.
- El código revisado de FactuCare 2 acoplaba persistencia PEM, datos de base y PAC. No
  se copió. Tampoco se copiaron certificados, llaves o contraseñas.

## Reglas de pago

`CfdiPaymentRuleService` centraliza estas reglas:

- `PPD` exige FormaPago `99`.
- `PUE` exige una forma real y rechaza `99`.
- Una venta sin pago o parcialmente pagada propone `PPD/99`.
- Una venta pagada propone `PUE`; la FormaPago sólo se propone cuando el último método
  administrativo tiene un mapeo activo y explícito.

La relación método administrativo → FormaPago SAT se guarda en
`fiscal_payment_method_mappings` y se administra desde el formulario existente de
métodos de pago. No se infieren equivalencias por nombre y no se cambian pagos
históricos.

## Modelo y almacenamiento de CSD

La migración `2026-07-28-080000_CreateFiscalIssuerCertificatesAndPaymentMappings.php`
crea:

- `fiscal_issuer_certificates`;
- `fiscal_payment_method_mappings`.

Los CSD se administran por perfil emisor en **Certificados de sello digital**. Durante
la carga se valida contenido, tamaño, extensión, X.509, contraseña, correspondencia
entre llave privada y llave pública, RFC y vigencia local. El número de certificado se
extrae del propio X.509.

Los originales se almacenan fuera del árbol público en
`writable/fiscal/certificates/{issuer_profile_id}/`, con nombres aleatorios, escritura
atómica, permisos restrictivos y SHA-256. Se conserva la llave original protegida; una
llave descifrada nunca se persiste.

Se adoptó la estrategia A: la contraseña se solicita al firmar. No se guarda en base,
sesión, `.env`, auditoría ni respuesta. La validación de vigencia es exclusivamente
local y no afirma que el CSD esté activo ante el SAT.

## XSLT oficial y cadena original

Los recursos están en `resources/fiscal/sat/cfdi40/xslt/`. El archivo principal es:

`https://www.sat.gob.mx/sitio_internet/cfd/4/cadenaoriginal_4_0/cadenaoriginal_4_0.xslt`

Se conservaron localmente el XSLT principal y sus 33 dependencias directas. Los hashes
SHA-256 se registran en `MANIFEST.sha256`; el hash del principal es:

`b0559b380e73b850ca8a3da53b077a6051a09d87068d8d98e82dfd4acfba7565`

`CfdiOriginalChainGenerator` usa `XSLTProcessor`, resuelve únicamente esos recursos
locales permitidos y deshabilita escritura y red. No concatena atributos manualmente ni
descarga archivos durante un request.

## Flujo de sellado

`CfdiSigningService`:

1. comprueba documento `locked`, Pre-XML activo y validación semántica;
2. valida el CSD seleccionado y su pertenencia al emisor;
3. incorpora `Certificado` y `NoCertificado`;
4. genera la cadena original mediante el XSLT oficial;
5. firma con RSA-SHA256 y codifica `Sello` en Base64;
6. regenera la cadena y verifica la firma con la llave pública;
7. ejecuta la validación XSD completa;
8. rechaza cualquier TimbreFiscalDigital o UUID;
9. almacena privadamente `original_chain` y `signed_xml`;
10. registra firma y auditoría sin secretos.

La migración `2026-07-28-080100_CreateFiscalDocumentSignatures.php` registra el vínculo
inmutable entre documento, Pre-XML, CSD, cadena y XML firmado. Repetir la operación
devuelve el artefacto existente y no crea otra firma.

## Permisos y rutas

Permisos nuevos:

- ver y administrar CSD;
- firmar XML localmente;
- ver XML sellado.

La carga y desactivación de CSD son rutas explícitas y protegidas. El formulario de
firma es `POST`; la contraseña nunca forma parte de una URL. La vista modal del Pre-XML
también usa `POST`, acorde con el mecanismo AJAX de RISE.

## Artefactos privados

La cadena y el XML firmado viven en `writable/fiscal/artifacts/`, fuera de acceso web
directo. El servicio valida tipo, ruta, hash e integridad. `.gitignore` excluye tanto
certificados como artefactos.

## Pruebas e aislamiento

`tests/Increment08/database_integration.php` utiliza una base aislada y directorios
temporales. Genera un certificado RSA sintético y una llave cifrada exclusivamente para
la prueba; no usa datos ni CSD reales. Comprueba:

- reglas PPD/PUE;
- esquema de tablas;
- extracción de RFC, vigencia y número;
- rechazo de contraseña incorrecta;
- cadena oficial determinista;
- firma y verificación RSA-SHA256;
- XSD completo válido;
- atributos `Sello`, `NoCertificado` y `Certificado`;
- ausencia de Timbre y UUID;
- almacenamiento privado e idempotencia;
- ausencia de cambios en venta, estado o folio;
- auditoría sin secretos.

Los temporales se eliminan al terminar, incluso ante una excepción.

## Revisión manual

1. En el método administrativo de pago, configura una FormaPago SAT explícita.
2. Abre un perfil emisor y entra a **Certificados de sello digital**.
3. Carga el `.cer`, el `.key` cifrado y su contraseña; marca predeterminado si aplica.
4. Confirma RFC, número, vigencia local y estado válido.
5. Abre una venta fiscalmente lista y crea/bloquea su borrador.
6. Genera y valida el Pre-XML.
7. En el borrador bloqueado, selecciona el CSD, captura la contraseña y firma.
8. Confirma “Firma local correcta” y “XSD válido”.
9. Abre el XML sellado y confirma `Sello`, `Certificado` y `NoCertificado`.
10. Confirma que no existen `TimbreFiscalDigital` ni `UUID`, y que la venta, pagos,
    proyecto y folio permanecen sin cambios.

## Limitaciones y siguiente fase

- No se consulta revocación o vigencia en línea ante SAT.
- No existe bóveda con llave maestra; la contraseña se solicita por firma.
- No existe PAC, timbrado, UUID ni CFDI definitivo.
- No hay complemento de pago, cancelación, nota de crédito fiscal ni PDF fiscal.
- La activación de XSL debe verificarse en cada servidor.

