# P14 — instalación limpia canónica

## Decisión

La instalación nueva usa `ikontrol:install-canonical`. Sólo acepta una base MySQL vacía cuyo nombre coincida con `--expected-database` y cuyo prefijo sea `ikontrol_`. Importa `install1/database.sql`, aplica los archivos de migración uno por uno con un runner ligado a esa conexión y ejecuta los siete seeders SAT con conexión explícita. Las migraciones P01, P03, P04 y P10 son obligatorias en el plan y se verifica su presencia antes de escribir.

La cadena histórica contiene DML peligroso para datos existentes. En una base verificada vacía, sus transformaciones y truncados no tienen filas que modificar; por ello sólo participan en el instalador nuevo. La actualización de clientes sigue P02 y nunca usa `spark migrate` global ni `db:build-clean`.

## Evidencia

El 2026-10-01 se recreó exclusivamente `ikontrol20_clean`, se instaló con el comando canónico y se registraron 88 migraciones. Se sembraron `sat_tax_codes`, `sat_tax_factor_types`, `sat_tax_regimes`, `sat_cfdi_uses`, `sat_product_service_keys`, `sat_unit_keys` y `sat_tax_object_codes`. No se tocó Base, Navika ni el PAC.

El smoke runner verificó 36 condiciones de schema reconciliado, historial dirigido, administrador, catálogos, rutas P06–P13, disponibilidad de sus runners y configuración de ejemplo sin PAC real. Regresiones aisladas aprobadas: P06 (13), P07 (21), P08 (11), P11 (28), P12 (32) y P13 (24). Los flujos P06–P13 conservan fixtures sintéticos propios; P14 no reutiliza datos comerciales como fixtures.

## Limpieza

`.env.example` ya no apunta a `ikontrol2`; usa la URL canónica y enumera correo, ambiente fiscal separado de `CI_ENVIRONMENT`, endpoints, claves y flags sin secretos. El adapter normal queda fail-closed; `fake` es sólo para pruebas automatizadas. Las herramientas `dold_preview` y FC2 permanecen fuera del instalador porque tienen consumidores activos de importación/diagnóstico; no introducen tablas, datos ni configuración obligatoria de una instancia nueva.
