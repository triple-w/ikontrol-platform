# Incremento C2.2.1 — Corrección del listado de ventas

Fecha: 2026-07-29  
Entorno: development, base `ikontrol_new`, prefijo `ikontrol_`

## Respaldo

Antes de la corrección se creó:

`writable/backups/c2_2_1_fix_sales_20260729_134710`

El respaldo contiene un dump SQL verificable de 9,150,068 bytes, `.env`, los
controladores, modelos, servicios, vistas, rutas y migraciones solicitados, y
un inventario SHA-256 de 180 archivos. El SHA-256 del dump es:

`F2D86683D022398BD16D2BE8E77643DF73EB9DD27A683B30FCFC1A47D7892798`

## Excepción exacta y reproducción

La ruta comercial usa `Invoices::list_data()`, obtiene las ventas mediante
`Invoices_model::get_details()`, transforma cada registro con `_make_row()` y
construye sus acciones con `_make_options_dropdown()`. Para un administrador o
usuario con `fiscal.sales.invoice`, esta última función llama
`FiscalSaleAllocationService::hasBlockingOperation()`.

La misma consulta se ejecutó contra la conexión efectiva de CodeIgniter. El
resultado anterior fue:

`CodeIgniter\Database\Exceptions\DatabaseException: Unknown column
's.completed_at' in 'where clause'`

El lanzamiento ocurrió en `system/Database/BaseConnection.php:692`; el punto
de aplicación fue
`app/Services/Fiscal/FiscalSaleAllocationService.php:234`, al ejecutar
`countAllResults()`.

SQL anterior, sin datos fiscales:

```sql
SELECT COUNT(*) AS numrows
FROM ikontrol_fiscal_document_sales a
JOIN ikontrol_fiscal_stamp_attempts s
  ON s.fiscal_document_id = a.fiscal_document_id
WHERE a.sale_id = ?
  AND s.status IN ('sending', 'unknown', 'pending')
  AND (s.completed_at IS NULL OR s.requires_reconciliation = 1)
```

El parámetro reproducido fue un identificador numérico de venta. No se
registraron XML, PDF, UUID ni credenciales.

Los logs disponibles en `writable/logs` terminaban el 2026-07-24 y no
contenían el incidente nuevo. La excepción se obtuvo reproduciendo directamente
la misma consulta, servicio y conexión que usa el listado, sin ocultarla.

## Causa raíz

C2.2 agregó la comprobación de operaciones bloqueantes al menú de cada venta.
La consulta mezcló dos esquemas:

- `fiscal_pdf_generation_attempts` tiene `completed_at`;
- `fiscal_stamp_attempts` tiene `responded_at`.

El alias `s` corresponde a `fiscal_stamp_attempts`, por lo que
`s.completed_at` nunca fue una columna válida. El listado base sí recuperaba
ventas; la excepción ocurría al transformar la primera fila autorizada y la
respuesta AJAX terminaba como error general.

## Migraciones y esquema efectivo

`php spark migrate:status` confirmó:

- `2026-08-02-130000_CreateCommercialFiscalAllocationModel`: aplicada, batch 18;
- `2026-08-03-140000_ExtendFiscalDraftWorkflow`: aplicada, batch 19.

También se comprobaron en `ikontrol_new`:

- `ikontrol_fiscal_document_sales`;
- `ikontrol_fiscal_drafts`;
- `ikontrol_fiscal_draft_sales`;
- `ikontrol_fiscal_document_relations`;
- `ikontrol_fiscal_draft_items`;
- `ikontrol_fiscal_draft_audit`.

Las columnas extendidas de `fiscal_drafts` están presentes. Las versiones con
fecha posterior se ordenan por su identificador de versión y ya están
registradas por CodeIgniter; no fue necesario renombrarlas ni volverlas a
aplicar.

## Corrección mínima

Archivo:

`app/Services/Fiscal/FiscalSaleAllocationService.php`

Método:

`hasBlockingOperation(int $saleId)`

Consulta corregida:

```sql
SELECT COUNT(*) AS numrows
FROM ikontrol_fiscal_document_sales a
JOIN ikontrol_fiscal_stamp_attempts s
  ON s.fiscal_document_id = a.fiscal_document_id
WHERE a.sale_id = ?
  AND s.status IN ('sending', 'unknown', 'pending')
  AND (s.responded_at IS NULL OR s.requires_reconciliation = 1)
```

Se cambió exclusivamente la columna inexistente por la columna real,
calificada con su alias. Los builders de CodeIgniter resolvieron correctamente
el prefijo `ikontrol_`; no se halló prefijo duplicado en esta consulta.

Además, `getSaleFiscalSummary()` trata `invoice_total` nulo o vacío como
`0.000000`. Otros valores decimales inválidos continúan lanzando una excepción,
por lo que la protección no oculta corrupción real.

## Validación

Después de la corrección, la consulta real procesa todas las ventas existentes
sin excepción y devuelve una estructura DataTable válida con `data` como
arreglo. El detalle conserva su ruta y el resumen fiscal. Un usuario sin
permisos fiscales conserva el listado y sólo deja de ver las acciones
protegidas.

Apache respondió a la ruta protegida con redirección esperada a inicio de
sesión (`302`), no con error `500`. La validación autenticada automatizada
ejercitó la consulta, servicio y transformación sobre una copia aislada de la
base. No se almacenaron credenciales de navegador.

## Pruebas

Se agregó `tests/IncrementC221/run.php`.

Resultados principales:

- IncrementC221: 31 aprobadas, 0 fallidas;
- IncrementC21: 34 aprobadas, 0 fallidas;
- IncrementC22: 47 aprobadas, 0 fallidas;
- suite completa Increment00–IncrementC221: 18 suites, 0 suites fallidas.

La suite cubre listado, JSON, ventas sin relación fiscal, relaciones legacy,
documentos vigentes y cancelados, borradores, perfil/cliente incompleto,
usuario sin permisos, prefijo, columna no ambigua, total nulo, resumen
predeterminado, listado vacío, detalle y condición de Facturar.

## Integridad y restricciones

- Llamadas PAC: 0.
- Timbres consumidos: 0.
- XML modificados: 0.
- PDF modificados: 0.
- UUID modificados: 0.
- Ventas eliminadas: 0.
- Borradores eliminados: 0.
- Commit: no realizado.
- Push: no realizado.
