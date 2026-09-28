# P01 — Fundamento de schema para selección de plantilla

Fecha: 2026-09-28. Destino: el repositorio existente `ikontrol-platform`.
Estado: código integrado y probado; NO aplicado a las bases fuente.

## Decisión

Se incorpora la capacidad genérica de `AddProposalTemplateSelection` desde la fuente Navika con revisión manual de su contrato. Se conserva nombre/timestamp `2026-09-25-120000`; no se renumera ni modifica ninguna migración histórica de Base.

La lectura directa actual confirmó que esta migración no está registrada y su columna no existe en ninguna de las dos bases locales. La revisión no afirma el estado de instancias remotas.

| Aspecto | Fuente Navika | Decisión canónica Base |
| --- | --- | --- |
| Columna | INT nullable, default NULL | Se conserva |
| Datos históricos | Sin backfill | Se conserva; no inferir IDs desde HTML |
| Template borrado/inexistente | Sin FK ni lookup | Se conserva la identidad histórica sin exigir catálogo actual |
| Tabla proposals ausente | Error implícito de Forge | Error explícito antes de crear estructura |
| Columna ya existente | Se omite sin validar | Se comprueban tipo INT, nullability y default NULL; incompatibilidad se rechaza sin reescribir datos |
| Metadatos cacheados | Reset sólo después de ADD | Reset inicial y posterior al ADD |
| Rollback | Elimina columna | Conserva columna y selecciones; el código anterior tolera una columna nullable adicional |

La variante canónica se incorpora por primera vez en Base. No se ha sustituido en Navika ni se ha editado una migración ya aplicada en Base. Si una instancia remota registró la variante original, su historial debe respetarse y su definición inspeccionarse; una corrección necesaria debe ser otra migración, no reescribir el registro histórico.

## Archivos

- `app/Database/Migrations/2026-09-25-120000_AddProposalTemplateSelection.php`.
- `tests/ReconciliationSchema/run.php`.
- Este documento y el [registro de ejecución](BASE_V1_RECONCILIATION_EXECUTION.md).

No se incorporan todavía servicios, controllers, vistas o contenido de plantillas. No se cambia `.env`, configuración de empresa ni datos comerciales.

## Validación

| Comando | Resultado |
| --- | --- |
| `php -d extension=sqlite3 tests/ReconciliationSchema/run.php` | 16 aprobadas, 0 fallidas; SQLite en memoria |
| `php -d extension=sqlite3 tests/ReconciliationSchema/run.php --mysql` | 16 aprobadas, 0 fallidas; MariaDB local 10.4.32 en schema temporal propio |
| `php -d extension=sqlite3 tests/BaselineCheck/run.php` | 13 aprobadas, 0 fallidas |
| `php -l app/Database/Migrations/2026-09-25-120000_AddProposalTemplateSelection.php` | Sin errores |
| `git diff --check` | Sin errores |

Los casos cubren prefijo vacío/no vacío, conservación de contenido/status/borrado lógico, ausencia de inferencia para propuestas antiguas, repetición conservando selección, reversión de código sin pérdida de identidad, baseline ausente y columnas existentes incompatibles (TEXT, NOT NULL, default no NULL).

SQLite usa una conexión explícita `:memory:` no compartida. El modo MySQL conecta al servidor local sin seleccionar la base fuente, crea un schema aleatorio `ikontrol_test_schema_<12 hex>`, usa únicamente fixtures sintéticos y lo elimina en `finally`. No clona datos ni cambia la conexión de la aplicación. La eliminación sólo acepta el nombre creado por esa ejecución; no reutiliza un schema preexistente. Las guardas fiscales de prueba deshabilitan PAC real antes del bootstrap.

## Estado real después del paquete

| Base | Tablas | Historial de migraciones | Columna proposal_template_id |
| --- | --- | --- | --- |
| ikontrol20_ik_ikontrol | 172 | 65 | Ausente, sin cambios |
| ikontrol20_dold_preview | 174 | 86 | Ausente, sin cambios |

Base pasa de 84 a 85 archivos de migración. Quedan las **19 históricas sin registro más esta nueva migración no desplegada**. No se confunde prueba de `up()` en fixtures con aplicación/registro en la instalación.

## Despliegue y recuperación pendientes

No ejecutar un runner global para aplicar esta columna: recorrería migraciones históricas peligrosas. Antes del despliegue se debe completar P02, resolver las postcondiciones de cada histórica y establecer un procedimiento canónico de actualización con destino verificado.

El rollback de código conserva la columna. No elimina selecciones ni intenta restaurar snapshots de contenido. DDL MySQL no es una transacción de negocio: si una instalación tiene schema parcialmente alterado, la validación falla y exige conciliación explícita; no transforma silenciosamente columnas existentes.

La selección no se declara funcional en la aplicación hasta integrar P06 y validar servicio/editor/conversión. Este paquete no habilita la versión ni el tag v1.0.0.
