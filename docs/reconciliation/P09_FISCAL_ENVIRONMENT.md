# P09 — Ambiente fiscal canónico

Fecha de cierre: 2026-09-29. Estado: implementado y probado; no desplegado. No se ejecutó PAC real ni se modificó schema.

## Contrato resultante

`FiscalRuntimeContext` es la autoridad operativa que normaliza y valida:

- modo fiscal público `sandbox|production`;
- ambiente lógico persistido `development|production`;
- transporte TimbradorXpress `sandbox|production`;
- endpoint permitido, presencia de credencial, habilitación explícita de production y `allowRealPac`;
- coherencia y disponibilidad antes de construir un adaptador.

`fiscal.environment` es la selección canónica. `sandbox`, `local` y `development` se normalizan a `development`; `production` permanece `production`. `CI_ENVIRONMENT` y `Fiscal.runtimeMode` conservan su función de runtime y seguridad de fixtures, pero no eligen endpoint, credencial ni ambiente fiscal.

`TimbradorXpress` deriva el transporte desde `fiscal.environment`. `TIMBRADORXPRESS_ENVIRONMENT` queda aceptado sólo como validación de compatibilidad: si existe y contradice la fuente canónica, la configuración falla. Ya no se publica como selector en `.env.example`.

| Modo fiscal | Ambiente persistido | Transporte | Endpoint |
| --- | --- | --- | --- |
| sandbox | development | sandbox | `https://dev.timbradorxpress.mx/api/rest/servicio/` |
| production | production | production | `https://app.timbradorxpress.mx/api/rest/servicio/` |

Las credenciales siguen fuera del repositorio y se eligen mediante `TIMBRADORXPRESS_APIKEY_SANDBOX` o `TIMBRADORXPRESS_APIKEY_PRODUCTION` según el ambiente derivado.

## Consumidores reconciliados

- Factory y adaptadores de timbrado/cancelación usan el contexto antes del transporte. Sandbox y production construyen `TimbradorXpressRestAdapter`; sólo cambia configuración.
- Preflight valida que draft y serie pertenezcan al ambiente lógico activo.
- El servicio de timbrado rechaza un documento cuyo ambiente persistido no coincida antes de crear el intento PAC.
- Diagnóstico resuelve emisor, serie, receptores, endpoint y readiness para el ambiente activo.
- Invoice flow, cancelaciones, saldo local y consulta de créditos PAC usan la cuenta/snapshot del ambiente lógico correspondiente.
- Topbar muestra la advertencia de sandbox por el ambiente fiscal, independientemente del runtime de la app.
- `fiscal:integration:prepare` se conserva como herramienta exclusiva de datos sandbox/development; usa el contexto para sus guardas y no prepara production.

Los endpoints permanecen allowlisted. Production exige simultáneamente módulo fiscal habilitado, `allowRealPac`, credencial del ambiente, endpoint exacto y `TIMBRADORXPRESS_PRODUCTION_ENABLED=true`. Sandbox conserva los mismos guards salvo el opt-in específico de production. El adaptador fake sigue restringido a pruebas CLI.

## Persistencia y compatibilidad

No se reconstruyen históricos. `fiscal_documents`, drafts, series, perfiles y cuentas de timbres conservan `development|production`; los intentos PAC conservan el ambiente de transporte `sandbox|production`. Las consultas de wallet derivan el ambiente lógico del documento o del contexto, evitando mezclar saldos.

El alias `local` se acepta al normalizar configuración antigua, pero la configuración canónica nueva usa `development`. No se creó migración porque los campos requeridos ya existen.

## Validación aislada

- `php tests/FiscalEnvironment/run.php`: 16/16. Cubre app production + fiscal sandbox, app testing + sandbox, sandbox/production MOCK, endpoints, credenciales, guards, factory/pipeline común, diagnóstico/consumidores y ausencia de `CI_ENVIRONMENT` como selector. El cliente HTTP fue un objeto en memoria; hubo cero requests reales.
- `php tests/SharedDomainServices/run.php`: 16/16.
- `php tests/IncrementA1/run.php`: 8/8, actualizado al contrato production con guard explícito.
- `php tests/Increment09/run.php`: 25/25.
- Sintaxis PHP de todos los archivos P09: aprobada.

## Pendientes de P10

P09 no cambia reintentos, conciliación de resultados inciertos, invalidación de documentos preparados, locks avanzados ni lifecycle de wallet. La habilitación de production hace más visible el riesgo ya existente: cualquier `unknown` o posible envío debe continuar bloqueando reenvío automático hasta que P10 cierre la conciliación. Antes de despliegue real también deben configurarse secretos, CSD, perfiles, series y saldos separados por ambiente.
