# Preparación de `ikontrol20_clean`

Fecha de auditoría: 2026-08-04.

## Estado y alcance

La contaminación administrativa del lote 26 quedó corregida quirúrgicamente. La construcción final de `ikontrol20_clean` terminó el 2026-08-04 después de corregir el aislamiento de seeders. No se importó FC2, no se creó información de `DOLD860620EW7` y no se ejecutó rollback.

`ikontrol20_clean` contiene 144 tablas, registra 48 migraciones en batch 1, conserva vacías las tablas fiscales/legacy transaccionales y tiene cargados los diez catálogos SAT previstos actualmente.

## Finalización posterior al incidente de seeders

La solución evita `Seeder::call()`. `ExplicitConnectionSeederOrchestrator` instancia directamente los siete seeders hoja; `ExplicitConnectionSeeder` exige una conexión explícita y liga tanto Query Builder como Forge al mismo objeto. Antes y después de cada seeder se comprueban `SELECT DATABASE()`, `getDatabase()` y el objeto de conexión. Los destinos operativos/legacy están prohibidos.

El comando final ejecutado para datos fue:

```powershell
php spark db:seed-clean-sat --expected-database=ikontrol20_clean --allow-write-clean-build
```

No volvió a ejecutar migraciones. Conteos SAT finales:

| Catálogo | Filas |
|---|---:|
| `sat_tax_codes` | 3 |
| `sat_tax_factor_types` | 3 |
| `sat_tax_regimes` | 9 |
| `sat_cfdi_uses` | 5 |
| `sat_product_service_keys` | 3 |
| `sat_unit_keys` | 3 |
| `sat_tax_object_codes` | 4 |
| `sat_payment_forms` | 5 |
| `sat_payment_methods` | 2 |
| `sat_currencies` | 3 |

No existen códigos duplicados ni rangos de vigencia invertidos. La comparación completa de `ikontrol_new` antes y después produjo el mismo SHA-256 de snapshot: `519e3c5ce14dbf8e7bca579abec9aa3cfeff41bf586ca78a677bd5f4eceb575f`.

## Intento de construcción final y punto de corte

Las comprobaciones previas devolvieron `ikontrol20_clean` tanto en `SELECT DATABASE()` como en `getDatabase()`, con host local, puerto 3306, prefijo `ikontrol_`, charset `utf8mb4` y collation `utf8mb4_general_ci`. El baseline tenía 93 tablas, cero clientes, productos, DOLD, migraciones, tablas fiscales y tablas legacy.

Se ejecutó una sola vez:

```powershell
php spark db:build-clean --expected-database=ikontrol20_clean --allow-write-clean-build
```

El runner nuevo sí quedó físicamente ligado a clean: creó 48 entradas de migración en `ikontrol20_clean.ikontrol_migrations`, batch 1, y elevó el total a 144 tablas. Sin embargo, `CodeIgniter\Database\Seeder::call()` crea cada seeder hijo sin propagar la conexión recibida. Los seeders hoja resolvieron entonces `default`, actualizaron `updated_at` de las diez filas SAT operativas a `2026-08-04 11:58:43` y no cargaron los catálogos SAT en clean.

La comparación detectó exclusivamente diferencias en los tres hashes SAT de `ikontrol_new`; los conteos, historial de migraciones, hashes de esquema de items/legacy, tipo de rate y cero DOLD permanecieron iguales. Conforme al punto de corte solicitado, no se ejecutaron las suites posteriores, no se generó dump y no se realizó restauración temporal.

Estado de clean al detenerse:

| Elemento | Estado |
|---|---:|
| Tablas | 144 |
| Migraciones | 48, batch 1 |
| Clientes / productos | 0 / 0 |
| Perfiles / series fiscales | 0 / 0 |
| Documentos / intentos PAC / stamps | 0 / 0 / 0 |
| Certificados / secretos CSD | 0 / 0 |
| Configuraciones PAC | 0 |
| Lotes / mappings legacy | 0 / 0 |
| `items.rate` | `DECIMAL(18,6) NOT NULL DEFAULT 0.000000` |
| Coincidencias exactas DOLD | 0 |
| Siete catálogos SAT validados | Presentes pero vacíos |

## Incidente de selección de base

El lote 25 aplicado anteriormente en `ikontrol_new` se conserva por decisión expresa: `CreateLegacyImportRegistry`, `ConvertItemRateToExactDecimal`, tablas legacy vacías y `ikontrol_items.rate` como `DECIMAL(18,6)`. No se intentó rollback.

Durante esta continuación, la primera versión de `db:build-clean` utilizó el servicio compartido de migraciones. CodeIgniter creó ese runner ligado previamente a la conexión `default`; pasar `clean_build` a `latest()` actuó como filtro/grupo de historial, pero no sustituyó la conexión física. El guard inicial sí comprobó `ikontrol20_clean`, pero el runner compartido escribió después en `ikontrol_new`.

Efectos comprobados:

- `ikontrol_new.ikontrol_migrations` pasó de 48 a 96 filas.
- El lote 26 contiene 48 registros con grupo `clean_build`, aunque físicamente quedaron registrados en `ikontrol_new`.
- Los dos seeders SAT volvieron a actualizar las diez filas objetivo; sus valores funcionales coinciden con los seeders, pero `updated_at` quedó en `2026-08-04 11:40:56`.
- No se crearon las tablas fiscales o legacy en `ikontrol20_clean`.
- Los conteos comerciales comprobados de `ikontrol_new` permanecieron: clientes 1, productos 2, perfiles fiscales 2, series 2, documentos fiscales 19, lotes legacy 0 y mappings legacy 0.

Por la instrucción “si algo cambió, detenerse”, no se ejecutaron más migraciones ni seeders sobre bases reales.

## Limpieza administrativa del lote 26

Antes de eliminar se creó un respaldo exclusivo de `ikontrol_new.ikontrol_migrations`:

- Ruta: `C:\xampp\htdocs\ikontrol2\ikon2.0\writable\backups\ikontrol_new_migrations_before_batch26_cleanup_20260804_1150.sql`
- Tamaño: 23,151 bytes.
- SHA-256: `e2ccaae749dc2dce20fa3586977dc881594addba71f8c3aab68fa37595743287`.
- Fecha: `2026-08-04T11:49:41-06:00`.

La auditoría comprobó 48 filas, IDs 49–96, `batch=26`, `group=clean_build`, `namespace=App` y tiempos Unix entre `1785865255` y `1785865256`. Las 48 combinaciones versión/clase/namespace tenían una identidad homóloga en un batch anterior. Batch 25 contenía exclusivamente `CreateLegacyImportRegistry` y `ConvertItemRateToExactDecimal`.

La eliminación se ejecutó en una transacción con todas estas condiciones:

```sql
batch = 26
AND `group` = 'clean_build'
AND namespace = 'App'
AND id BETWEEN 49 AND 96
AND time BETWEEN 1785865255 AND 1785865256
```

Antes del commit se exigieron exactamente 48 candidatos, 48 filas en el batch completo, dos identidades exactas en batch 25, 48 filas afectadas y cero remanentes. Resultado: 48 eliminadas, total de migraciones 48, máximo batch 25 y batch 25 intacto. No se invocó rollback de migraciones, `down()`, migración ni seeder.

## Barrera de conexión

Se añadió un grupo dedicado `clean_build` en `app/Config/Database.php`. Fija:

- base: `ikontrol20_clean`;
- prefijo: `ikontrol_`;
- charset: `utf8mb4`;
- collation: `utf8mb4_general_ci`;
- conexión persistente desactivada.

Sólo hereda el transporte y credenciales de la conexión MySQL local. No incorpora configuración FC2 ni secretos PAC.

`DatabaseTargetGuard` rechaza cualquier grupo distinto de `clean_build`, cualquier nombre esperado distinto de `ikontrol20_clean`, y las bases prohibidas `ikontrol_new`, `fc2_migration_source` y `tws001_factucare`. Verifica tanto `SELECT DATABASE()` como `getDatabase()`, existencia del esquema y prefijo exacto.

Comprobación manual segura:

```powershell
php spark db:confirm-target --expected=ikontrol20_clean --group=clean_build
```

La evidencia obtenida antes del bloqueo fue:

```text
database=ikontrol20_clean
get_database=ikontrol20_clean
prefix=ikontrol_
environment=development
```

No se imprime contraseña. Una prueba negativa con `--expected=ikontrol_new` terminó con error antes de escribir.

La implementación de `db:build-clean` construye correctamente un runner nuevo con la conexión `clean_build`, exige ambos flags y utiliza ahora el orquestador explícito de seeders. `SeederTargetIsolation` cubre el aislamiento de Query Builder, Forge, objetos de conexión, identidad física, timestamps y digest de default.

## Prueba automatizada de aislamiento

`tests/DatabaseTargetIsolation/run.php` crea dos bases temporales con nombres aleatorios validados, inserta marcadores distintos y ejecuta una migración fixture mediante un runner construido físicamente con la conexión clean temporal. Resultado: 11/11 verificaciones aprobadas.

- La migración y el repositorio `migrations` existieron sólo en clean temporal.
- El objeto de conexión interno del runner fue exactamente el objeto clean suministrado.
- `SELECT DATABASE()` dentro de la migración devolvió la base clean temporal.
- Default no recibió tablas ni historial de migración y conservó el mismo digest.
- Los marcadores permanecieron distintos e intactos.
- Ambas bases temporales se eliminaron al terminar.

También se comprobó que el builder rechaza la ausencia de `--allow-write-clean-build` y rechaza `--expected-database=ikontrol_new` antes de escribir.

## Auditoría de las diez filas SAT de `ikontrol_new`

Todas las filas son estructuralmente válidas y coinciden con el seeder vigente. Clasificación funcional: **Conservar**. No se intentó restaurarlas.

| Tabla | ID | Clave SAT | Columnas del seeder | Cambio observado | Referencias | Resultado |
|---|---:|---|---|---|---:|---|
| `ikontrol_sat_product_service_keys` | 1 | `01010101` | descripción, vigencia, activo, versión, actualización | Metadatos y `updated_at`; código sin cambio | 0 | Conservar |
| `ikontrol_sat_product_service_keys` | 2 | `43211503` | descripción, vigencia, activo, versión, actualización | Metadatos y `updated_at`; código sin cambio | 0 | Conservar |
| `ikontrol_sat_product_service_keys` | 3 | `81112100` | descripción, vigencia, activo, versión, actualización | Metadatos y `updated_at`; código sin cambio | 0 | Conservar |
| `ikontrol_sat_unit_keys` | 1 | `H87` | nombre, descripción, símbolo, vigencia, activo, versión, actualización | Metadatos y `updated_at`; código sin cambio | 1 | Conservar |
| `ikontrol_sat_unit_keys` | 2 | `E48` | nombre, descripción, símbolo, vigencia, activo, versión, actualización | Metadatos y `updated_at`; código sin cambio | 0 | Conservar |
| `ikontrol_sat_unit_keys` | 3 | `KGM` | nombre, descripción, símbolo, vigencia, activo, versión, actualización | Metadatos y `updated_at`; código sin cambio | 0 | Conservar |
| `ikontrol_sat_tax_object_codes` | 1 | `01` | descripción, activo, vigencia, actualización | Metadatos y `updated_at`; código sin cambio | 0 | Conservar |
| `ikontrol_sat_tax_object_codes` | 2 | `02` | descripción, activo, vigencia, actualización | Metadatos y `updated_at`; código sin cambio | 2 | Conservar |
| `ikontrol_sat_tax_object_codes` | 3 | `03` | descripción, activo, vigencia, actualización | Metadatos y `updated_at`; código sin cambio | 0 | Conservar |
| `ikontrol_sat_tax_object_codes` | 4 | `04` | descripción, activo, vigencia, actualización | Metadatos y `updated_at`; código sin cambio | 0 | Conservar |

Los valores textuales concretos se verificaron contra los seeders sin incluir datos personales ni secretos en este documento.

## Conteos y estructura comprobados

| Elemento | `ikontrol_new` después del bloqueo | `ikontrol20_clean` |
|---|---:|---:|
| Tablas físicas | No recontadas | 93 |
| Registros de migración | 48 | tabla ausente |
| Clientes | 1 | 0 |
| Productos | 2 | 0 |
| Perfiles fiscales | 2 | tabla ausente |
| Series fiscales | 2 | tabla ausente |
| Documentos fiscales | 19 | tabla ausente |
| Certificados | 1 | tabla ausente |
| Secretos de certificados | 1 | tabla ausente |
| Lotes legacy | 0 | tabla ausente |
| Mappings legacy | 0 | tabla ausente |
| Coincidencias exactas de `DOLD860620EW7` | No consultado globalmente | 0 |
| `ikontrol_items.rate` | `DECIMAL(18,6) NOT NULL DEFAULT 0.000000` | `DECIMAL(18,6) NOT NULL DEFAULT 0.000000` |

## Procedimiento de reanudación propuesto

Este procedimiento está documentado, pero no debe ejecutarse sin autorización expresa para reanudar la construcción real.

1. Confirmar que el árbol de trabajo contiene la versión corregida y probada de `DbBuildClean`.
2. Capturar nuevamente conteos y hashes de referencia de `ikontrol_new` sólo con `SELECT`.
3. Ejecutar `php spark db:confirm-target --expected=ikontrol20_clean --group=clean_build`.
4. Exigir que ambas identidades devuelvan exactamente `ikontrol20_clean`.
5. Ejecutar únicamente `php spark db:build-clean --expected-database=ikontrol20_clean --allow-write-clean-build`.
6. Volver a confirmar identidad, migraciones, seeders, tablas legacy vacías y tipo exacto de `rate`.
7. Comparar `ikontrol_new` contra la nueva referencia; detenerse ante cualquier diferencia.
8. Ejecutar las suites autorizadas sólo sobre fixtures temporales o la base limpia.

No debe usarse un override de shell ni asumirse que `migrate -g` cambia una conexión ya ligada al runner.

## Migraciones, seeders y pruebas

- Migraciones aplicadas correctamente en `ikontrol20_clean`: ninguna en esta continuación.
- Seeders hoja aplicados correctamente en `ikontrol20_clean`: siete, mediante conexión explícita.
- `DatabaseTargetIsolation`: 11/11.
- `SeederTargetIsolation`: 30/30.
- `LegacyImportFoundation`: 13/13.
- `Fc2MasterDataDryRun`: 24/24.
- `Increment00`: 58/58.
- `Increment03`: 40/40.
- `IncrementC231`: 24/24.
- Total: 200 verificaciones aprobadas, 0 fallos.

## Respaldo futuro

El respaldo se generó mediante la conexión interna, sin contraseña en argumentos:

- Ruta: `C:\xampp\htdocs\ikontrol2\ikon2.0\writable\backups\ikontrol20_clean_20260804.sql`.
- Tamaño: 269,914 bytes.
- SHA-256: `e0daecf6930827ac0f2818308de6eea337eb9a6d3052553b4fda3642b507db53`.
- Fecha: `2026-08-04T12:15:57-06:00`.

Se restauró en `ikontrol20_clean_restore_test`: 144 tablas, 48 migraciones, máximo batch 1, diez catálogos cargados, `items.rate` exacto, tablas legacy vacías y cero clientes, productos, perfiles, series, documentos, secretos, PAC y DOLD. La base temporal fue eliminada después de validar.

## Riesgos pendientes

- La actualización aceptada de `updated_at` de las diez filas SAT operativas se conserva.
- Antes de distribuir el dump fuera del entorno controlado, mantenerlo protegido porque incluye el usuario administrador local ficticio requerido por el baseline.
- La importación FC2 y cualquier dato DOLD permanecen fuera de alcance.
