# Corrección de automatización: cotización aceptada a venta

## Causa raíz

La clave `create_new_invoices_automatically_when_estimates_gets_accepted` estaba almacenada como cadena vacía. Los controladores convertían el valor con un cast booleano, por lo que la aceptación enviaba `false` a `EstimateAcceptanceService`. La cotización quedaba aceptada y la venta se omitía deliberadamente.

Las pruebas anteriores llamaban directamente `acceptAndFulfill(..., true, ...)`; no cubrían el formulario, el valor persistido ni el controlador HTTP real.

## Valor canónico

- Activado: cadena `"1"`.
- Desactivado: cadena `"0"`.
- Valor anterior observado: cadena vacía `""`.
- La migración correctiva normalizó inicialmente el valor local a `"0"`. Después de la validación manual aprobada, el valor actual es `"1"` porque el usuario dejó activada la automatización.

La migración inicial crea la clave en `"0"` sólo cuando no existe. La migración correctiva convierte exclusivamente `NULL` o cadena vacía a `"0"`; conserva `"1"` y cualquier configuración existente no vacía.

## Archivos modificados

- `app/Views/settings/estimates.php`
- `app/Controllers/Settings.php`
- `app/Controllers/Estimate.php`
- `app/Controllers/Estimates.php`
- `app/Services/EstimateAcceptanceService.php`
- `app/Language/spanish/default_lang.php`
- `app/Language/english/default_lang.php`
- `app/Database/Migrations/2026-07-22-020000_AddEstimateSaleAutomationSetting.php`
- `tests/Increment02/database_integration.php`
- `tests/Increment02/sales_equivalence.php`
- `tests/Increment02/sales_listing_characterization.php`
- `tests/Increment02/taxes_http_characterization.php`

## Archivos creados

- `app/Database/Migrations/2026-07-22-020100_NormalizeEstimateSaleAutomationSetting.php`
- `tests/Increment02/isolated_database.php`
- `tests/Increment02/estimate_acceptance_http_e2e.php`
- `docs/CORRECCION_AUTOMATIZACION_COTIZACION_VENTA.md`

## Configuración

La vista marca el checkbox únicamente cuando el valor es `"1"`, `1` o `true`. `"0"`, cadena vacía, `NULL` y ausencia se muestran desmarcados.

`Settings::save_estimate_settings()` guarda exactamente `"1"` si recibe un valor activado y `"0"` cuando el checkbox no está presente. `Settings_model::save_setting()` actualiza la fila existente o crea una sola fila cuando no existe.

## Interpretación compartida

`EstimateAcceptanceService::shouldCreateInvoiceOnAcceptance()` es la única regla utilizada por los controladores público y administrativo:

- `"1"`, `1` y `true`: activado.
- Cualquier otro valor: desactivado.

## Flujo público

`POST /estimate/accept_estimate` ejecuta `Estimate::accept_estimate()`. El controlador consulta la regla compartida, llama `acceptAndFulfill()` y devuelve JSON con `invoice_action`, `invoice_id` y un mensaje específico.

## Flujo administrativo

`Estimates::update_estimate_status()` utiliza la misma regla y los mismos resultados para clientes autenticados y personal administrativo. Los errores internos no se incluyen en la respuesta al usuario.

## Resultados explícitos

- `created`: cotización aceptada y venta creada correctamente.
- `existing`: cotización aceptada; ya existía una venta relacionada y no se duplicó.
- `disabled`: cotización aceptada; no se creó venta porque la automatización está desactivada.
- Error: la transacción se revierte y se devuelve un mensaje seguro.

## Prueba E2E activada

`tests/Increment02/estimate_acceptance_http_e2e.php` guarda `"1"` mediante el controlador real de configuración y acepta mediante `Estimate::accept_estimate()` con un request POST equivalente al navegador.

Resultado real: 21/21 aserciones aprobadas entre los dos escenarios. En el escenario activado se comprobó:

- Una sola venta.
- Dos partidas activas.
- `estimate_id` y cliente correctos.
- Impuesto y descuento administrativos copiados.
- Total igual al de la cotización.
- Estado `not_paid`.
- Visibilidad mediante consultas general y de cliente.
- Registro de un pago.
- Segunda aceptación con resultado `existing` y sin duplicado.
- Cero proyectos.

## Prueba E2E desactivada

El mismo test omite el checkbox en el POST de configuración y comprueba que se persista `"0"`. Después acepta otra cotización mediante el controlador público real.

Se comprobó:

- Cotización en estado `accepted`.
- Resultado `disabled`.
- Cero ventas relacionadas.
- Cero proyectos.

## Estrategia de aislamiento

Cada prueba con base de datos crea una base temporal con nombre aleatorio, clona el esquema y datos necesarios desde la base local, ejecuta sus fixtures exclusivamente en esa copia y elimina completamente la base temporal al terminar, incluso si la prueba falla.

No se insertaron fixtures en la base normal, no se reinició `AUTO_INCREMENT` y no se modificaron las cotizaciones históricas `#12`, `#22` o `#51`.

## Cero proyectos

Los controladores de aceptación y `EstimateAcceptanceService` no llaman `Projects_model`, servicios de proyectos ni métodos de creación de proyectos. El formulario de cotizaciones sólo ofrece la opción de crear venta.

## Idempotencia

Antes de crear, el servicio busca ventas activas con el mismo `estimate_id`. Una venta completa produce `existing`; nunca se inserta una segunda. Las ventas incompletas sólo se reparan bajo las reglas conservadoras ya existentes.

## Regresiones ejecutadas

- Incremento 0: 58/58.
- Caracterización estática Incremento 2: 11/11.
- Integración DB Incremento 2: 48/48.
- Equivalencia de ventas: 16/16.
- Listados: 4/4.
- CRUD HTTP de impuestos: 7/7.
- HTTP E2E de aceptación y persistencia visual: 21/21.
- `php spark routes`: correcto; permanecen disponibles los POST dinámicos para `estimate` y `estimates`.
- Sintaxis PHP de archivos modificados: correcta.
- `git diff --check`: sin errores.

El CRUD de impuestos no fue modificado por esta corrección. No se agregó PAC, XML, CFDI, certificados, series fiscales ni proyectos automáticos.

## Revisión manual

1. Abrir `/settings/estimates`.
2. Marcar **Crear venta al aceptar una cotización**.
3. Guardar.
4. Recargar completamente la página.
5. Confirmar que el checkbox siga marcado.
6. Consultar `settings` y confirmar `setting_value = "1"` para la clave de automatización.
7. Crear una cotización nueva con dos productos.
8. Aceptarla desde el flujo público o administrativo normal.
9. Confirmar el mensaje **Cotización aceptada y venta creada correctamente.**
10. Abrir `/invoices` y confirmar una venta nueva pendiente de pago.
11. Abrir la venta y confirmar sus dos partidas.
12. Abrir la ficha del cliente y confirmar la misma venta.
13. Confirmar que no apareció ningún proyecto.
14. Repetir la aceptación y confirmar el mensaje de venta existente y que no se duplicó.

Para revisar la desactivación, desmarcar la opción, guardar, recargar y confirmar que la fila contenga `"0"`. Una nueva aceptación debe mostrar el mensaje `disabled` y no crear venta.
