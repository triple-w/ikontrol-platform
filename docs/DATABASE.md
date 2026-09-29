# Base de datos: evidencia y reconciliación

## Tres fuentes distintas

1. [Migraciones](../app/Database/Migrations/) describen transformaciones previstas, incluyendo posibles backfills y DML.
2. El registro de migraciones dice qué ejecuciones se registraron; no prueba por sí solo el efecto actual.
3. El esquema observado muestra estructura existente; no prueba que los datos hayan sido transformados correctamente.

La diferencia entre esas tres fuentes es material en esta Base. La conciliación histórica se sigue en el [registro de ejecución](reconciliation/BASE_V1_RECONCILIATION_EXECUTION.md). No marcar migraciones como aplicadas ni ejecutarlas globalmente por el solo hecho de encontrar una tabla o columna. No editar migraciones históricas ejecutadas para igualarlas con Navika.

## Referencia verificable

- [schema-reference.json](database/schema-reference.json): captura de la **Base local**, con fecha UTC, commit del código, identidad de BD/servidor, consultas exactas y resultados ordenados.
- [schema-reference.sha256](database/schema-reference.sha256): integridad del archivo completo. El JSON incluye además hash del bloque de resultados y su codificación.

Se obtuvo por `mysqli`, sin arrancar la aplicación, mediante transacción de sólo lectura y `ROLLBACK`. Incluye tablas, columnas, tipos, nullability, defaults, componentes de índices/FK, checks y metadatos de triggers visibles, además del registro técnico de migraciones. No contiene filas de negocio, credenciales ni un dump restaurable. Los cuerpos de triggers, si existen, se representan sólo por hash.

Las consultas de metadatos son secuenciales: DDL concurrente puede impedir una captura atómica. Las definiciones de vistas, rutinas, eventos, permisos y artefactos externos quedan fuera del alcance. Cero resultados visibles no demuestra ausencia bajo otros permisos.

Verificar integridad desde la raíz con PowerShell:

```powershell
$expected = ((Get-Content docs/database/schema-reference.sha256 -Raw).Trim() -split '\s+')[0]
$actual = (Get-FileHash docs/database/schema-reference.json -Algorithm SHA256).Hash.ToLowerInvariant()
if ($actual -ne $expected) { throw 'Schema reference checksum mismatch' }
```

Consultar una tabla sin cargar el catálogo entero en el contexto de Codex:

```powershell
$schema = Get-Content docs/database/schema-reference.json -Raw | ConvertFrom-Json
$schema.data.columns | Where-Object TABLE_NAME -eq 'ikontrol_proposals'
```

Los nombres y conteos completos pertenecen al artefacto; no se mantienen listas paralelas aquí. Los conteos de índices/FK corresponden a componentes, no necesariamente a restricciones distintas.

## Verificar o renovar contra una instalación

Confirma primero destino, permisos y prefijo en la configuración local de [Database.php](../app/Config/Database.php) y sus overrides, sin imprimir secretos. La referencia existente identifica sólo la Base capturada; no asumas que otra conexión corresponde a ella.

Con una conexión de lectura y sin bootstrap de la aplicación, ejecuta las consultas almacenadas en `queries` del JSON contra el destino confirmado. Conserva su orden, compara cada bloque de `data` y cierra la transacción. Para una nueva captura registra fecha UTC, identidad, commit y alcance; recalcula el hash interno según `payload_hash_encoding` y el SHA-256 del archivo final. Revisa el diff antes de versionarlo para evitar incluir datos o secretos. No publiques credenciales ni incorpores filas comerciales para explicar una diferencia estructural.

Un cambio de esquema requiere una decisión independiente sobre datos existentes y despliegue. La [decisión P01](reconciliation/P01_PROPOSAL_TEMPLATE_SCHEMA.md) muestra la separación entre migración probada y migración realmente aplicada.
