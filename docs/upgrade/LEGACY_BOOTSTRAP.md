# Legacy bootstrap para instancias anteriores al versionador

## Propósito y frontera de seguridad

El bootstrap permite que una instalación CodeIgniter funcional, anterior al agente de iKontrol, exponga diagnóstico, adopción y versionado dirigido. No convierte por sí mismo el código completo de la instancia a Platform y no reemplaza módulos del cliente.

La instalación es aditiva. Antes de escribir valida todos los checksums y todos los destinos. Si un destino ya existe con contenido distinto, devuelve `CONFLICT` y realiza cero escrituras. Si todos los destinos coinciden, devuelve `INSTALLED`. No contiene migraciones, SQL, DML, cambios de `.env`, operaciones Git ni acceso a PAC.

Los checksums son portables entre Windows y Linux. `CanonicalFileHasher` declara dos modos:

- `text-lf-sha256`: para `php`, `json`, `md`, `txt`, `csv`, `yml`, `yaml`, `xml`, `sql`, `js`, `css`, `html`, `htm`, `sh` y `ps1`; normaliza CRLF y CR a LF exclusivamente para calcular SHA-256.
- `binary-sha256`: para cualquier otra extensión; calcula SHA-256 sobre los bytes físicos sin normalización.

El archivo copiado conserva exactamente los bytes de la fuente. La normalización sólo define su identidad lógica en el manifest, la comparación del destino y el rollback-plan. La copia temporal se verifica además byte a byte para garantizar que el transporte local no alteró el archivo. `bytes_on_build_host` es informativo y puede variar por EOL; no participa en la validación. El recibo conserva el SHA-256 físico del manifest instalado como evidencia del artefacto concreto usado en esa ejecución.

El repositorio no tiene `.gitattributes`. Conviene definir EOL de manera global en un cambio posterior y aislado, pero agregarlo aquí podría provocar una normalización masiva de archivos históricos. La portabilidad del bootstrap no depende de `core.autocrlf` ni de reglas Git.

Navika/DOLD no es `UNREACHABLE`: PHP, CodeIgniter 4.6.1 y `spark` funcionan, pero el commit legacy `4daa416` no contiene los comandos del agente. Su clasificación correcta antes del bootstrap es `LEGACY_BOOTSTRAP_REQUIRED`.

## Grafo real de dependencias

```text
ikontrol:database-check
└── CodeIgniter Database + CLI

ikontrol:version
└── InstanceVersionService
    └── Config\\Version + app_schema_versions

ikontrol:baseline-check
└── IkontrolBaselineCheckService
    ├── BaselineCheckResult
    ├── CodeIgniter Database
    ├── app/Database/Migrations del destino (lectura)
    └── resources/fiscal/catalogs/sat/manifest.json (lectura opcional)

ikontrol:adopt-baseline
└── LegacyBaselineAdoptionService
    └── InstanceVersionService

ikontrol:upgrade:plan
└── InstanceUpgradeService::plan
    ├── InstanceVersionService
    └── Config\\Version

ikontrol:upgrade
└── InstanceUpgradeService::execute
    ├── InstanceVersionService
    ├── InstanceIdentityService
    ├── InstanceFeaturesService
    ├── SatCatalogInfrastructureService
    │   └── SatCatalogTextNormalizer
    ├── SatCatalogImporterService
    │   ├── SatCatalogInfrastructureService
    │   ├── SatCatalogTextNormalizer
    │   └── resources/fiscal/catalogs/sat/manifest.json + CSV declarados
    ├── FinancialAccountMovementsSchemaUpgrade
    ├── PaymentAllocationsSchemaUpgrade
    ├── ExpensesSchemaUpgrade
    └── PhysicalSchemaInspector
```

CodeIgniter descubre clases `App\\Commands` automáticamente. No se requiere editar rutas, `Autoload.php`, `Logger.php`, `.env`, `spark` ni archivos existentes si los destinos del manifest están libres. Un archivo distinto en cualquiera de esas rutas exige conciliación humana; el instalador nunca lo sobrescribe.

La lista exacta y sus SHA-256 viven en `resources/upgrade/legacy-bootstrap/manifest.json`. Se regenera desde la Base canónica con:

```bash
php tools/legacy-bootstrap/build-manifest.php --write
```

## Estados para iKontrolAdmin

Admin debe separar transporte, agente y adopción:

| Estado | Evidencia |
| --- | --- |
| `UNREACHABLE` | No puede ejecutar PHP/Spark, falla transporte/autenticación o el proyecto no inicia. |
| `LEGACY_BOOTSTRAP_REQUIRED` | `php spark list` funciona, pero `ikontrol:version` no existe. |
| `LEGACY_BOOTSTRAP_CONFLICT` | El dry-run del instalador encuentra rutas existentes diferentes o fuentes con checksum inválido. |
| `LEGACY_ADOPTABLE` | Bootstrap `INSTALLED`; `ikontrol:adopt-baseline --json` devuelve `READY_TO_ADOPT`. |
| `LEGACY_ADOPTION_BLOCKED` | Bootstrap instalado, pero el dry-run de adopción reporta blockers. |
| `MANAGED` | `ikontrol:version --json` devuelve una versión instalada. |

Un error `Command "ikontrol:version" not found` no debe mapearse a `UNREACHABLE` si `php spark list` terminó correctamente. Admin no debe ejecutar adopción ni upgrades automáticamente en esta fase.

## Preparación del paquete

Usar una copia limpia del release canónico 1.1.4 como fuente temporal. No cambiar el remote de Navika.

```bash
cd /ruta/temporal/ikontrol-platform-1.1.4
php tools/legacy-bootstrap/build-manifest.php
php tools/legacy-bootstrap/install.php \
  --source=/ruta/temporal/ikontrol-platform-1.1.4 \
  --target=/home/tws001/navika.ikontrol.solutions \
  --json
```

El primer comando imprime el manifest calculado para compararlo con el versionado. El segundo es dry-run. Debe devolver `READY_TO_INSTALL` o `INSTALLED`, `conflicts: []` y `source_errors: []`. Revisar individualmente `create`; no ejecutar si aparece un conflicto.

Antes de aplicar, respaldar por el procedimiento operativo habitual la base y el árbol de código. No incluir ni mover `files/system`, `files/timeline_files`, `writable`, `.env` o uploads en el paquete.

## Aplicación controlada en Navika

Sólo después de aprobar el dry-run:

```bash
php /ruta/temporal/ikontrol-platform-1.1.4/tools/legacy-bootstrap/install.php \
  --source=/ruta/temporal/ikontrol-platform-1.1.4 \
  --target=/home/tws001/navika.ikontrol.solutions \
  --execute --yes --json
```

El instalador crea un recibo en:

```text
/home/tws001/navika.ikontrol.solutions/.ikontrol-legacy-bootstrap/legacy-bootstrap-1.1.4.2.json
```

Después, desde Navika:

```bash
cd /home/tws001/navika.ikontrol.solutions
php spark ikontrol:database-check
php spark ikontrol:version --json
php spark ikontrol:baseline-check --json
php spark ikontrol:adopt-baseline --json
```

`baseline-check` puede reportar faltantes operativos y seguir siendo útil. La adopción usa su contrato mínimo propio: core RISE, administrador activo, baseline administrativo y ausencia de evidencia iKontrol posterior.

Si la adopción devuelve `READY_TO_ADOPT`, ejecutar explícitamente:

```bash
php spark ikontrol:adopt-baseline --execute --yes --json
php spark ikontrol:version --json
php spark ikontrol:upgrade:plan --target=1.1.4 --json
```

No ejecutar todavía `ikontrol:upgrade` en Navika hasta reconciliar el código cliente con Platform y suministrar los artefactos SAT oficiales. En el estado actual del repositorio, `resources/fiscal/catalogs/sat/manifest.json` no declara catálogos activos; el paso `sat_catalog_import` abortaría de forma segura.

## Rollback

Antes de adoptar 1.0.0, el rollback consiste en retirar únicamente los archivos enumerados en `created_files` del recibo, después de verificar que su SHA-256 todavía coincide con el manifest. No retirar entradas `identical`, porque ya existían. No eliminar directorios compartidos. Restaurar el respaldo de código si cualquier archivo creado fue modificado después de instalarlo.

Obtener el inventario verificable, sin borrar nada:

```bash
php /ruta/temporal/ikontrol-platform-1.1.4/tools/legacy-bootstrap/install.php \
  --source=/ruta/temporal/ikontrol-platform-1.1.4 \
  --target=/home/tws001/navika.ikontrol.solutions \
  --rollback-plan --json
```

Sólo un resultado `SAFE_TO_ROLL_BACK` permite retirar manualmente las rutas de `removable`. `MANUAL_REVIEW_REQUIRED` significa que al menos un archivo cambió después del bootstrap y debe restaurarse desde el respaldo, no eliminarse.

Después de adoptar 1.0.0, no borrar manualmente el registro de versión. Restaurar el respaldo transaccional de base y código tomado antes del cambio. La adopción sólo añade una fila a `app_schema_versions`, pero el respaldo sigue siendo la ruta canónica de recuperación.

En ambos casos deben permanecer intactos:

- `app/Config/Logger.php`;
- `.env` y secretos;
- `.git/config` y remotes;
- `files/system/`;
- `files/timeline_files/`;
- `writable/`, uploads y branding;
- tablas y datos comerciales/fiscales.

## Pruebas

```bash
php tests/LegacyBootstrapInstaller/run.php
php tests/LegacyBaselineAdoption/run.php
```

La primera suite valida manifest/checksums, dry-run, instalación aditiva, recibo, idempotencia, bloqueo previo por conflictos y preservación byte a byte de archivos operativos. La segunda valida compatibilidad, adopción 1.0.0 y el plan completo hasta 1.1.4 usando esquemas MySQL temporales.
