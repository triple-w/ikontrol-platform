# Incremento C1.2 — Prueba controlada de PDF WSTools33

Fecha: 2026-07-27  
Resultado: **bloqueada antes del transporte; no se realizó llamada externa**.

## 1. Respaldo

Ruta:

`C:\xampp\htdocs\ikontrol2\ikon2.0\writable\backups\C1_2_20260727_110651`

El respaldo contiene:

- `ikontrol_new.sql` no vacío: 9,040,895 bytes;
- copia de `.env`;
- copia de `writable/fiscal`, con 48 archivos;
- `SHA256SUMS.txt`, con 51 entradas SHA-256.

## 2. Documento fuente FC2

- Base: `tws001_factucare`.
- ID interno: 116610.
- Serie/folio: A-14.
- Tipo: I (Ingreso).
- Estado: TIMBRADA.
- UUID: `C0B6D517-****-****-****-********142F`.
- SHA-256 XML: `3bea0d3d8f65d21d95bd42739c27fa2c0b72e47f55a882566ec5a6a0255fa876`.
- Huella del registro fuente: `6e1caf575740e340c76c5bdc8ca1fae59a9c9c48205d3c7a6f3445f8a0dfac28`.

El XML se comprobó bien formado, tipo I, con un
`TimbreFiscalDigital` y UUID coincidente. No se alteró el registro fuente.

## 3. Fixture iKontrol

- Etiqueta: `C1.2 PDF WSTools33 Test`.
- `document_id`: 21.
- Serie informativa local: `FC2-A`.
- Folio informativo: 14.
- Estado: `stamped_pdf_pending`.
- Proveedor del timbre importado: `imported_test_fixture`.
- XML almacenado como artefacto privado 46.
- UUID y hash XML idénticos al documento fuente.

La metadata lo marca como fixture importado, no contabilizable, no cancelable,
no reenviable al timbrado y fuera del flujo normal de ventas. No se copiaron
pagos, ventas, saldos, folios operativos ni intentos de timbrado.

La serie local `FC2-A` evita colisionar con el índice único de la serie A ya
ocupada por documentos locales. La serie original A permanece en el XML y en
la metadata informativa.

## 4. Plantilla, proveedor y metadata

- Proveedor PDF: `timbradorxpress-tools`.
- Tipo: I.
- Plantilla activa: `factura`.
- Resolución comprobada con `FiscalPdfTemplateResolver`: `factura`.
- Metadata preparada desde el XML: tipo, nombre de tipo, receptor, comentarios,
  serie y folio.
- No se recalcularon impuestos ni totales.
- Logo: ausencia representada por cadena vacía, como admite el flujo FC2.

## 5. Credenciales y controles

Las credenciales vigentes de WSTools33 se copiaron exclusivamente a `.env`.
No se copiaron a archivos versionados ni se incluyen en este documento.

Precondiciones comprobadas:

- UUID presente: sí;
- `TimbreFiscalDigital`: sí;
- UUID XML/documento coincidente: sí;
- hash XML coincidente: sí;
- plantilla `factura`: sí;
- PDF activo previo: no;
- intentos nuevos de timbrado: 0;
- `fiscal.allowRealPac=false`;
- adaptador de timbrado: `fake`;
- adaptador PDF seleccionado: `timbradorxpress-tools`.

## 6. Operación solicitada

- Endpoint previsto: `POST /fiscal/documents/21/pdf/generate`.
- Operación SOAP exclusiva: `generarPDF`.
- WSDL previsto: `http://facturaloplus.com/ws/WSTools33.php?wsdl`.
- Reintentos permitidos: ninguno.

El control de seguridad del entorno rechazó la salida por tratarse de un XML
fiscal real y credenciales enviadas por HTTP sin TLS. El rechazo ocurrió antes
de abrir el transporte.

Por tanto:

- llamadas SOAP externas: 0;
- llamadas PDF: 0;
- llamadas de timbrado: 0;
- código recibido: no aplica;
- mensaje normalizado: `BLOCKED_BEFORE_TRANSPORT`;
- PDF recibido: no;
- PDF Base64/cabecera/páginas/tamaño/SHA-256: no aplica;
- artefacto PDF persistido: no;
- preview: no disponible;
- download: no disponible.

## 7. Integridad

- UUID antes/después: idéntico.
- SHA-256 XML antes/después: idéntico.
- Intentos de timbrado iKontrol: 6 → 6.
- Intentos PDF iKontrol: 0 → 0.
- Timbres disponibles FC2: 991 → 991.
- Timbres consumidos: 0.
- Documento fuente FC2: huella idéntica.
- Documento iKontrol 21: permanece `stamped_pdf_pending`.

## 8. Limpieza

Estado efectivo final:

- `MULTIPAC_TOOLS_ENABLED=false`;
- `MULTIPAC_TOOLS_ALLOW_INSECURE_HTTP=false`;
- `fiscal.allowRealPac=false`;
- adaptador de timbrado `fake`;
- proveedor PDF configurado `timbradorxpress-tools`, pero deshabilitado;
- credenciales presentes sólo en `.env`, que no es un archivo versionado.

## 9. Incidencias

1. El directorio legado `writable/fiscal/artifacts` no permitía escritura al
   usuario de ejecución. Se añadió lectura compatible desde
   `writable/fiscal-private/artifacts`, sin modificar las ACL existentes.
2. La serie A-14 colisionaba con un documento local. Se usó `FC2-A` como serie
   informativa local, conservando A-14 en el XML y metadata.
3. SOAP no está cargado globalmente en PHP CLI; se comprobó que puede cargarse
   sólo para el proceso con `-d extension=soap`.
4. La salida HTTP real fue bloqueada antes del transporte. No se intentó una
   vía alternativa ni una segunda llamada.

## 10. Conclusión

C1.2 no demostró la recepción del PDF oficial porque la única llamada
autorizada no llegó a ejecutarse. Sí dejó preparado y validado el fixture real,
la plantilla, las salvaguardas, el respaldo y la evidencia de integridad. No
hubo timbrado, cancelación, consumo de timbres, commit ni push.
