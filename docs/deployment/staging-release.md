# Release de staging de iKontrol 2.0

Fecha de preparación: 2026-08-04. Este documento prepara el despliegue; no acredita que se haya subido o ejecutado nada en un servidor.

## Artefactos

- Código: `ikontrol20-staging-20260804.zip` (se genera localmente bajo `writable/releases/`).
- Tamaño del ZIP: 15,800,177 bytes.
- SHA-256 del ZIP: `8b5aa5c04e37f1ce490ca985b067dfc909cc097f052ee11adfe2ec7379f0bb7d`.
- Base separada: `writable/backups/ikontrol20_clean_20260804.sql`.
- SHA-256 esperado del SQL: `e0daecf6930827ac0f2818308de6eea337eb9a6d3052553b4fda3642b507db53`.
- El SQL no se incluye dentro del ZIP de código.

## Requisitos

- PHP 8.1 o superior; la validación local se realizó con PHP 8.2.12.
- Extensiones requeridas por el framework y módulos usados: `curl`, `dom`, `fileinfo`, `gd`, `iconv`, `intl`, `json`, `libxml`, `mbstring`, `mysqli`, `mysqlnd`, `openssl`, `session`, `SimpleXML`, `soap`, `xml`, `xmlreader`, `xmlwriter`, `zip` o soporte ZipArchive, y `zlib`. `bcmath` se recomienda para operaciones fiscales exactas.
- MariaDB 10.4 con `utf8mb4`/`utf8mb4_general_ci`; la versión validada fue 10.4.32. Para evitar diferencias no comprobadas, staging debe conservar esa familia de motor antes de certificar otra versión.
- HTTPS y acceso CLI a `php spark`.

No existe `composer.json` en la raíz: las dependencias del producto están incluidas en `system/` y `app/ThirdParty/`. Por tanto, **no se debe ejecutar `composer install` en la raíz**. Los manifests Composer internos pertenecen a bibliotecas vendorizadas y no deben reinstalarse durante este despliegue.

## Directorio público y servidor web

El front controller real es `index.php` en la raíz y carga `app/Config/Paths.php` mediante rutas relativas. El DocumentRoot debe apuntar a la raíz descomprimida de la release, no al subdirectorio `public/`. La configuración del servidor debe impedir acceso HTTP a `.env`, `app/`, `system/`, `writable/`, `tests/`, `docs/` y archivos de configuración. `public/soap-check.php` es diagnóstico local y está excluido.

## Entorno

Copiar `.env.example` a `.env` **en el servidor**, nunca dentro del ZIP ni del repositorio. Valores obligatorios:

- `CI_ENVIRONMENT = production`;
- `app.baseURL` con HTTPS y slash final;
- `database.default.hostname`, `database.default.database`, `database.default.username`, `database.default.password`, `database.default.DBDriver = MySQLi`, `database.default.DBPrefix = ikontrol_` y puerto;
- `fiscal.enabled = false`;
- `fiscal.allowRealPac = false`;
- `fiscal.pdf.allowExternalPdf = false`;
- rutas privadas dentro de `writable`.

Mantener vacíos en esta release:

- claves TimbradorXpress sandbox/producción;
- `fiscal.pacEncryptionKey` y `fiscal.csdEncryptionKey` mientras no existan secretos cifrados;
- usuario, contraseña, WSDL y hosts de MultiPAC;
- todas las variables `FC2_DB_*` salvo el charset/puerto no sensibles;
- cualquier SMTP, FTP/SFTP o API externa no requerida para el smoke test.

PAC debe permanecer deshabilitado y sin credenciales. No habilitar timbrado, PDF externo ni modo de producción PAC durante este despliegue.

## Writable y archivos

Crear fuera del ZIP, propiedad del usuario del proceso PHP:

```text
writable/cache
writable/logs
writable/session
writable/uploads
writable/debugbar
writable/reports
writable/backups
writable/releases
writable/fiscal-private
writable/fiscal/prexml
writable/fiscal/certificates
writable/fiscal/artifacts
writable/fiscal/pac-contingency
```

Usar permisos mínimos que permitan lectura/escritura al proceso PHP; evitar `0777`. El servidor web no debe servir `writable` directamente.

## Base de datos

1. Crear una base nueva vacía con `utf8mb4` y `utf8mb4_general_ci`.
2. Transferir el SQL por un canal separado y protegido.
3. Verificar antes de importar:

```powershell
Get-FileHash .\ikontrol20_clean_20260804.sql -Algorithm SHA256
```

Linux:

```bash
sha256sum ikontrol20_clean_20260804.sql
```

4. Usar un archivo de opciones MySQL privado para no poner la contraseña en argumentos:

```bash
mysql --defaults-extra-file=/secure/ikontrol-staging.cnf ikontrol20_staging < ikontrol20_clean_20260804.sql
```

5. Configurar `.env` para esa base y ejecutar sólo lectura:

```bash
php spark migrate:status
```

Debe mostrar 48 migraciones aplicadas y máximo batch 1. No ejecutar `migrate`, `rollback` ni seeders en esta release.

## Inicialización y contraseña administrativa

La base contiene un administrador local ficticio activo: ID 1, `admin@ikontrol20-clean.invalid`, `staff`, `is_admin=1`, rol administrativo implícito (`role_id=0`). Antes del primer acceso:

```bash
php spark admin:reset-password admin@ikontrol20-clean.invalid
```

El comando solicita dos veces la contraseña sin mostrarla, exige al menos 12 caracteres, usa `password_hash(PASSWORD_DEFAULT)` y no admite contraseña por argumento.

Limpiar caché después de configurar el entorno:

```bash
php spark cache:clear
```

## Smoke tests

1. `php spark list` y `php spark migrate:status` terminan correctamente.
2. La página de inicio responde por HTTPS sin detalle de excepciones.
3. El login administrativo funciona con la contraseña establecida en staging.
4. Clientes, productos, perfiles, series y documentos muestran cero registros.
5. Los diez catálogos SAT tienen datos.
6. No existen CSD, PAC, XML, PDF, mappings legacy ni DOLD.
7. Provocar sólo en una ruta controlada de QA un 404/500 y confirmar respuesta genérica y logs sin valores sensibles.
8. Confirmar que `fiscal.enabled=false` y que no existen llamadas salientes al PAC.

## Contenido del ZIP

Se incluyen:

- `app/`, `assets/`, `documentation/`, `files/`, `install1/`, `plugins/`, `resources/`, `system/`, `updates/`;
- `index.php`, `spark`, `README.md`, `.env.example`, `.gitignore`, `.gitattributes`.

Se excluyen:

- `.git/`, `.agents/`, `.build-check/`, `.env` y variantes locales;
- `tests/`, `tools/`, `docs/`, `public/soap-check.php`;
- todo `writable/`, incluido el ZIP y los respaldos;
- cualquier SQL raíz, especialmente `tws001_factucare.sql`;
- logs, sesiones, caché, reportes, temporales y scripts diagnósticos;
- certificados y archivos `.cer`, `.key`, `.pem`, `.pfx`, `.p12`, `.jks`;
- CSD, PAC, datos FC2 y DOLD.

La extracción de verificación contó 5,133 archivos y confirmó cero archivos prohibidos, cero coincidencias con valores secretos locales y cero coincidencias de `DOLD860620EW7`.

## Reversión

Antes de desplegar, conservar el ZIP y dump de la versión staging previa. Si falla el smoke test:

1. retirar tráfico o activar mantenimiento;
2. restaurar atómicamente el directorio de código anterior;
3. restaurar la base anterior desde su dump separado si la importación ya fue activada;
4. restaurar el `.env` de staging desde el gestor seguro, nunca desde el ZIP;
5. limpiar caché y repetir smoke tests;
6. conservar logs sanitizados y hashes de artefactos para el análisis.

No usar rollback de migraciones como mecanismo general de reversión.
