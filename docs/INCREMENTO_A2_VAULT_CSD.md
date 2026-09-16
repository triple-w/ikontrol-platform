# Incremento A2 — Vault seguro de contraseña CSD

Fecha: 24 de julio de 2026.

## 1. Objetivo

Proteger la contraseña de la llave privada del CSD con cifrado autenticado y
permitir que el sellado local la utilice sin solicitarla en cada factura.

A2 no modifica el archivo `.key` original, no persiste una llave descifrada y
no realiza llamadas al PAC.

## 2. Situación inicial

- El CSD se importaba validando certificado, llave y contraseña.
- La contraseña se descartaba al finalizar la importación.
- `InvoiceReview::sign_xml()` recibía `private_key_password`.
- La vista de la factura contenía un campo de contraseña requerido.
- `CfdiSigningService::sign()` exigía la contraseña como argumento.
- Existía un certificado local sin contraseña persistida.
- `fiscal.pacEncryptionKey` protegía contingencia PAC y no podía reutilizarse.

Mapa anterior:

| Etapa | Archivo | Método | Uso anterior |
|---|---|---|---|
| Captura al importar | `Views/fiscal/certificates/modal_form.php` | formulario | Recibía contraseña |
| Validación | `CsdCertificateService.php` | `import()` | Abría `.key` y validaba pareja |
| Descarte | `CsdCertificateService.php` | `finally` | Eliminaba referencias locales |
| Nueva captura | `Views/fiscal/invoices/draft.php` | formulario de firma | Solicitaba contraseña otra vez |
| Controlador | `InvoiceReview.php` | `sign_xml()` | Leía `private_key_password` |
| Firma | `CfdiSigningService.php` | `sign()` | Recibía contraseña explícita |

## 3. Respaldo

Respaldo externo A2 verificado:

`C:\Users\iKontrol\Backups\ikontrol-A2-20260724-175948`

Incluye:

- dump no vacío de `ikontrol_new`;
- copia del `.env` posterior a A1.1;
- 25 archivos del almacenamiento `writable/fiscal`;
- hashes SHA-256 verificados por el usuario.

## 4. Arquitectura

```text
Configuración administrativa CSD
  → CsdCertificateService valida certificado/llave
  → CsdCertificateSecretService valida contraseña
  → CsdSecretVault cifra AES-256-GCM
  → fiscal_issuer_certificate_secrets

Firma de factura
  → InvoiceReview::sign_xml (sin contraseña)
  → CfdiSigningService
  → CsdCertificateSecretService
  → CsdSecretVault descifra en memoria
  → abre llave privada original
  → firma y verifica
  → elimina referencias locales en finally
```

## 5. Algoritmo y payload

- algoritmo: `aes-256-gcm`;
- llave: 32 bytes representados por 64 caracteres hexadecimales;
- nonce: 12 bytes aleatorios por cifrado;
- tag: 16 bytes;
- ciphertext, nonce y tag: Base64 estricto;
- versión lógica: `csd-secret-v1`;
- versión del payload: `1`.

Formato:

```json
{
  "version": 1,
  "algorithm": "aes-256-gcm",
  "nonce": "base64",
  "tag": "base64",
  "ciphertext": "base64"
}
```

No se utiliza serialización PHP ni cifrado sin autenticación.

## 6. Llave maestra

Variable local:

```dotenv
fiscal.csdEncryptionKey =
```

La instalación principal tiene una llave generada con 32 bytes aleatorios:

- configurada: sí;
- longitud: 64;
- formato hexadecimal: válido;
- distinta de `fiscal.pacEncryptionKey`: sí.

El valor no se documentó, imprimió, registró ni agregó a Git.

`Config\Fiscal` es la única clase que lee la variable y bloquea:

- llave ausente;
- formato o longitud incorrectos;
- reutilización de `fiscal.pacEncryptionKey`.

## 7. Esquema

Migración:

`2026-07-30-100000_CreateCsdCertificateSecrets.php`

Aplicada en `ikontrol_new`, lote 15.

Tablas:

- `ikontrol_fiscal_issuer_certificate_secrets`;
- `ikontrol_fiscal_issuer_certificate_secret_audit`.

La tabla de secretos contiene una fila por certificado/tipo, payload `LONGTEXT`,
versión, estado, validación y fechas de rotación. Una restricción única impide
dos contraseñas activas del mismo tipo para el mismo certificado.

La relación con el certificado es lógica e indexada. No se agregó una foreign
key física para mantener compatibilidad con el esquema fiscal existente y sus
tipos heredados.

La migración no insertó secretos. El certificado existente quedó inicialmente
como `password_pending`.

## 8. Importación y actualización

La importación ahora:

1. valida archivos y tamaños;
2. abre la llave con la contraseña;
3. confirma pareja certificado/llave;
4. valida RFC y vigencia;
5. guarda `.cer` y `.key` original protegido;
6. cifra la contraseña;
7. persiste certificado y secreto en la misma transacción;
8. elimina archivos si la transacción falla;
9. descarta referencias sensibles en `finally`.

Para certificados existentes se agregó:

- `Configurar contraseña del CSD`;
- `Actualizar contraseña del CSD`.

No se vuelve a solicitar `.cer` o `.key`. La contraseña se valida contra el
archivo privado ya almacenado y protegido por hash.

Una contraseña incorrecta no crea ni reemplaza el secreto activo.

## 9. Firma automática

`CfdiSigningService::sign()` recibe ahora:

```text
documentId
preXmlArtifactId
certificateId
userId
authorized
```

Ya no recibe contraseña.

El servicio:

- carga el secreto activo;
- autentica y descifra el payload;
- verifica integridad del `.key`;
- abre la llave;
- firma y verifica;
- no escribe PEM descifrado;
- limpia referencias sensibles.

El formulario de la factura ya no contiene `private_key_password`.

## 10. Estado operativo

`CsdOperationalStatusService` proyecta:

- Configurado y listo.
- Contraseña pendiente de configuración.
- Certificado vencido.
- Archivos privados no disponibles.
- Contraseña inválida.
- Configuración de cifrado ausente.
- Requiere reconfiguración.

El certificado existente de la instalación principal permanece en
`Contraseña pendiente de configuración` hasta que un administrador capture la
contraseña correcta localmente. No se inventó ni migró una contraseña.

## 11. Errores controlados

Se implementaron códigos seguros:

- `CSD_ENCRYPTION_KEY_MISSING`;
- `CSD_ENCRYPTION_KEY_INVALID`;
- `CSD_ENCRYPTION_KEYS_REUSED`;
- `CSD_SECRET_NOT_CONFIGURED`;
- `CSD_SECRET_DECRYPT_FAILED`;
- `CSD_SECRET_CORRUPTED`;
- `CSD_PRIVATE_KEY_PASSWORD_INVALID`;
- `CSD_PRIVATE_KEY_FILE_MISSING`;
- `CSD_CERTIFICATE_NOT_READY`.

La respuesta de firma no contiene detalles OpenSSL, rutas, payload cifrado,
llave maestra o stack trace.

## 12. Permisos, CSRF y auditoría

- `fiscal_certificates_manage`: configura, actualiza o reemplaza secretos.
- `fiscal_certificates_view`: consulta el estado del certificado.
- `fiscal_xml_sign`: utiliza un CSD previamente listo, sin administrar secretos.
- administradores globales conservan el comportamiento existente.
- el endpoint de mutación es POST y tiene filtro CSRF explícito.
- no existe infraestructura de rate limiting por acción en la entrega actual;
  autenticación, permiso y CSRF son las guardas disponibles.

Eventos sin secretos:

- `csd_secret_configured`;
- `csd_secret_updated`;
- `csd_secret_validation_failed`;
- `csd_secret_decryption_failed`;
- `csd_automatic_signing_used`.

La auditoría conserva ID de certificado, usuario, resultado, código interno y
fecha. No conserva contraseña, ciphertext, nonce, tag, llave o contenido `.key`.

## 13. Pruebas

Resultados aislados:

- criptografía A2: 14/14;
- migración A2: 13/13;
- seguridad/rutas A2: 9/9;
- Incremento 8 estático: 38/38;
- importación, secreto y firma: 26/26;
- configuración/factory A1: 8/8;
- migración A1: 16/16;
- proyección/HTTP A1: 10/10;
- Incremento 9 estático: 25/25;
- integración Fake PAC: 41/41.

Total: 200 aprobadas, 0 fallidas.

Se comprobó:

- round trip AES-GCM;
- nonce aleatorio;
- manipulación de tag, nonce y ciphertext;
- llave equivocada;
- payload incompleto o versión desconocida;
- llave ausente, corta, no hexadecimal o reutilizada;
- importación correcta;
- contraseña incorrecta;
- rotación;
- secreto corrupto;
- readiness;
- firma sin contraseña HTTP;
- ausencia de plaintext en respuesta y base aislada;
- ausencia de PEM descifrado persistente;
- A1/Fake PAC sin red.

## 14. Rotación futura de llave maestra

A2 versiona el formato, pero no implementa interfaz de rotación. El proceso
futuro deberá:

1. conservar temporalmente la llave anterior;
2. autenticar y descifrar cada secreto;
3. cifrar con una nueva llave;
4. validar apertura de cada `.key`;
5. actualizar versión;
6. retirar la llave anterior únicamente al terminar.

Nunca debe rotarse automáticamente al arrancar.

## 15. Estado histórico

Después de migrar:

- documento 9: `stamping`, hash estructural intacto;
- documento 10: `stamping`, hash estructural intacto;
- intento 11: `sending`, hash estructural intacto;
- certificados: 1, hash conjunto intacto;
- firmas: 7, hash conjunto intacto;
- timbres: 0.

No se modificó XML, firma, certificado, llave privada o artefacto existente.

## 16. Riesgos y trabajo manual pendiente

- El certificado principal aún necesita que un administrador configure su
  contraseña desde la interfaz local.
- Perder `fiscal.csdEncryptionKey` hace irrecuperables los secretos; debe formar
  parte de cada respaldo.
- La rotación de llave maestra se diseñó, pero no se automatizó.
- Los documentos 9/10 y el intento 11 siguen requiriendo conciliación futura.

## 17. Integración con Incremento B

El orquestador futuro podrá llamar `CfdiSigningService::sign()` sin mostrar o
transportar la contraseña. Debe reutilizar:

- `CsdOperationalStatusService`;
- `CsdCertificateSecretService`;
- `CsdSecretVault`;
- errores tipados;
- auditoría existente.

No debe leer `.env`, descifrar secretos ni abrir llaves directamente.

## 18. Confirmaciones

- Cero llamadas externas.
- Cero llamadas a TimbradorXpress.
- Fake PAC permanece activo.
- `allowRealPac=false`.
- Producción permanece deshabilitada.
- Sin commit.
- Sin push.

## Corrección operativa A2.1 — CSRF del modal de contraseña

La mutación del secreto utiliza exclusivamente:

```text
POST fiscal/certificates/secret/configure
```

La ruta conserva el filtro CSRF explícito. El error 403 observado antes de
llegar al controlador se debía a que el modal no renderizaba `csrf_field()` y
no inicializaba un envío AJAX que serializara el formulario. El mecanismo
global de RISE sólo agrega datos CSRF cuando su ajuste administrativo
`csrf_protection` está activo, por lo que no suministraba el token requerido
por este filtro de ruta.

La corrección:

- genera el campo CSRF dentro del formulario cada vez que se carga el modal;
- envía el formulario real mediante `FormData`;
- devuelve nombre y hash actuales en respuestas JSON controladas;
- actualiza el campo oculto sin registrar el token;
- limpia inmediatamente la contraseña en éxito, rechazo funcional o error;
- ante HTTP 403 cierra el modal y exige abrirlo de nuevo, sin reenvío
  automático de la contraseña;
- mantiene POST, filtro CSRF, permisos y cifrado sin cambios.

Configuración caracterizada:

- protección: `cookie`;
- token: `rise_csrf_token`;
- encabezado: `X-CSRF-TOKEN`;
- cookie: `rise_csrf_cookie`;
- regeneración: deshabilitada;
- cookie `SameSite=Lax`, `HttpOnly=true`, path `/`, sin dominio fijo y no
  `secure` en la instalación HTTP local.

Pruebas directas confirmaron que POST sin token o con token inválido devuelve
403 y que GET no está registrado. Las pruebas aisladas confirman que una
contraseña válida persiste únicamente el payload autenticado y que una
contraseña inválida no reemplaza el secreto.

## Cierre operativo A2.1

El administrador configuró la contraseña directamente en la interfaz local.
La instalación principal confirmó:

- certificado 1 en estado operativo `ready`;
- secreto `private_key_password` activo con versión `csd-secret-v1`;
- payload cifrado presente, sin inspeccionarlo ni imprimirlo;
- auditorías `csd_secret_configured` y `csd_secret_updated` exitosas;
- mensaje visible `Configurado y listo`.

La prueba manual se realizó con el documento fiscal 12, distinto de los
documentos históricos 9 y 10. El firmado:

- no solicitó nuevamente contraseña;
- recuperó el secreto mediante el vault;
- generó y persistió la firma 8;
- registró `csd_automatic_signing_used`;
- dejó el documento en `ready_to_stamp`;
- no creó intento PAC ni timbre.

La verificación independiente posterior confirmó XML bien formado, XSD CFDI
4.0 válido, certificado vigente, RFC y NoCertificado congruentes, cadena
original reproducible, firma RSA-SHA256 válida y hash del artefacto íntegro.
No se encontraron PEM descifrados, temporales sospechosos ni patrones sensibles
en logs o sesiones. Los documentos 9/10 y el intento 11 permanecieron intactos.
# Continuidad

El flujo normal que consume el vault sin volver a solicitar la contraseña se documenta en
`docs/INCREMENTO_B_ORQUESTADOR_FACTURA.md`.
