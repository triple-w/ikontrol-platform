# Smartfree pre-deploy readiness

Fecha de validación local: 2026-10-01. Fuente: copia aislada `ikontrol_test_smartfree_bridge_20261001`. No se usó producción, DOLD, PAC real ni `spark migrate` global.

## Estado

El bridge conserva 315 clientes, 273 productos, 1,301 facturas y 1,418 pagos. Continúa con cero CFDI históricos y cero intentos de timbrado. `fiscal_enabled=1` declara ante el baseline que la estructura fiscal debe existir. La visibilidad administrativa continúa gobernada por permisos; el servidor debe configurar `fiscal.enabled=true` y conservar `fiscal.stampingEnabled=false` y `fiscal.allowRealPac=false` durante onboarding.

`FiscalOnboardingReadinessService` proyecta `NOT_INSTALLED`, `ONBOARDING` o `READY` sin persistir un segundo state machine. La pantalla `/fiscal/onboarding` muestra emisor, CSD, series, PAC, catálogos, productos, clientes, métodos de pago y el bloqueo de timbrado. El guard fiscal compartido exige `READY` antes de timbrar o cancelar, y antes de crear adaptador, intento, reserva o transporte PAC.

La copia validada está en `ONBOARDING`: emisor incompleto, CSD ausente, series ausentes, PAC sin credencial, catálogos SAT vacíos, 273 productos y 315 clientes pendientes de configuración fiscal, y mappings SAT de métodos de pago pendientes. Los métodos legacy 1, 6, 7 y 8 conservan sus IDs y ahora están disponibles para configurar; no se inventó ningún mapping SAT.

## Controles auditados

- La visibilidad de emisores, CSD, series y onboarding está en `app/Views/settings/tabs.php` y en los permisos de sus controladores. No depende de habilitar el transporte PAC.
- `app/Config/Fiscal.php` mantiene separados `fiscal.enabled`, `fiscal.stampingEnabled` y `fiscal.allowRealPac`; `FiscalRuntimeContext` resuelve coherencia de ambiente, endpoint y credencial.
- `FiscalOnboardingReadinessService` reúne el emisor, `CsdOperationalStatusService`, series, salud canónica de los diez catálogos SAT, productos, clientes y mappings de métodos de pago.
- `FiscalPreviewModeGuard` es la compuerta compartida por `FiscalStampingService` y `FiscalCancellationService`. Ambos rechazan una operación si el servidor la deshabilita o si onboarding no es `READY`.
- Los perfiles de emisor/receptor, configuración fiscal de productos y mappings de métodos de pago reutilizan las tablas y pantallas existentes; no se creó un segundo modelo persistido de estados.

## UI administrativa

Las rutas, controladores, permisos y tablas necesarios están presentes para login, dashboard, clientes, productos, facturas, pagos, emisores, CSD, series, configuración fiscal de productos/clientes, métodos de pago y readiness. La ruta nueva usa `fiscal_pac_status_view` o administración. Las mutaciones existentes conservan POST/CSRF. La validación local cubrió carga de servicios, dependencias de schema y permisos; no se automatizó una sesión HTTP autenticada con navegador.

## Configuración de onboarding

Conservar en el `.env` de la instancia, sin compartir secretos:

```dotenv
fiscal.enabled=true
fiscal.runtimeMode=integration
fiscal.environment=development
fiscal.pacAdapter=timbradorxpress
fiscal.stampingEnabled=false
fiscal.allowRealPac=false
TIMBRADORXPRESS_ENVIRONMENT=sandbox
TIMBRADORXPRESS_PRODUCTION_ENABLED=false
```

Después configurar, en este orden: catálogo SAT verificado, mappings SAT de métodos legacy, emisor/RFC/régimen/CP, llave local CSD, CSD y contraseña, series de ingreso/pago/egreso, productos, clientes, credencial PAC sandbox y saldo de timbres. Sólo tras obtener `READY` puede evaluarse habilitar sandbox. Producción requiere una revisión independiente, credencial production y opt-in explícito.

## Despliegue dirigido

Con la aplicación en mantenimiento y variables reemplazadas por valores revisados:

```powershell
& C:\xampp\mysql\bin\mysqldump.exe -uroot --single-transaction --routines --triggers tws001_smartfree > C:\backups\tws001_smartfree_before_ikontrol.sql
php spark ikontrol:legacy-upgrade:smartfree --database=tws001_smartfree --template-database=ikontrol20_clean --dry-run --json
php spark ikontrol:legacy-upgrade:smartfree --database=tws001_smartfree --template-database=ikontrol20_clean --execute --yes --json
php spark ikontrol:baseline-check --json
```

No ejecutar si el dry-run no coincide con el fingerprint certificado. No usar `php spark migrate`.

Preservar `.env`, `files/`, uploads/logos, `writable/fiscal-private`, llaves de cifrado, certificados CSD y el backup previo. No copiar `.env`, CSD o datos fiscales entre clientes.

## Rollback

Mantener la aplicación en mantenimiento. Restaurar el backup completo, no ejecutar `down()`:

```powershell
& C:\xampp\mysql\bin\mysql.exe -uroot -e "DROP DATABASE IF EXISTS tws001_smartfree_rollback; CREATE DATABASE tws001_smartfree_rollback CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"
Get-Content -Raw C:\backups\tws001_smartfree_before_ikontrol.sql | & C:\xampp\mysql\bin\mysql.exe -uroot tws001_smartfree_rollback
```

Validar la restauración separada y cambiar la configuración hacia ella. No sobrescribir la base fallida hasta conservarla para diagnóstico.

## Resultados

- Smartfree pre-deploy: 15/15 PASS.
- Smartfree bridge idempotence/reconciliation: 1/1 PASS.
- BaselineCheck aislado: 13/13 PASS.
- Guards PAC/fiscal: 8/8 PASS; transporte fake confirmado y cero PAC real.
- Fiscal stamping boundary: 6/6 PASS.
- Fiscal stamping lifecycle: 26/26 PASS.
- Fiscal environment sandbox/production MOCK: 16/16 PASS.
- Superficie HTTP, permisos y CSRF: 24/24 PASS.
- Servicios compartidos: 16/16 PASS.
- Baseline sobre copia bridgeada: 8 PASS, 1 WARN esperado por no usar historial global de migraciones y 1 FAIL esperado por catálogos SAT vacíos.
- Reconciliación: conteos y sumas certificados sin cambios; cero documentos e intentos fiscales.

Las suites `CommercialFiscalFlowRegression` y `CreditNotes` no forman parte del gate de este pre-deploy: sus runners requieren fixtures fiscales propios (owner/company y una factura ya timbrada) que no existen deliberadamente en la copia Smartfree. El fallo de precondición ocurrió antes de ejecutar dominio o PAC y no modificó datos.

## Gates

SMARTFREE DEPLOYMENT: **GO**

SMARTFREE FISCAL ONBOARDING: **GO**

SMARTFREE PRODUCTION STAMPING: **NO-GO**

El NO-GO de timbrado se mantiene hasta catálogo verificado, onboarding completo, readiness `READY`, pruebas sandbox y aprobación operativa de producción.
