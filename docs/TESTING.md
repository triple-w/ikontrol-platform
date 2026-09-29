# Pruebas: selección y aislamiento

Este repositorio utiliza runners PHP por paquete. No existe una orden universal que deba ejecutarse a ciegas. Elige el runner cercano al cambio y lee su bootstrap, conexiones, escritura de archivos y transporte antes de lanzarlo.

## Comandos con alcance conocido

Desde la raíz, con PHP CLI y SQLite3 disponibles:

```powershell
php -d extension=sqlite3 tests/BaselineCheck/run.php
php -d extension=sqlite3 tests/ReconciliationSchema/run.php
```

Ambos usan conexiones explícitas y fixtures SQLite en memoria. En XAMPP puede usarse `C:/xampp/php/php.exe` si `php` no está en PATH.

La variante siguiente **crea y elimina un esquema MySQL temporal con fixtures sintéticos**; requiere revisar la conexión local y permisos de servidor antes de ejecutarla:

```powershell
php -d extension=sqlite3 tests/ReconciliationSchema/run.php --mysql
```

Las decisiones, resultados y límites de esa validación están en [P01](reconciliation/P01_PROPOSAL_TEMPLATE_SCHEMA.md). Son evidencia fechada, no una afirmación de que todo el repositorio esté probado.

## Riesgos de los runners heredados

[tests/bootstrap.php](../tests/bootstrap.php) configura el entorno de pruebas, pero puede cargar configuración local. `ENVIRONMENT=testing` no demuestra aislamiento de BD ni transporte PAC.

[Increment02/isolated_database.php](../tests/Increment02/isolated_database.php) crea una BD temporal copiando estructura **y todas las filas** de la conexión de origen, luego la elimina. Su filtro del nombre de BD no es una garantía de seguridad. Los runners CanonicalPayments, PaymentComplementStamping, DoldSupplierCosts y DoldWarehousesPhase2 lo utilizan: requieren un origen de fixtures preparado, no datos comerciales de Base/Navika. No ejecutar todos los `run.php` mediante descubrimiento automático.

Los scripts `manual_*_development.php` de [IncrementC24](../tests/IncrementC24/) incluyen operaciones fiscales reales; no son una batería automatizada segura. Los tests fiscales deben usar transporte mock/fake verificable, sin PAC real. Los tests PDF también pueden producir archivos: revisa sus destinos.

Los nombres históricos `Dold*` no bastan para clasificar un test como específico de cliente. Evalúa qué comportamiento cubre y qué datos requiere.

## Validación proporcionada al cambio

Para PHP modificado, utiliza `php -l ruta/al/archivo.php` y el runner correspondiente tras comprobar aislamiento. Para documentación, verifica enlaces, consistencia de referencias y `git diff --check`; no es necesario ejecutar suites que mutan BD. La selección por paquete pendiente se mantiene en el [plan de ejecución](reconciliation/BASE_V1_RECONCILIATION_EXECUTION.md), sin duplicarla aquí.
