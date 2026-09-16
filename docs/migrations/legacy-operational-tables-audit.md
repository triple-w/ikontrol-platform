# Auditoría de tablas operativas y DBPrefix

Fecha: 2026-08-04. Alcance: sólo metadatos, conteos y revisión de código; no se inspeccionaron filas ni datos personales.

## Conclusión

El conjunto operativo confirmado es el prefijado con `ikontrol_`. El futuro importador debe utilizar nombres lógicos (`items`, `clients`, etc.) mediante Query Builder/modelos, dejando que CodeIgniter aplique el prefijo. No debe escribir en las tablas homónimas sin prefijo.

## Configuración y aplicación del prefijo

- `app/Config/Database.php` declara `DBPrefix = ikontrol_` para `default`.
- `Crud_model::use_table()` concatena `$db->getPrefix()` al nombre lógico y crea el builder sobre la tabla física resultante.
- `db->table('nombre_logico')` aplica el prefijo automáticamente.
- SQL crudo del proyecto obtiene nombres mediante `prefixTable()` y, para DDL, `protectIdentifiers()`.
- Las migraciones usan nombres lógicos con Forge/Query Builder. Cuando usan SQL crudo, construyen el nombre con `prefixTable()`.
- `install1/do_install.php` antepone el prefijo elegido a cada `CREATE TABLE` e `INSERT INTO` del baseline.
- No se encontraron nombres físicos `ikontrol_*` hardcodeados fuera de la configuración.

## Evidencia física

| Entidad | Sin prefijo | `ikontrol_` | Firma estructural | Tabla operativa |
|---|---:|---:|---|---|
| clients | 0 | 1 | Igual, 31 columnas | `ikontrol_clients` |
| items | 0 | 2 | Igual, 11 columnas antes de la nueva migración | `ikontrol_items` |
| company | 1 | 1 | Igual, 11 columnas | `ikontrol_company` |
| users | 1 | 2 | Igual, 34 columnas | `ikontrol_users` |

La capa fiscal sólo existe físicamente como `ikontrol_fiscal_*` y contiene actividad: 2 perfiles, 1 serie y documentos/artefactos fiscales. No se encontraron tablas fiscales homónimas sin prefijo.

## Resolución por modelo

| Modelo o acceso | Nombre lógico | Tabla física con configuración actual |
|---|---|---|
| `Clients_model` | `clients` | `ikontrol_clients` |
| `Items_model` | `items` | `ikontrol_items` |
| `Company_model` | `company` | `ikontrol_company` |
| `Users_model` | `users` | `ikontrol_users` |
| `Fiscal_profiles_model` | `fiscal_profiles` | `ikontrol_fiscal_profiles` |
| `Fiscal_series_model` | `fiscal_series` | `ikontrol_fiscal_series` |
| demás modelos fiscales | `fiscal_*` | `ikontrol_fiscal_*` |

## Consultas que podrían evitar el prefijo

No se detectó una consulta de aplicación relevante que hardcodee `FROM clients`, `FROM items`, `FROM company`, `FROM users` o tablas fiscales sin pasar por el builder/prefijo. El riesgo permanece para scripts futuros: una consulta SQL escrita manualmente con un nombre físico sin prefijo sí podría alcanzar las copias inactivas.

## Riesgos y dudas

- Las tablas sin prefijo son duplicados estructurales con datos iniciales o residuales. No se eliminaron, renombraron ni fusionaron.
- `company` tiene una fila en ambos conjuntos; el conteo por sí solo sería ambiguo, pero el prefijo, los modelos y la actividad fiscal confirman el conjunto operativo.
- Cambiar `DBPrefix` en configuración redirigiría silenciosamente modelos/builders.
- Los scripts externos deben comprobar `getPrefix()` y nunca inferir el conjunto por conteo.
- No queda una duda técnica bloqueante sobre el conjunto a usar. El origen histórico de las tablas sin prefijo no puede determinarse sólo con el repositorio.

