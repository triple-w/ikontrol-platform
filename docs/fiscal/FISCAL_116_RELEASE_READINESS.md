# iKontrol Platform 1.1.6 — preparación técnica fiscal

Fecha de corte: 2026-11-02
Estado global: **NO LISTO PARA PUBLICAR**

La revalidación focalizada encontró y corrigió un falso rechazo del upgrade dirigido: CodeIgniter conservaba metadatos de columnas antes del `ALTER TABLE`. La inspección ahora consulta `information_schema` sin caché; el runner dirigido pasó 21 aserciones en dos esquemas MySQL locales desechables, incluyendo columnas, invariantes e idempotencia. La publicación continúa bloqueada: el árbol tiene cambios locales, no existe `v1.1.6`, no se generó paquete, Smartfree no fue inspeccionada y hay suites bloqueadas por el entorno.

## Implementado

### Integración A/B/C

- Productos, formulario, listado y preflight comparten `ProductFiscalReadinessService`; los errores identifican concepto y campo, incluidos descripción, unidad, ObjetoImp, impuestos, redondeo y total.
- Los métodos administrativos se validan por ID contra exactamente un mapping activo y una forma SAT activa. PPD conserva forma `99`; PUE usa la forma efectiva validada.
- `FiscalRuntimeContext` sigue siendo la autoridad de ambiente lógico/transporte. Emisor, serie, PAC, PDF, wallet, créditos, consulta SAT y cancelación conservan el ambiente congelado del documento.
- `FiscalSeriesEnvironmentService` concentra la selección por emisor y ambiente. Además, `FiscalFolioService::reserveNextFolio()` ahora aplica el mismo ambiente bajo lock que preview/default; una serie de otro ambiente no consume folio.
- Consulta SAT y consulta de cancelación comparten el adaptador existente, persistencia durable, lock por documento, idempotencia, cooldown, evidencia con hash y estados normalizados.
- El selector de ambiente continúa en modo preview-only, con permiso/CSRF/auditoría; la feature flag permanece deshabilitada en producción y no existe driver de activación distribuido.

### Upgrade dirigido

`InstanceUpgradeService` incorpora el paquete `ikontrol-1.1.6-fiscal-unification`, exclusivamente desde 1.1.5, en este orden:

1. `fiscal_116_schema`.
2. `fiscal_116_verify`.
3. `record_version`.

El paso de esquema crea de forma aditiva y respetando `DBPrefix`:

- `fiscal_environment_change_requests`.
- `fiscal_sat_status_queries`.
- `fiscal_sat_status_artifacts`.
- `global_periodicity`, `global_months` y `global_year` en `fiscal_drafts` y `fiscal_documents`.

No usa el runner global de migraciones. Antes de registrar 1.1.6 verifica columnas e índices físicos mediante `information_schema` sin depender de la caché de metadatos de CodeIgniter, genera un fingerprint y conserva evidencia por step. Una tabla parcial/personalizada falla cerrada; 1.1.6 no se registra. La reejecución controlada de un paquete completado es idempotente.

`Fiscal116SchemaContract` centraliza el esquema mínimo previo consumido por A/B/C: documentos/evidencias/cancelaciones, perfiles, series, wallets, formas de pago y configuración fiscal de productos. Incluye `fiscal_series.environment`; el upgrade no intenta convertir series `legacy`, alterar índices, reparar folios ni suplir una 1.1.5 con esquema histórico incompleto.

### Manifest y builder

- Versión canónica: `1.1.6`, release ref prevista `v1.1.6`.
- Manifest lógico: `updates/1.1.6/manifest.json`.
- Origen único soportado: `1.1.5` en el commit `3c4a65d00d833743378ce526da7e5891da7509f9`.
- Migraciones requeridas: las tres migraciones aditivas del 2026-10-22, incluidas las columnas de información global CFDI.
- Backup obligatorio y health checks `ikontrol:database-check`, `ikontrol:baseline-check` e `ikontrol:fiscal-116-readiness`.
- El builder rechaza un manifest cuya migración requerida no exista en el árbol etiquetado.

### Consolidación Platform/RC1

La comparación SHA-256 previa cubrió 229 archivos fiscales/comerciales: 227 coinciden y ninguno falta en RC1. Las únicas divergencias son `app/Controllers/Fiscal/InvoiceReview.php` y `app/Services/PaymentComplementDraftService.php`; RC1 conserva variantes distintas, por lo que no se copiaron sobre Platform. El examen no incorporó archivos privados, configuración sandbox, certificados, documentos ni datos de RC1.

El commit base 1.1.5 declarado por el manifest es `3c4a65d00d833743378ce526da7e5891da7509f9`. Se verificó que `v1.1.5`, `main`, `origin/main` y `HEAD` apuntan a ese mismo commit. No existe `v1.1.6` y no se movió ni reemplazó ningún tag.

### Comparación read-only por instancia

`php spark ikontrol:fiscal-116-readiness` genera evidencia no secreta de:

- versión registrada/canónica y ruta dirigida;
- SHA-256 de archivos fiscales relevantes;
- fingerprint de tablas, columnas e índices;
- esquema nuevo y prerrequisitos;
- contexto fiscal sanitizado;
- series agrupadas por ambiente;
- cardinalidad emisor/ambiente de wallets, sin saldos ni RFC;
- cardinalidad de mappings activos;
- blockers explícitos.

No recolecta XML, API keys, CSD, llaves privadas, contraseñas ni datos personales. Ninguna instancia fue consultada durante este trabajo.

La limitación de ese comando —sólo existe una vez instalado el código 1.1.6— queda cubierta por el bundle previo independiente de `updates/1.1.6/preflight/`. iKontrolAdmin debe ejecutarlo desde staging contra la instalación 1.1.5, antes de aplicar archivos o migraciones. El contrato, estados y procedimiento por instancia están en `docs/fiscal/FISCAL_116_INSTANCE_COMPATIBILITY.md`.

## Probado

Todos los runners ejecutados fueron inspeccionados primero. Usan SQLite `:memory:`, lógica pura/estática o esquemas MySQL locales de nombre aleatorio con eliminación restringida. Los adaptadores son fake/stub/captura y no existe transporte PAC/SAT real.

| Suite | Resultado | Cobertura relevante |
| --- | ---: | --- |
| `FiscalPreflight116` | PASS 19 / FAIL 0 | Productos, impuestos, redondeos, PUE/PPD, mappings, series y snapshots. |
| `FiscalEnvironment116` | PASS 26 / FAIL 0 | Runtime, PAC, endpoints, opt-in, wallet, selector, permisos y ambiente histórico. |
| `FiscalSatStatus116` | PASS 23 / FAIL 0 | Estados SAT/cancelación, idempotencia, locks, evidencia, auditoría y costo. |
| `Fiscal116DirectedUpgrade` | PASS 21 / FAIL 0 (2026-11-02) | Prefijos, preflight compatible, tres migraciones, columnas globales, invariantes, reejecución, fallo parcial y no registro prematuro. |
| `Fiscal116ReleaseMetadata` | PASS 11 / FAIL 0 (2026-11-02) | Versión, manifests, origen, tres migraciones, health checks y guard del builder. |
| `FiscalFakePdfLab` | PASS 11 / FAIL 0 (2026-11-02) | Adaptador PDF local de laboratorio, marca simulada y separación de PAC/PDF. |
| `FiscalStampingLifecycle` | PASS 26 / FAIL 0 | Estados PAC, retry/conciliación, UUID y ledger. |
| `FiscalStampingBoundary` | PASS 6 / FAIL 0 | Consistencia antes de firma/PAC y cierre posterior al UUID. |
| `FiscalPreparedDocumentLifecycle` | PASS 11 / FAIL 0 | Hashes, snapshots, stamped e incertidumbre PAC. |
| `FiscalEnvironment` | PASS 16 / FAIL 0 | Contexto y transporte HTTP capturado, sin red. |
| `FiscalPdfRegeneration` | PASS 15 / FAIL 0 | XML autoritativo, plantillas, artifacts y ausencia de efectos fiscales. |
| `PaymentComplementsCanonical` | PASS 28 / FAIL 0 | Pago 2.0 interno/externo/mixto, impuestos, snapshot e idempotencia. |
| `FiscalSeriesPdfCanonical` | PASS 35 / FAIL 0 | Series/folios por ambiente, CFDI I/E/P, nota de crédito, XML/PDF histórico. |
| `FiscalStampBalanceCanonical` | PASS 17 / FAIL 0 | Wallet por identidad/ambiente, saldo y ledger. |
| `CanonicalStampBalanceAndSaleStatus` | PASS 19 / FAIL 0 | Wallet canónica, cancelación y estados comerciales. |
| `FiscalOnboardingUx` | PASS 11 / FAIL 0 | Emisor/serie, blockers y PAC real bloqueado. |
| `ReleaseArtifact` | PASS 23 / FAIL 0 | Tag/ref, exclusiones, hashes, tamper, symlinks y ZIP reproducible en fixture. |
| `Fiscal116InstanceCompatibility` | PASS 13 / FAIL 0 (2026-11-02) | Bundle verificado, consulta `query_only`, fingerprints y estados de compatibilidad; SQLite en memoria. |
| `FiscalGlobalInformation116` | PASS 30 / FAIL 0 (2026-11-02) | Reglas de receptor genérico, periodo, XML/XSD y persistencia de Información Global. |
| `FiscalPaymentContext116` | PASS 22 / FAIL 0 (2026-11-02) | Formas SAT/métodos administrativos y readiness de complementos; SQLite en memoria. |
| `FiscalCommercialIssuanceTransition` | PASS 7 / FAIL 0 (2026-11-02) | Transición comercial aislada, sin PAC/SAT. |
| `FiscalDraftSnapshotIntegrity` | PASS 7 / FAIL 0 (2026-11-02) | Integridad de snapshots; SQLite en memoria. |
| `FiscalCancellationTargetReadiness116` | PASS 10 / FAIL 0 (2026-11-02) | Cancelación sin readiness global, validación y frontera previa al transporte. |
| `FiscalCancellationCycle116` | PASS 17 / FAIL 0 (2026-11-02) | SOAP sintético inyectado; cero red y cero base de datos. Incluye cancelación administrativa de ventas. |
| `FiscalCancellationWallet116` | PASS 7 / FAIL 0 (2026-11-02) | Cargo idempotente con esquema MySQL local desechable. |
| `FiscalStampingBoundary` | PASS 6 / FAIL 0 (2026-11-02) | Gate de consistencia antes de firma y PAC; reintento posterior al UUID. |
| `CanonicalStampBalanceAndSaleStatus` | PASS 19 / FAIL 0 (2026-11-02) | Saldo canónico, pagos y estados comerciales; fixture sintético local. |
| `FiscalCancellationModal116` | PASS 11 / FAIL 0 | Estados AJAX, errores HTTP/red y bloqueo de reenvío. |

El PHP de XAMPP no carga `sqlite3` por defecto, pero `C:\xampp\php\ext\php_sqlite3.dll` está disponible. Las suites SQLite se ejecutaron con `php -d extension=php_sqlite3.dll`; no se modificó `php.ini`. Los runners de preflight/consulta SAT fijan `IKONTROL_RC1_LAB` y `IKONTROL_RC1_SANDBOX_TRANSPORT` a `false` antes de cargar el bootstrap, para que la configuración local no determine el modo de una prueba automatizada. PAC/SAT quedó simulado o deshabilitado.

`Fiscal116FunctionalDefects` pasó 26 aserciones e incluye reconciliación de reportes de facturas y totales. No se ejecutó `CanonicalPayments`: depende de `tests/Increment02/isolated_database.php`, que no demuestra aislamiento de la base configurada. La prueba funcional segura sí verificó reportes sintéticos. Dos fallos iniciales fueron fixtures/assertions desactualizados, no fallos de runtime: se alineó el fixture de métodos de pago con `available_on_invoice` y el gate de stamping con las llamadas reales; ambas suites pasan después del ajuste.

Durante la integración `FiscalSeriesPdfCanonical` detectó dos contratos de fixture antiguos (`fiscal_series.environment` y `fiscal_documents.environment`). La primera falla reveló además que la reserva de folio no filtraba ambiente; se corrigió el servicio y se añadieron casos que demuestran que una reserva cruzada es rechazada sin alterar `current_folio`. La suite completa pasó después de alinear su esquema sintético con el contrato de 1.1.5.

## Pendiente de validar en instancia

| Instancia | Riesgo principal | Evidencia obligatoria antes de instalar |
| --- | --- | --- |
| Golden | Antecedente de rollback de código con BD adelantada. | Backup verificable, versión exacta 1.1.5, hashes sin personalizaciones, fingerprint, prueba de fallo/recuperación y confirmación de que `record_version` sólo ocurre después del health check. |
| Smartfree | Productos/mappings/series/PAC/wallet reportados inconsistentes. | Diagnóstico read-only, filas afectadas, series por ambiente, cardinalidad de mappings, emisor-wallet y preflight sin secretos. No reparar automáticamente. |
| Navika/DOLD | Operación fiscal productiva ya funcional y posibles capacidades particulares. | Comparación de hashes y esquema, inventario de personalizaciones, series/folios productivos, emisor-wallet, golden paths con copia anonimizada y adaptadores fake para I/E/P, PDF y cancelación. Cualquier divergencia bloquea sobrescritura. |

Compartir `1.1.5` no demuestra igualdad de archivos, esquema ni datos. Una personalización o fingerprint inesperado requiere revisión y un paquete/plan explícito; nunca se sobrescribe por inferencia.

## Bloqueado

1. No se ha integrado aún el runner externo restringido en iKontrolAdmin ni se ha ejecutado el diagnóstico previo en Golden, Smartfree o Navika, ni sobre copias anonimizadas aprobadas.
2. No se ha probado el upgrade sobre una copia representativa 1.1.5 de cada instancia.
3. Las suites que dependen de `tests/Increment02/isolated_database.php` no se ejecutaron: ese helper puede copiar la base configurada y no demuestra aislamiento. Incluye `PaymentComplementStamping`, `PaymentComplementsFiscalUx`, `CreditNoteFiscalSquare`, `CommercialFiscalFlowRegression`, `PaymentComplementCancellation`, `FiscalSaleConsistency` y `FiscalStampCommercialControl`. La cobertura aislada equivalente disponible sí pasó, pero no sustituye la validación de divergencias reales.
4. Falta confirmación contractual del costo de `consultarEstadoSAT`. Se conserva `unconfirmed_no_charge` sin descuento local; cancelación y consulta de cancelación conservan la política comercial existente. No se añadieron tarifas.
5. El selector sigue siendo preview-only. No debe anunciarse como activación funcional.
6. El tag `v1.1.6` no existe y esta tarea no autoriza crearlo. El builder formal exige un checkout limpio y un tag que resuelva al commit candidato; no se generó directorio ni ZIP, por lo que no hay hash de paquete que reportar. `ReleaseArtifact` pasó 23/23, incluida reproducibilidad byte-a-byte en fixtures, pero eso no sustituye un artefacto oficial desde el tag.

## Procedimiento de prueba 1.1.5 → 1.1.6

1. En una copia aislada/anonimizada, registrar commit desplegado, versión en `app_schema_versions`, hashes fiscales, fingerprint, prefijo y personalizaciones. Ejecutar `php spark ikontrol:upgrade:plan 1.1.6` y `php spark ikontrol:fiscal-116-readiness`; en pre-upgrade se espera `UPGRADE_REQUIRED`, no `READY`.
2. Bloquear si el origen no es exactamente 1.1.5, faltan prerrequisitos, hay wallets ambiguas, archivos modificados no explicados o no existe backup restaurable de código, BD y artifacts.
3. Restaurar esa copia en un entorno sin salida PAC/SAT, con credenciales no reales/adaptadores fake. Nunca usar la base productiva como destino del runner.
4. Aplicar el paquete dirigido con `php spark ikontrol:upgrade 1.1.6 --yes`. No ejecutar `php spark migrate`.
5. Verificar que los tres steps estén `completed`, que las tablas tengan el prefijo esperado, columnas/índices exactos, cero filas inventadas y que 1.1.6 sólo aparezca después de `fiscal_116_verify`.
6. Ejecutar los tres health checks y guardar JSON/fingerprints. Probar A/B/C y los golden paths aislados de factura PUE/PPD, complemento, nota, PDF/XML, wallet, cancelación y consulta SAT.
7. Comparar conteos/hashes de documentos, UUID, artifacts, wallets, movimientos, certificados y folios antes/después; deben permanecer invariantes salvo el esquema aditivo y los registros técnicos generados deliberadamente por la prueba.
8. Simular un fallo entre schema y verify. Debe quedar evidencia `failed`, no versión 1.1.6, y debe poder reanudarse tras corregir la causa.
9. Recuperación: si falla cualquier check, no habilitar tráfico. Restaurar conjuntamente código, BD y artifacts desde el mismo punto. No repetir el patrón de Golden de restaurar sólo código después de avanzar BD. Las tablas nuevas son aditivas, pero su conservación o retiro debe decidirse con evidencia; la recuperación garantizada es el backup consistente.
10. Sólo después de aprobar las tres matrices de instancia, crear commit/tag, construir dos veces y comparar SHA-256/manifest interno. La prueba de reproducibilidad actual valida el builder con fixture, no el ZIP oficial 1.1.6.

## Inventario de preparación de release

- Nuevos: migraciones B/C, servicios/controladores/vistas A/B/C, comando y servicio de readiness, runners 1.1.6, manifest y spec del builder.
- Modificados: configuración/rutas/permisos, readiness y materialización fiscal, ambiente/PAC/wallet/PDF, cancelación/consulta, series/folios, upgrade dirigido y builder.
- Sin cambios históricos automáticos: CFDI, XML, UUID, sellos, certificados, snapshots, saldos, movimientos y folios.
- Sin efectos externos: no hubo despliegue, llamada PAC/SAT, cambio de ambiente, commit, push, tag ni release.

## Smartfree: siguiente ensayo aislado

Smartfree no se conectó ni se inspeccionó. El bundle previo es de sólo lectura y su comando previsto, después de copiar el bundle verificado a staging, es:

```powershell
php C:\ruta\staging\updates\1.1.6\preflight\inspect.php --root="C:\ruta\copia-smartfree-1.1.5" --pretty
```

El modo agregado es el punto inicial. Para un documento puntual, confirmar que existe en la copia y usar sólo el identificador autorizado por su responsable. Conservar el JSON delimitado por `IKONTROL_COMPATIBILITY_JSON_BEGIN/END`, verificar el hash del bundle, investigar cada `blocker`, `review_required` y `not_verified`, y no instalar si no queda `COMPATIBLE` o una excepción aprobada por responsable. El inspector no cubre por sí solo la integridad completa de documentos históricos ni acepta funcionalmente cancelación, consulta SAT o PDF; esas comprobaciones siguen requiriendo ensayo en copia anonimizada.

### Plan para Smartfree Staging

1. Crear copia aislada/anonimizada de Smartfree 1.1.5, sin rutas ni credenciales de producción; registrar versión, commit, prefijo y configuración saneada.
2. Ejecutar el inspector anterior y guardar JSON y hashes. Resolver diferencias de código, esquema, wallet, mapping o runtime antes de continuar; no reparar automáticamente.
3. Respaldar juntos archivos, base y artifacts de la copia; restaurarlos en un entorno de ensayo y comprobar la integridad de cada respaldo. Mantener el tráfico comercial y fiscal detenido durante la actualización.
4. Sólo después de aprobar un paquete etiquetado, aplicar sus archivos y ejecutar el upgrade dirigido `php spark ikontrol:upgrade 1.1.6 --yes`; no ejecutar `php spark migrate`, seeders ni inicialización. Deshabilitar PAC/SAT externos y usar fake/stub.
5. Ejecutar los health checks, golden paths sintéticos y comparación de invariantes descritos arriba. Si falla cualquier check, detenerse y restaurar conjuntamente código, base y artifacts desde el mismo backup. Conciliar toda operación externa que hubiera ocurrido; el rollback local no revierte PAC/SAT.

Conclusión: la integración local y el mecanismo técnico están implementados y probados en aislamiento, pero la release permanece **NO LISTA** hasta cerrar los bloqueos de instancia, contrato y build etiquetado.
