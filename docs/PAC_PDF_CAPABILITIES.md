# Capacidad PAC disponible para PDF

> **Corrección C1.1:** el hallazgo histórico inicial queda superado. FC2 sí
> utilizó `clientToolsV33->generarPDF(usuario, claveAcceso, xmlB64, plantilla,
> json, logo)`. Sólo `code=210` es éxito y `pdf` contiene el Base64. La
> operación no timbra ni crea UUID. C1.1 bloquea HTTP por defecto, exige
> allowlist y no copió credenciales históricas.

## Evidencia revisada

- `docs/INCREMENTO_09_TIMBRADORXPRESS_REST_Y_TIMBRADO.md`
- `app/Services/Fiscal/Pac/TimbradorXpressRestAdapter.php`
- `app/Services/Fiscal/Pac/TimbradorXpressResponseParser.php`
- `app/Services/Fiscal/Pac/TimbradorXpressStampDataParser.php`
- `app/Config/TimbradorXpress.php`
- pruebas de contrato en `tests/Increment09/`

## Resultado

La única operación REST confirmada en el proyecto es:

```text
POST {baseUrl}/timbrar3
Content-Type: application/x-www-form-urlencoded
Campos: apikey, xmlCFDI
```

`data` se interpreta como un objeto JSON. El parser documentado admite el campo exacto `PDF`, además de `XML`, `UUID`, `FechaTimbrado`, certificados, cadenas, sellos y `CodigoQR`. El contenido `PDF` se trata como Base64 limpio y se valida antes de persistirse.

La evidencia interna no permite afirmar que `PDF` esté presente en todas las respuestas reales ni que dependa de una plantilla. La configuración actual conserva el identificador informativo `Principal`.

## Recuperación exclusiva

No se encontró en las fuentes disponibles una operación oficial confirmada para recuperar exclusivamente el PDF por UUID o XML timbrado. Por tanto:

- no se inventó endpoint, URL ni parámetro;
- un XML/UUID válido sin PDF queda `stamped_pdf_pending`;
- un PDF inválido queda `stamped_pdf_error`;
- nunca se retimbra el CFDI para intentar obtener el PDF;
- la acción **Recuperar PDF** permanece bloqueada hasta incorporar documentación contractual verificable.

La cancelación real del PAC tampoco se implementa en C1; sólo existe el contrato separado y su adaptador fake local.
