# Incremento 04: emisor, series y revisión fiscal

## Estado del Incremento 3

El Incremento 3 se cerró después de ejecutar su batería completa y las regresiones de los incrementos anteriores. Los catálogos locales quedaron con 52,839 claves de productos y servicios y 2,418 claves de unidad; los buscadores esperan tres caracteres y usan AJAX POST. El cierre está en el commit `ac91929` y la etiqueta anotada `ikontrol-2.0-incremento-03`. No se realizó push.

## Auditoría inicial de empresa

RISE representa la identidad administrativa en `company`, administrada por `Company`, `Company_model` y `app/Views/company`. Una instalación puede tener varias compañías administrativas. `company_id` identifica cuál compañía, logo y datos comerciales se muestran en cotizaciones, ventas y PDFs; no es una razón social fiscal.

El nombre, domicilio, teléfono, correo, sitio web y logo de `company` son administrativos. VAT/GST y el módulo genérico E-Invoice tampoco son RFC, régimen ni CFDI mexicano. No se copiaron ni reinterpretaron. Los perfiles receptores existentes viven en `fiscal_profiles` con `profile_type=receiver`; la tabla ya reservaba `company_id` y contenía domicilio fiscal separado.

## Modelo elegido para emisores

Se reutiliza `fiscal_profiles` con `profile_type=issuer`. Es la alternativa de menor riesgo porque conserva una sola frontera de perfiles fiscales, permite asociar opcionalmente una compañía administrativa y mantiene `client_id=NULL` para emisores. La migración agrega únicamente `trade_name`, `expedition_postal_code`, `email` y `phone`; los demás campos fiscales y de domicilio ya existían.

Puede haber varios emisores. Sólo uno puede ser predeterminado por `company_id` (o para la instalación cuando no hay compañía asociada). La exclusividad se aplica dentro de una transacción. Los perfiles se desactivan; no se eliminan físicamente. Editar un emisor no actualiza `company`.

## Readiness del emisor

`IssuerFiscalReadinessService` devuelve `not_configured`, `incomplete`, `ready` o `inactive`, además de errores, advertencias y campos faltantes. Exige RFC, razón social, régimen activo, código postal fiscal, código postal de expedición, país y perfil activo. Calle, colonia, municipio, estado y nombre comercial sólo generan advertencias. No valida el RFC contra el SAT ni llama servicios externos.

## Series y folios

`fiscal_series` separa los tipos `ingreso`, `egreso` y `pago`. La combinación emisor, tipo y serie es única. Cada serie guarda `initial_folio` y el último `current_folio`; una serie nueva inicia `current_folio = initial_folio - 1`.

`FiscalFolioService::previewNextFolio()` sólo lee y nunca actualiza. `reserveNextFolio()` abre una transacción, obtiene la fila con `SELECT ... FOR UPDATE`, calcula el siguiente número sin `MAX`, actualiza la serie y confirma. Ante cualquier excepción hace rollback. Este incremento sólo usa preview: la reserva queda preparada para una fase que persista un documento fiscal real.

## Permisos

Se añadieron `fiscal_issuers_view`, `fiscal_issuers_manage`, `fiscal_series_view`, `fiscal_series_manage` y `fiscal_sales_review`. La ausencia de clave deniega acceso; roles anteriores no se modifican. El administrador global conserva acceso. Vender administrativamente no exige ningún permiso fiscal.

## Revisión fiscal integral

`SaleFiscalReadinessService` compone readiness de emisor, receptor y productos, una serie activa de ingreso y los datos administrativos de la venta. Propone los perfiles y serie predeterminados, pero permite otra selección activa en el request. Muestra moneda, subtotal, descuento, impuestos y total administrativos.

Una venta queda lista para preparación fiscal sólo si el emisor, receptor, serie y todas las partidas están listos, existe al menos una partida, no hay partidas manuales sin configuración, la moneda es identificable, el total no es negativo y la venta no está eliminada ni cancelada. Los estados visibles son “No lista”, “Lista con advertencias” y “Lista para preparación fiscal”. No existe botón Timbrar.

La revisión no guarda selecciones, no crea `fiscal_documents`, no consume folios y no altera la venta. Forma y método de pago SAT, cálculo fiscal definitivo, PAC, XML, sellos, UUID y estado SAT permanecen pendientes.

## Migraciones

- `2026-07-24-040000_ExtendFiscalProfilesForIssuers.php`
- `2026-07-24-040100_CreateFiscalSeries.php`

Son aditivas, no insertan emisores ficticios y no modifican `clients`, `items`, `invoices` ni `taxes`. No contienen FLOAT o DOUBLE. El rollback retira exclusivamente la estructura agregada, con la advertencia habitual de que ejecutar `down()` de series eliminaría datos de series ya capturados.

## Pruebas y aislamiento

`tests/Increment04/database_integration.php` utiliza la clonación temporal creada por `tests/Increment02/isolated_database.php`; no escribe fixtures en la base normal de desarrollo. Cubre emisores incompletos y listos, domicilios independientes, único predeterminado, separación de `company`, series por tipo y emisor, preview, reservas únicas, rollback, serie inactiva, permisos heredados y revisión sin consumo. `tests/Increment04/run.php` revisa estructura, rutas, permisos y límites de alcance.

## Revisión manual

### Emisor

1. Abrir `/fiscal/issuers` desde Configuración → Configuración fiscal de emisores.
2. Crear una razón social emisora y guardarla incompleta; confirmar “Configuración incompleta”.
3. Completar RFC, razón social, régimen, país y ambos códigos postales; confirmar “Listo para emitir”.
4. Crear un segundo emisor con domicilio distinto y verificar independencia.
5. Marcar sólo uno como predeterminado.

### Serie

1. Abrir `/fiscal/series`.
2. Crear serie `A`, tipo Ingreso, indicar folio inicial y marcarla predeterminada.
3. Anotar “Próximo folio”, recargar y confirmar que no cambió.

### Venta

1. Abrir una venta y elegir “Revisión fiscal”.
2. Confirmar emisor, serie y perfil receptor propuestos.
3. Revisar ClaveProdServ, ClaveUnidad, ObjetoImp y errores de cada partida.
4. Corregir faltantes hasta obtener “Lista para preparación fiscal”.
5. Confirmar que no aparece “Timbrar” y que recargar no consume el folio.

## Limitaciones y próximos pasos

No existe snapshot fiscal, documento fiscal, XML, PAC, certificado CSD, timbrado, cancelación, nota de crédito fiscal ni complemento de pago. La reserva de folio no debe usarse hasta que una fase posterior persista atómicamente un borrador fiscal autorizado. Falta una validación de concurrencia con procesos realmente paralelos; la implementación usa bloqueo de fila y la prueba aislada caracteriza reservas consecutivas y rollback.

## Confirmación de alcance

Este incremento no modificó el CRUD de impuestos ni el flujo cotización → venta. No implementó PAC, XML, CFDI, CSD ni timbrado.
