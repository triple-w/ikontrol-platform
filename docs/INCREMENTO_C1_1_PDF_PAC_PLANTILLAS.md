# Incremento C1.1 — PDF real del proveedor y plantillas configurables

## Resumen

C1.1 separa el timbrado fiscal de la representación impresa. El XML timbrado y
su UUID siguen siendo la autoridad; generar, recuperar o cambiar el PDF no
retimbra el CFDI.

Respaldo confirmado:
`C:\Users\iKontrol\Backups\ikontrol-C1.1-20260725-102710`.

## Contrato histórico

```text
clientToolsV33->generarPDF(
    usuario,
    claveAcceso,
    base64_encode(xmlTimbrado),
    plantilla,
    base64_encode(jsonAuxiliar),
    logoBase64
)
```

Sólo `code=210` es éxito y `pdf` contiene el Base64. La operación es posterior
e independiente al timbrado.

## Configuración

Las credenciales se leen exclusivamente del `.env` local:

```dotenv
fiscal.allowExternalPdf=false
fiscal.pdf.provider=fake
fiscal.pdf.allowExternalPdf=false
fiscal.pdf.defaultTemplateIncome=
fiscal.pdf.defaultTemplateExpense=
fiscal.pdf.defaultTemplatePayment=
fiscal.pdf.defaultTemplateTransfer=
fiscal.pdf.defaultTemplatePayroll=
MULTIPAC_TOOLS_ENABLED=false
MULTIPAC_TOOLS_WSDL=
MULTIPAC_TOOLS_USER=
MULTIPAC_TOOLS_PASSWORD=
MULTIPAC_TOOLS_ALLOW_INSECURE_HTTP=false
MULTIPAC_TOOLS_ALLOWED_HOSTS=
MULTIPAC_TOOLS_CONNECT_TIMEOUT=30
MULTIPAC_TOOLS_REQUEST_TIMEOUT=60
```

`.env.example` no contiene secretos. El adaptador real exige módulo fiscal y
PDF externo habilitados, ambiente no productivo, credenciales completas y WSDL
allowlisted. HTTP se bloquea por defecto y TLS nunca se desactiva.

## Plantillas, metadata y logo

`fiscal_issuer_pdf_templates` configura proveedor, tipo `I/E/P/T/N`, código y
estado por emisor. El resolutor prioriza la configuración exacta, luego el
valor predeterminado del tipo y finalmente produce un error tipado.

La administración está disponible en `/fiscal/pdf-templates` y registra
auditoría. La metadata proviene del XML timbrado y snapshots. El logo se
resuelve en un servicio separado, sólo admite PNG/JPEG y nunca se registra.

## Intentos, persistencia y estados

`fiscal_pdf_generation_attempts` persiste el intento antes del adaptador y no
crea intentos de timbrado. Los estados son `stamped`,
`stamped_pdf_pending`, `stamped_pdf_processing`, `stamped_pdf_error` y
`stamped_pdf_unknown`.

El PDF se guarda como Base64 validado en `fiscal_document_binary_artifacts`
con tamaño, SHA-256, MIME, proveedor, plantilla e intento. No existe archivo
PDF permanente. Un cambio de plantilla conserva la versión anterior.

**Generar PDF del PAC** requiere permiso, confirmación visible, POST y CSRF e
invoca únicamente el servicio PDF:

```text
POST fiscal/documents/{document}/pdf/generate
```

La confirmación muestra serie, folio, UUID y plantilla. En éxito devuelve las
URLs protegidas de vista previa y descarga.

## Pruebas

- C1.1: **38 aprobadas, 0 fallidas**.
- Regresión C1: **42 aprobadas, 0 fallidas**.

Se usó base temporal y dobles SOAP/PAC. Hubo **cero llamadas externas**.
Los documentos 9, 10, 12, 13 y 14 y el intento 11 no fueron modificados en la
base normal.

## Riesgos y sandbox

Una prueba real requerirá WSDL vigente, host autorizado, credenciales rotadas,
plantilla confirmada y autorización expresa. HTTPS y disponibilidad permanente
del endpoint histórico no están confirmados.

Para habilitarla manualmente en un entorno expresamente autorizado se requiere:

```dotenv
MULTIPAC_TOOLS_ENABLED=true
MULTIPAC_TOOLS_ALLOW_INSECURE_HTTP=true
```

Estos valores no fueron activados. El `.env` local conserva ambos en `false`.
