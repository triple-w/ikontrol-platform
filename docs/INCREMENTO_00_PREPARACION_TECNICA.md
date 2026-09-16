# Incremento 00: preparación técnica de iKontrol2.0

> Ejecutado el 21 de julio de 2026. Este incremento no implementa funcionalidad fiscal, no conecta un PAC y no cambia el esquema ni los datos administrativos.

## 1. Estado inicial comprobado

| Elemento | Estado antes del incremento |
|---|---|
| PHP CLI | 8.2.12, compatible con el mínimo PHP 8.1. |
| Framework | CodeIgniter 4.6.1 en `system/CodeIgniter.php`. |
| Git | No era un repositorio válido: existía una carpeta `.git` vacía. `git status` y `git branch` fallaban. No había rama, remoto ni historial. |
| Archivos modificados | Git no podía determinarlos por falta de repositorio/historial. Se preservaron todos los archivos existentes. El plan técnico ya existía como trabajo previo. |
| Base de datos | Grupo `default` MySQLi, host local, prefijo `ikontrol_` y nombre local configurados directamente en `app/Config/Database.php`. Los valores se trasladaron sin cambios a `.env`; no se reproducen aquí. |
| `.env` | No existía. |
| `.gitignore` | No existía. |
| Migraciones | `app/Database/Migrations` y `Seeds` sólo contenían `.gitkeep`; `app/Config/Migrations.php` ya tenía migraciones habilitadas, tabla `migrations` y timestamp `Y-m-d-His_`. |
| CLI | Faltaba el archivo raíz `spark`; `php spark` no podía ejecutarse. |
| Pruebas | No había `tests`, PHPUnit, `composer.json`, `phpunit.xml` ni comando de pruebas propio. Las clases de soporte de CI4 sí están vendorizadas en `system/Test`. |
| Rutas | RISE registra automáticamente todos los archivos PHP de `app/Controllers`; el escaneo original también confundía subdirectorios con controladores. |
| Configuración sensible | Se encontró una clave de cifrado legacy literal en `app/Config/App.php`. No se movió porque cambiarla sin inventario podría invalidar datos existentes. Debe tratarse como riesgo pendiente y rotarse en un incremento separado. |

### Riesgos iniciales

- Sin historial Git no es posible distinguir de forma fiable cambios anteriores del usuario.
- No se puede reconstruir la aplicación con Composer porque la entrega no contiene manifiesto raíz.
- La base configurada podría contener datos útiles; su nombre local no demuestra que sea desechable o no productiva.
- Ejecutar una primera migración en una instalación incompleta podría registrar una baseline falsa; por ello la migración verifica 13 tablas antes de escribir.
- El prefijo configurado forma parte de los nombres reales (`ikontrol_*`); todos los comandos deben usar el `.env` correcto por instalación.
- La clave de cifrado legacy versionada requiere inventario y plan de rotación, no un reemplazo improvisado.

## 2. Resultado de control de versiones

Se ejecutó `git init -b main`. El repositorio quedó local, sin remoto y sin commit. Como todo el árbol existía antes de inicializar Git, el primer `git status --short` muestra los archivos reproducibles como no rastreados; esto no significa que el incremento los haya creado todos.

`.gitignore` protege:

- `.env` y variantes locales, conservando `.env.example`;
- configuración `*Local.php` y archivos de secretos;
- cache, logs, sesiones, uploads y futuro `writable/fiscal-private`;
- CER, KEY, PEM, PFX/P12 y JKS;
- temporales, configuración de editor, cobertura y cache de PHPUnit.

No se configuró remoto, no se ejecutó push y no se hizo commit ni staging.

## 3. Configuración por entorno

`app/Config/Database.php` conserva la estructura estándar CI4 pero ya no contiene host, usuario, contraseña ni nombre de base locales. `BaseConfig` resuelve el array mediante las claves puntuales soportadas por CI4:

```dotenv
CI_ENVIRONMENT = development
app.baseURL = 'http://localhost/ikontrol2/'
database.default.hostname = localhost
database.default.database = ikontrol_example
database.default.username = ikontrol_example_user
database.default.password = change-locally
database.default.DBDriver = MySQLi
database.default.DBPrefix = ikontrol_
database.default.port = 3306
```

`.env.example` sólo contiene ejemplos. El `.env` local ignorado recibió los valores que ya estaban configurados, sin cambiarlos. En cada cliente se debe crear su propio `.env`, restringir sus permisos y comprobar `git check-ignore .env`.

`app/Config/App.php` ahora llama `parent::__construct()`, requisito para que variables como `app.baseURL` sobrescriban propiedades. Para CLI, usa `http://localhost/` sólo como base neutral cuando no hay host HTTP; en web conserva el cálculo legacy. No se cambió la clave de cifrado existente.

## 4. Migraciones y baseline

Se restauró el lanzador estándar `spark`; `php spark` descubre `migrate` y `migrate:status`. `app/Config/Migrations.php` ya estaba correctamente habilitado y no necesitó cambios.

La migración `2026-07-21-000000_RiseAdministrativeBaseline.php` realiza este orden:

1. Usa la conexión/prefijo de la instalación.
2. Verifica `settings`, `users`, `roles`, `clients`, `items`, `estimates`, `estimate_items`, `invoices`, `invoice_items`, `invoice_payments`, `payment_methods`, `taxes` y `company`.
3. Si falta alguna, lanza `RuntimeException` con la lista y no crea el marcador.
4. Si todas existen, crea solamente `app_schema_versions` cuando falte.
5. Inserta una vez `rise-administrative-baseline-1`.
6. CI4 registra normalmente la migración en su propia tabla `migrations`.

`app_schema_versions` no sustituye `migrations`: aporta diagnóstico legible entre instalaciones independientes. Por seguridad ante una tabla que pudiera ser compartida por versiones futuras, `down()` sólo elimina el registro de esta baseline y conserva la tabla vacía; nunca crea, altera ni elimina tablas administrativas. No se modificó `install1/database.sql`.

## 5. Comandos para migraciones

Desde PowerShell en `C:\xampp\htdocs\ikontrol2`:

```powershell
php spark migrate:status
php spark migrate
```

Si `php` no está en `PATH`:

```powershell
C:\xampp\php\php.exe spark migrate:status
C:\xampp\php\php.exe spark migrate
```

No usar `migrate:refresh`: ejecuta rollback y podría ser destructivo en futuras migraciones. Revisar siempre `.env`, respaldo, prefijo y nombre de DB antes de `migrate`.

## 6. Instalación existente

1. Activar ventana de mantenimiento y detener escrituras administrativas.
2. Confirmar que el código corresponde a la release prevista y que `.env` apunta a esa instalación.
3. Respaldar DB y archivos; probar que el dump puede leerse/restaurarse.
4. Confirmar las 13 tablas con el prefijo de la instalación.
5. Ejecutar `php spark migrate:status`.
6. Ejecutar `php spark migrate` una sola vez.
7. Verificar el registro baseline tanto en `migrations` como en `app_schema_versions`.
8. Probar login, clientes, artículos, cotizaciones, ventas y pagos.

La migración aborta antes de crear el marcador si el esquema no parece RISE. No se debe editarla para saltar una tabla faltante: primero se diagnostica la instalación.

## 7. Instalación nueva

Hasta que el instalador sea migrado de forma controlada:

1. Crear DB vacía y `.env` propio.
2. Ejecutar el instalador RISE existente, que importa `install1/database.sql`.
3. No ejecutar la baseline antes del SQL: fallará deliberadamente.
4. Respaldar el esquema recién instalado.
5. Ejecutar `php spark migrate` para registrar baseline y futuras migraciones.
6. Completar smoke tests administrativos.

`install1/database.sql` sigue siendo la fuente de instalación administrativa inicial en este incremento.

## 8. Respaldo antes de migrar (Windows/XAMPP)

Usar placeholders y la solicitud interactiva de contraseña; no escribirla en el comando ni en scripts versionados:

```powershell
New-Item -ItemType Directory -Path C:\backups\ikontrol2 -Force
C:\xampp\mysql\bin\mysqldump.exe --host=localhost --user=USUARIO -p --single-transaction --routines --triggers NOMBRE_DB > C:\backups\ikontrol2\antes_incremento.sql
```

Respaldar por separado `files/` y `writable/` que contengan datos persistentes. Validar tamaño, checksum y restauración en una DB aislada. No guardar dumps bajo webroot ni Git.

## 9. Pruebas

No se instaló PHPUnit porque no existe manifiesto raíz. Se creó un runner mínimo compatible con PHP/CI4:

```powershell
php tests\Increment00\run.php
```

Comprueba dinámicamente la carga de `Config\Fiscal`, módulo deshabilitado, PAC real bloqueado y adaptador fake. Comprueba estáticamente que no hay ruta fiscal pública, que la baseline verifica y no crea/elimina tablas RISE, y que `.gitignore` cubre archivos sensibles. El resultado ejecutado fue **58 passed, 0 failed**.

Esto no sustituye una futura suite PHPUnit. Falta un manifiesto de dependencias reproducible, configuración PHPUnit y una DB de pruebas MySQL aislada; SQLite `:memory:` no representa adecuadamente el esquema MySQL heredado.

## 10. Estructura fiscal inerte

Se añadieron sólo `.gitkeep` en:

```text
app/Domain/Fiscal/{DTO,Exceptions,Money,Validation,ValueObjects}/
app/Services/Fiscal/Pac/
app/Models/Fiscal/
app/Controllers/Fiscal/
app/Views/fiscal/
```

`app/Config/Fiscal.php` deja `enabled=false`, ambiente `local`, `allowRealPac=false`, storage futuro bajo `writable/fiscal-private` y adaptador `fake`. No contiene endpoints, credenciales, RFC, reglas CFDI ni certificados.

`app/Config/FiscalRoutes.php` no registra endpoints. `Routes.php` lo carga explícitamente y el escaneo RISE ahora acepta sólo archivos PHP, no carpetas; por ello `Controllers/Fiscal` no genera rutas automáticas.

## 11. Archivos creados

- `.gitignore`, `.env.example`, `.env` local ignorado y `spark`.
- `app/Config/Fiscal.php`, `app/Config/FiscalRoutes.php`.
- `app/Database/Migrations/2026-07-21-000000_RiseAdministrativeBaseline.php`.
- Nueve `.gitkeep` de la estructura fiscal.
- `tests/bootstrap.php`, `tests/Increment00/run.php`.
- `docs/INCREMENTO_00_PREPARACION_TECNICA.md`.
- Metadatos internos `.git/` creados por `git init`.

## 12. Archivos modificados

- `app/Config/Database.php`: valores locales sensibles pasan a `.env`.
- `app/Config/App.php`: habilita overrides de entorno y ejecución CLI segura.
- `app/Config/Routes.php`: incluye rutas fiscales explícitas vacías y excluye directorios del descubrimiento legacy.

No se modificaron controladores, modelos, vistas ni cálculos administrativos; tampoco `install1/database.sql`, `system/**` o `app/ThirdParty/**`.

## 13. Reversión del incremento

No hay commit base, por lo que **no usar `git reset`, `git clean` ni borrar en masa**. Para revertir antes de ejecutar migración:

1. Restaurar desde copia verificada las tres configuraciones modificadas (`App.php`, `Database.php`, `Routes.php`).
2. Retirar individualmente los archivos creados listados en §11, conservando documentos previos.
3. Si se desea abandonar Git, hacerlo sólo tras respaldar y confirmar el objetivo; `.git` contiene únicamente el repositorio nuevo sin commits.

Si la baseline fue aplicada, primero confirmar backup y ejecutar rollback dirigido únicamente mientras sea la última migración:

```powershell
php spark migrate:rollback
```

Ese `down()` sólo retira el registro baseline y conserva `app_schema_versions`; no toca RISE. La tabla vacía puede conservarse sin impacto. En cuanto existan más migraciones, no usar rollback global sin revisar el batch.

## 14. Validaciones y limitaciones

- `php -v`: ejecutado, PHP 8.2.12.
- `php spark`: ejecutado después de restaurar `spark`; CI4 4.6.1 mostró comandos.
- `php spark routes`: ejecutado; no aparecen rutas fiscales, timbrado, PAC ni el falso controlador `Fi`.
- Lint PHP: archivos nuevos/modificados relevantes sin errores.
- Runner Incremento 0: 58/58 pruebas pasaron.
- `git diff --check`: ejecutado sin avisos; al no existir commit inicial, Git todavía considera el árbol completo como no rastreado y no puede producir un diff histórico útil.
- `php spark migrate:status`: no se usa como autorización para migrar; requiere conexión a la DB local configurada.
- `php spark migrate`: **no ejecutado**. El nombre/host local no garantiza que la DB sea desechable y no hubo autorización específica sobre sus datos.
- No se hizo prueba HTTP/browser completa; se requiere smoke test manual de la instalación.
- No existe PHPUnit/Composer raíz; el runner actual es deliberadamente mínimo.

## 15. Riesgos pendientes y confirmaciones

- Rotar/mover la clave de cifrado legacy requiere inventario de datos cifrados y procedimiento separado.
- Crear una DB MySQL exclusiva de pruebas antes de probar migrations end-to-end.
- Establecer un primer commit baseline revisado para que Git pueda distinguir cambios futuros.
- Definir manifiesto reproducible de dependencias y PHPUnit sin sustituir librerías vendorizadas a ciegas.
- El enrutamiento dinámico general de RISE sigue siendo amplio; sólo el futuro fiscal queda aislado.

**Confirmación:** el módulo fiscal permanece deshabilitado; no hay CFDI, perfiles, SAT, CSD, series, impuestos fiscales, PAC ni endpoints fiscales. No hubo conexión externa ni cambio de tabla/dato administrativo.
