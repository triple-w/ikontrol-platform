# Incremento C1.4 — Preparación de prueba PDF real local

## Estado de la preparación

El módulo queda configurado para seleccionar `timbradorxpress-tools` exclusivamente
para PDF. El timbrado real permanece bloqueado con `fiscal.allowRealPac=false` y
`fiscal.pacAdapter=fake`. Durante esta preparación no se invocó `generarPDF`, no se
timbró, no se canceló y no se consumieron timbres.

Antes de modificar la configuración se creó un respaldo verificable de
`ikontrol_new`, `.env`, `writable/fiscal` y `writable/fiscal-private` en
`writable/backups/c1_4_20260728`. El SQL no está vacío y cada archivo tiene un
hash SHA-256 en el inventario de la preparación.

## Configuración efectiva

Variables utilizadas, sin documentar sus valores sensibles:

- `fiscal.enabled`
- `fiscal.allowExternalPdf`
- `fiscal.pdf.provider`
- `fiscal.pdf.allowExternalPdf`
- `fiscal.pdf.defaultTemplateIncome`
- `fiscal.pdf.defaultTemplatePayment`
- `MULTIPAC_TOOLS_ENABLED`
- `MULTIPAC_TOOLS_WSDL`
- `MULTIPAC_TOOLS_USER`
- `MULTIPAC_TOOLS_PASSWORD`
- `MULTIPAC_TOOLS_ALLOWED_HOSTS`
- `MULTIPAC_TOOLS_CONNECT_TIMEOUT`
- `MULTIPAC_TOOLS_REQUEST_TIMEOUT`
- `MULTIPAC_TOOLS_ALLOW_INSECURE_HTTP`
- `fiscal.allowRealPac`
- `fiscal.pacAdapter`

El WSDL HTTPS respondió correctamente y publicó la operación `generarPDF`, por
lo que no fue necesario habilitar HTTP inseguro. No se deshabilitó la validación
TLS. Las credenciales se conservan únicamente en `.env`; no están en código,
pruebas, documentación ni archivos versionados.

## PHP y SOAP

Apache usa PHP 8.2.12, SAPI `apache2handler`, y carga
`C:\xampp\php\php.ini`. La extensión SOAP fue habilitada en ese archivo y el PHP
CLI ya reconoce `SoapClient`.

El proceso Apache que estaba en ejecución todavía no cargó la extensión porque
el servicio Windows no pudo reiniciarse sin privilegios administrativos. Antes
de la prueba manual es obligatorio reiniciar Apache desde el panel XAMPP
ejecutado como administrador y comprobar que `SoapClient` esté disponible. No
se debe pulsar Generar/Regenerar hasta completar esta comprobación.

## Flujo y contrato

Generar y Regenerar usan el mismo endpoint:

`POST /fiscal/documents/{document_id}/pdf/generate`

El controlador invoca únicamente `FiscalPacPdfGenerationService`, que carga el
artefacto `stamped_xml`, valida el XML, localiza `TimbreFiscalDigital`, compara
su UUID con el documento, resuelve la plantilla y selecciona el adaptador
configurado. `TimbradorXpressToolsPdfAdapter` llama:

`generarPDF(usuario, claveAcceso, xmlB64, plantilla, jsonB64, logoB64)`

Sólo se acepta `code=210`, Base64 estricto y un PDF estructuralmente válido. No
se conserva la respuesta SOAP cruda. Un `SoapFault` se normaliza y sanitiza sin
registrar XML, credenciales ni PDF Base64, y no existe reintento automático.

Regenerar conserva el PDF anterior hasta validar y persistir la nueva versión.
Si el proveedor falla, el artefacto anterior continúa activo.

## Documentos candidatos

Se excluyeron los documentos protegidos 9, 10, 12, 13 y 14.

| Documento | Serie/folio | UUID enmascarado | PDF activo | Proveedor actual | Plantilla |
|---|---|---|---:|---|---|
| 21 | FC2-A 14 | C0B6D517-****-****-****-142F | No | — | factura |
| 16 | A 16 | C4AEEB38-****-****-****-4026 | Sí | timbradorxpress | factura |
| 15 | A 15 | 49CAAEEE-****-****-****-C34F | Sí | timbradorxpress | factura |

El documento 21 es el recomendado para Generar. Conserva el XML importado
activo (artefacto 46), XML bien formado, TimbreFiscalDigital y UUID coincidente;
su SHA-256 es
`3bea0d3d8f65d21d95bd42739c27fa2c0b72e47f55a882566ec5a6a0255fa876`.
No tiene PDF fake activo ni intentos PDF.

El documento 16 puede utilizarse para Regenerar después de validar que se desea
crear una nueva versión sobre su PDF actual. La regeneración no elimina el PDF
anterior antes de recibir uno nuevo válido.

## Interfaz y errores

Las filas muestran Generar PDF aun si falta XML; en ese caso el servidor
responde que el documento no tiene XML timbrado. Cuando existe PDF activo se
muestran Ver PDF, Descargar PDF y Regenerar PDF. El modal presenta serie/folio,
UUID abreviado, plantilla, tipo de operación y el proveedor efectivo
`WSTools33 / PAC`.

Los intentos y artefactos distinguen `Prueba local`/`PDF de prueba local` de
`WSTools33 / PAC`/`PDF generado por PAC`.

## Procedimiento manual

1. Reiniciar Apache desde XAMPP Control Panel ejecutado como administrador.
2. Confirmar bajo Apache: `SoapClient` disponible, proveedor
   `timbradorxpress-tools`, servicio habilitado y credenciales configuradas.
3. Entrar a **Facturación → Facturas**.
4. En **FC2-A 14** abrir **Acciones → Generar PDF**.
5. Verificar en el modal: proveedor **WSTools33 / PAC**, plantilla **factura**.
6. Pulsar **Generar PDF** una sola vez y esperar la respuesta. No repetir ante
   timeout o resultado incierto.
7. Con éxito, comprobar Ver PDF y Descargar PDF, y verificar que UUID, XML,
   timbres e intentos de timbrado no cambiaron.

## Deshabilitación al terminar

En `.env`:

```dotenv
MULTIPAC_TOOLS_ENABLED=false
MULTIPAC_TOOLS_ALLOW_INSECURE_HTTP=false
fiscal.pdf.provider=fake
```

Reiniciar Apache para aplicar la configuración. Los PDFs reales ya generados no
deben eliminarse.
