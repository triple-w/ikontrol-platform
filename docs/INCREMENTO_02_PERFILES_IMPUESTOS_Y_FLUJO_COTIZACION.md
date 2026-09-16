# Incremento 02: perfiles, impuestos y flujo de cotización

## Estado final

El Incremento 2 agrega preparación fiscal opcional sin convertir a RISE en un sistema fiscal. Los clientes, cotizaciones, ventas y pagos continúan funcionando sin RFC o perfil fiscal. No se implementaron PAC, CFDI, XML, certificados, series fiscales, cancelaciones SAT ni productos SAT.

Este documento refleja el estado estable posterior a la corrección integral del flujo cotización → venta. La explicación detallada de la normalización del setting está en `docs/CORRECCION_AUTOMATIZACION_COTIZACION_VENTA.md`.

## Catálogos SAT mínimos

Las migraciones y seeders separados preparan:

- `sat_tax_regimes`.
- `sat_cfdi_uses`.
- `sat_tax_codes`: 001 ISR, 002 IVA y 003 IEPS.
- `sat_tax_factor_types`: Tasa, Cuota y Exento.

La carga es mínima y no inventa vigencias: `valid_from` y `valid_to` permanecen nulos cuando no existe una fuente oficial versionada dentro del repositorio. No se creó una matriz régimen–Uso CFDI ni catálogos de pago sin una fuente comprobable. Antes de producción fiscal deberá incorporarse una importación oficial versionada.

## Perfiles fiscales de clientes

`fiscal_profiles` mantiene RFC, razón social, régimen, código postal fiscal y Uso CFDI separados de los datos comerciales de `clients`. Un cliente puede:

- Operar sin perfil fiscal.
- Conservar varios perfiles.
- Guardar un perfil en borrador o incompleto.
- Designar un perfil predeterminado.
- Desactivar perfiles sin eliminar datos comerciales.

Los estados son `draft`, `incomplete`, `ready` e `inactive`. Los permisos de ver y administrar perfiles se integran con los permisos serializados de RISE; los roles existentes no reciben permisos nuevos automáticamente.

## Readiness

`FiscalReadinessService` evalúa localmente perfiles receptores y devuelve `is_ready`, `errors`, `warnings`, `missing_fields` y `profile_id`. Verifica presencia de RFC, razón social, régimen, código postal fiscal, Uso CFDI y actividad de catálogos.

Readiness no consulta al SAT y no afirma que un RFC sea válido ante la autoridad. Tampoco bloquea la operación administrativa.

## Impuestos administrativos con preparación fiscal opcional

La tabla `taxes` conserva `title`, `percentage`, IDs y relaciones administrativas. Las columnas nuevas son aditivas y opcionales:

- Clave SAT y tipo traslado/retención.
- Tipo de factor.
- `xml_rate` y `xml_quota` como `DECIMAL(18,6)`.
- Indicadores administrativo, fiscal y readiness.
- Notas fiscales.

Los impuestos existentes no se convierten automáticamente. Por defecto conservan uso administrativo y permanecen no fiscales. `percentage=16` continúa representando 16%; una configuración IVA 16% puede guardar separadamente `xml_rate=0.160000` como texto decimal exacto.

`TaxFiscalConfigurationService` normaliza valores opcionales, conserva `NULL` cuando no aplican y valida las reglas Tasa/Cuota/Exento. No usa `float` ni `double` para campos fiscales nuevos. `taxable` sigue siendo una bandera administrativa y no se interpreta como ObjetoImp. El tercer impuesto/TDS no se convirtió a ISR.

El CRUD de impuestos quedó comprobado para alta, edición y reapertura de impuestos administrativos y de IVA 16%. Los joins con catálogos son opcionales, por lo que impuestos legacy con valores fiscales nulos siguen listándose y utilizándose.

## Automatización cotización → venta

La opción visible es **Crear venta al aceptar una cotización**. Su clave es:

```text
create_new_invoices_automatically_when_estimates_gets_accepted
```

Los únicos valores canónicos son:

- `"1"`: activada.
- `"0"`: desactivada.

La vista, `Settings::save_estimate_settings()`, `Estimate::accept_estimate()` y `Estimates::update_estimate_status()` utilizan la misma interpretación estricta mediante `EstimateAcceptanceService::shouldCreateInvoiceOnAcceptance()`. No se utilizan casts booleanos ambiguos.

### Setting activado

La aceptación pública o administrativa:

1. Marca la cotización `accepted`.
2. Crea exactamente una venta administrativa.
3. Copia cliente, empresa, partidas, cantidades, descripciones, precios, descuento, impuestos, notas y totales.
4. Conserva `estimate_id`.
5. Asigna `project_id=0` y estado `not_paid`.
6. Hace visible la venta en el listado general y en la ficha del cliente.
7. Permite registrar pagos.
8. Reutiliza una venta existente y no crea duplicados.

`EstimateToInvoiceService` traduce la cotización y `InvoiceCreationService` crea encabezado, partidas y totales oficiales dentro de la transacción. Un fallo revierte la aceptación y la venta completa; no deja encabezados sin partidas.

### Setting desactivado

La cotización queda aceptada y no se crea venta. La respuesta indica explícitamente que la automatización está desactivada.

### Resultados explícitos

- `created`: venta creada.
- `existing`: ya existía una venta relacionada.
- `disabled`: automatización desactivada.

## Cero proyectos

El flujo de aceptación no crea proyectos, no llama servicios de proyectos, no depende de `project_id` y no modifica proyectos existentes. El módulo Proyectos permanece disponible fuera de esta automatización.

## Idempotencia y ventas incompletas

El servicio busca ventas activas por `estimate_id`:

- Ninguna: crea una completa.
- Una completa: devuelve `existing`.
- Un borrador automático inequívoco y sin cambios relevantes: puede completarlo o promoverlo de forma conservadora.
- Una venta modificada o múltiples coincidencias: detiene el proceso y registra conflicto sin duplicar.

No se agregó una restricción `UNIQUE estimate_id` porque RISE puede admitir conversiones manuales independientes; la protección corresponde al servicio.

## Visibilidad y estado

La venta automática utiliza el mismo estado emitido sin pagos que una venta normal: `not_paid`. El listado operativo filtra por fecha de venta y la consulta del cliente utiliza la misma fila. La prueba comprueba ambas consultas y el registro de un pago real sobre el fixture aislado.

## Numeración de cotizaciones

La tabla `estimates` no posee folio independiente, `number_sequence` ni `number_year`. El folio visible usa directamente `estimates.id`.

Los saltos 1, 2, 3, 12, 22 y 51 fueron IDs `AUTO_INCREMENT` consumidos por ejecuciones de pruebas anteriores y posteriormente limpiados. InnoDB no reutiliza esos IDs. No hubo doble incremento ni error de concatenación; no se reinició `AUTO_INCREMENT` ni se renumeraron registros históricos.

Las pruebas actuales ya no consumen IDs de desarrollo.

## Aislamiento de pruebas

Cada script de Incremento 2 que necesita base de datos crea una base temporal con nombre aleatorio, clona el esquema local, ejecuta allí sus fixtures y elimina la base completa al terminar, incluso ante fallos. No se insertan fixtures en la base normal.

La herramienta `tools/diagnose_increment02_sale.sql` es de sólo lectura, exige seleccionar explícitamente el ID objetivo y advierte que la salida puede contener datos comerciales.

## Pruebas finales

- Incremento 0: 58/58.
- Caracterización estática Incremento 2: 11/11.
- Integración DB: 48/48.
- Equivalencia manual/conversión/automática: 16/16.
- Listados general y cliente: 4/4.
- CRUD HTTP de impuestos: 7/7.
- Aceptación HTTP E2E y persistencia visual: 21/21.

El E2E guarda el setting mediante `Settings::save_estimate_settings()` y acepta mediante `Estimate::accept_estimate()`. Con `"1"` comprueba venta, dos partidas, descuentos, impuestos, total, estado, visibilidad, pago, idempotencia y cero proyectos. Con `"0"` comprueba aceptación, resultado `disabled`, cero ventas y cero proyectos.

También se verificaron sintaxis PHP, rutas CI4, estado de migraciones, ausencia de bases temporales y `git diff --check`.

## Estado aprobado manualmente

El usuario confirmó exitosamente en la interfaz real:

- El CRUD de impuestos funciona.
- Se puede cotizar y vender a clientes sin RFC.
- La configuración **Crear venta al aceptar una cotización** guarda correctamente.
- Al aceptar una cotización se genera una venta.
- La venta copia productos, cantidades, descuentos, impuestos y totales.
- La venta queda pendiente de pago.
- La venta aparece en el listado general.
- La venta aparece dentro de la ficha del cliente.
- No se crea proyecto.
- Repetir la aceptación no duplica la venta.
- La prueba manual completa terminó correctamente.

## Migraciones

Orden aplicado:

1. `2026-07-21-010000_CreateMinimalSatCatalogs`.
2. `2026-07-21-010100_ExtendAdministrativeTaxesForFiscalPreparation`.
3. `2026-07-21-010200_CreateFiscalProfiles`.
4. `2026-07-22-020000_AddEstimateSaleAutomationSetting`.
5. `2026-07-22-020100_NormalizeEstimateSaleAutomationSetting`.

Las migraciones detectan tablas/columnas existentes, son aditivas, no alteran columnas monetarias administrativas y no activan automáticamente la venta. La normalización convierte sólo cadena vacía o `NULL` a `"0"` y conserva `"1"`.

Los `down()` de tablas nuevas pueden eliminar únicamente las tablas creadas por este incremento y, por ello, requieren respaldo y revisión antes de un rollback. La extensión de `taxes` y los settings usan rollback conservador para no borrar configuración o datos.

## Limitaciones pendientes

- Los catálogos mínimos no sustituyen una fuente oficial SAT versionada.
- Readiness es validación local, no validación ante SAT.
- No existe todavía snapshot fiscal por concepto.
- No existe emisor fiscal, CSD, PAC, XML, timbrado, cancelación ni PDF fiscal.
- La exclusividad del perfil predeterminado se protege por servicio; MySQL no dispone de índice parcial portable.

## Próximos pasos

El siguiente incremento deberá definirse por separado. No debe reutilizar VAT/GST como RFC, TDS como ISR ni la venta administrativa como CFDI. Antes de cualquier integración fiscal real se requiere fuente oficial de catálogos, modelo de emisor y diseño de snapshots fiscales, manteniendo PAC y certificados fuera del código versionado.
