# Control comercial de timbres

## Alcance

Esta fase agrega una cuenta entera por perfil fiscal emisor, un ledger inmutable, reservas previas al transporte PAC, consumos, liberaciones, conciliación y ajustes controlados por CLI. No habilita PAC, venta automática de paquetes ni pagos. La migración queda pendiente y no debe aplicarse a bases reales sin autorización.

## Reglas

- Un CFDI aceptado consume un timbre. Factura, egreso y complemento usan la misma regla porque el consumo se liga al `fiscal_document_stamp` creado.
- Un rechazo definitivo o un error confirmado antes del envío libera la reserva y consume cero.
- Un timeout, duplicado informado o respuesta incierta conserva la reserva hasta conciliación.
- Una cancelación aceptada consume otro timbre independiente. Una cancelación rechazada consume cero; una incierta conserva su propia reserva.
- Repetir un timbrado o cancelación ya resuelto no crea otro consumo. Los consumos únicos son `stamp:<stamp_id>` y `cancellation:<request_id>`.
- Consultas SAT, XML/PDF y generación de PDF no llaman al servicio comercial.
- Los movimientos no se actualizan ni eliminan desde el dominio. Una corrección genera otro movimiento.

## Modelo

`fiscal_stamp_accounts` mantiene `available_balance` y `reserved_balance` como `INT UNSIGNED`, estado `active|suspended` y una fila única por `issuer_profile_id`.

`fiscal_stamp_movements` conserva cantidades enteras, saldos antes/después, documento, intento PAC, stamp, solicitud de cancelación, actor, razón, referencia e identificadores idempotentes. `consumption_key` es nullable y unique, compatible con MySQL sin índice parcial. No se agregó FK al perfil porque el esquema actual usa `INT UNSIGNED` y la cuenta solicitada usa `BIGINT UNSIGNED`; se preservó compatibilidad sin inventar una conversión estructural.

## Ciclo de emisión

1. Se crea de forma durable `fiscal_stamp_attempts`.
2. Antes de `markSending()`, `reserveForAttempt()` bloquea la cuenta con `FOR UPDATE`, resta uno disponible y suma uno reservado.
3. Saldo cero produce `insufficient_balance` antes del PAC.
4. Éxito fiscal: primero se persiste el stamp y después `consumeReservation()` resta uno reservado. La clave única se liga al stamp.
5. Rechazo o no-envío confirmado: `releaseReservation()` devuelve reservado a disponible.
6. Resultado desconocido: no consume ni libera; requiere conciliación.

Si el stamp fiscal quedó persistido y falló el ledger, el documento se marca con resultado desconocido y el intento exige conciliación. Nunca se modifica el saldo silenciosamente.

## Ciclo de cancelación

La solicitud durable se crea antes de reservar. `reserveForCancellation()` usa una clave independiente. `accepted` llama `consumeCancellation()`, `rejected|transport_not_sent` llama `releaseCancellation()`, y `pending|unknown` conserva la reserva. Una solicitud existente se devuelve idempotentemente y no vuelve a reservar ni consumir. El total de un CFDI emitido y posteriormente cancelado es dos timbres.

## Conciliación

`FiscalStampReconciliationService::consumeConfirmedStamp()` consume la reserva cuando se confirma UUID y `releaseDefinitiveRejection()` la libera ante rechazo definitivo. Si falta una reserva, el servicio devuelve `STAMP_RESERVATION_MISSING`; no crea ajustes implícitos. Las cancelaciones usan las operaciones idempotentes ligadas a `fiscal_cancellation_request_id`.

## Ajustes e identidad de plataforma

El comando es:

```text
php spark fiscal:stamps-adjust <RFC> <cantidad> --reason="..." --reference="..." --dry-run
php spark fiscal:stamps-adjust <RFC> <cantidad> --reason="..." --reference="..." --execute --confirm-rfc=<RFC> --actor=<email|id>
```

El actor debe tener simultáneamente `users.is_platform_superadmin=1` y `platform.fiscal_stamps.manage`. `is_admin` no concede esta capacidad. Los créditos positivos de paquete son `allocation`; los retiros son `adjustment_debit`. La referencia, cantidad, motivo y emisor generan una clave idempotente.

La identidad se administra fuera de la UI:

```text
php spark platform:identity grant <email|id> --confirm --reason="..."
php spark platform:identity revoke <email|id> --confirm --actor=<email|id> --reason="..."
```

El primer `grant` sólo se permite cuando aún no existe identidad de plataforma. Los cambios se registran en `platform_identity_audit`.

## Vista del cliente

`GET /fiscal/stamps/balance` exige `fiscal.stamps.view_balance`, resuelve internamente el emisor predeterminado y no acepta IDs de emisor. Muestra disponible, reservado y los últimos veinte movimientos, sin acciones de escritura. El módulo fiscal muestra un indicador compacto sólo a quien tenga ese permiso.

## Preview y operaciones sin costo

`FiscalPreviewModeGuard` sigue bloqueando timbrado y cancelación antes de cualquier operación. No se agregaron llamadas comerciales a consulta SAT, generación/descarga de PDF, descarga XML ni lectura de artefactos.

## Recuperación y auditoría

- Comparar el saldo de cuenta con la suma de efectos del ledger.
- Buscar reservas sin resultado definitivo por `pac_attempt_id` y por `fiscal_cancellation_request_id`.
- Conciliar únicamente con evidencia fiscal durable.
- Corregir diferencias mediante movimientos compensatorios, nunca mediante `UPDATE` al ledger.
- Mantener la llave anterior hasta resolver cualquier movimiento fiscal pendiente; esta fase no gestiona secretos ni llaves.

## Estado de despliegue

La migración `2026-08-04-170000_CreateFiscalStampCommercialControl` está pendiente. Sólo fue aplicada en bases temporales automatizadas. No se aplicó a `ikontrol_new`, `ikontrol20_clean`, `ikontrol20_dold_preview` ni servidor.
