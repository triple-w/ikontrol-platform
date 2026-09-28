# Fase B1B — Contrato de baseline y health check de solo lectura

## Objetivo

Este artefacto define un diagnóstico sin mutación para verificar si la instancia actual está lista para operar con la baseline mínima de iKontrol. El comando `php spark ikontrol:baseline-check` debe validar los requisitos mínimos sin ejecutar migraciones, sin recrear tablas ni mutar registros.

## Reglas de seguridad

1. Solo lectura: no se ejecutan `INSERT`, `UPDATE`, `DELETE`, `ALTER TABLE`, ni migraciones.
2. Origen de verdad: la base real es la fuente de comparación del esquema, no el historial de migración local.
3. Salida segura: la respuesta JSON no debe contener secretos, contraseñas ni tokens.
4. Detección de riesgo: inconsistencias de migración se reportan como `WARN`, mientras que faltantes críticos se reportan como `FAIL`.

## Contrato de resultado

Cada comprobación devuelve este esquema:

- `key`: identificador estable del chequeo.
- `group`: dominio del chequeo (`core`, `identity`, `configuration`, `payments`, `catalogs`, `fiscal`, `integrity`).
- `status`: `PASS`, `WARN` o `FAIL`.
- `message`: texto legible para ser mostrado en consola o UI.
- `details`: diccionario con evidencia mínima (faltantes, conteos, versiones duplicadas, etc.).
- `required`: si la comprobación es obligatoria para la baseline crítica.
- `remediationHint`: consejo de acción sin mutación.

La salida global del servicio incluye:

- `status`: estado general de la instancia.
- `summary`: contadores por `pass`, `warn` y `fail`.
- `checks`: lista de resultados por dominio.

## Comprobaciones mínimas

### Core

- `required_core_tables`: valida la presencia de `settings`, `users`, `roles`, `clients`, `items`, `estimates`, `estimate_items`, `invoices`, `invoice_items`, `invoice_payments`, `payment_methods`, `taxes` y `company`.

### Identity

- `company_record`: la empresa activa existe.
- `admin_user`: existe un administrador activo de tipo `staff` con `is_admin = 1`.

### Configuration

- `required_settings`: confirma que estén cargados `language`, `default_currency`, `timezone` y `module_invoice`.

### Payments

- `payment_method_required`: existe al menos un método de pago activo.
- `mxn_financial_account`: existe al menos una cuenta financiera activa con moneda `MXN`.

### Catalogs

- `project_status_catalog`: los estados de proyecto están cargados.
- `sat_product_service_keys`: el catálogo SAT base tiene entradas activas.

### Fiscal

- `fiscal_structure`: si `fiscal_enabled` está activo, exige la estructura fiscal mínima.

### Integrity

- `migration_history`: compara los archivos reales bajo `app/Database/Migrations` con la tabla `migrations` y reporta:
  - versiones duplicadas,
  - archivos sin registro,
  - registros sin archivo,
  - discrepancias del historial.

## Ejecución

```bash
php spark ikontrol:baseline-check
php spark ikontrol:baseline-check --json
```

La salida JSON debe estar delimitada por:

```text
IKONTROL_JSON_BEGIN
{...}
IKONTROL_JSON_END
```

## Resultado esperado

- `PASS`: la instancia cumple la baseline mínima sin advertencias.
- `WARN`: la instancia puede estar operativa, pero el historial o la estructura presentan señales de riesgo que deben documentarse.
- `FAIL`: la instancia no cumple la baseline crítica y requiere intervención manual o restauración de esquema.

## Recomendación de integración

Este comando debe usarse como comprobación de diagnóstico y no como mecanismo de corrección. En B1C y siguientes podrá ampliarse con validaciones de flujo fiscal, saldo de cuentas, estados de documentos y catálogo de SAT más detallado.
