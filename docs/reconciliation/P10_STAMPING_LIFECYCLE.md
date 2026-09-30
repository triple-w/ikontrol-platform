# P10 — Lifecycle seguro de timbrado

Fecha de cierre técnico: 2026-09-30. Estado: implementado y probado con transporte fake/MOCK; migración aditiva creada y no aplicada a las bases fuente.

## Contrato canónico

El intento durable se crea y reserva un timbre antes de abrir transporte. El documento preparado, el intento, la evidencia de transporte y el movimiento de wallet quedan ligados al mismo ambiente fiscal resuelto por `FiscalRuntimeContext`.

| Resultado observado | Estado del intento | Reconciliación | Reserva | Reenvío |
| --- | --- | --- | --- | --- |
| Fallo local anterior al transporte | `transport_not_sent` | no | liberar | sólo después de invalidación explícita |
| Adaptador confirma `request_sent=false` | `transport_not_sent` | no | liberar | sólo después de invalidación explícita |
| Respuesta PAC definitiva de rechazo | `rejected` | según clasificación PAC | liberar sólo si es definitiva | nunca repetir el mismo prepared document |
| Timeout, desconexión o excepción después de invocar adaptador | `timeout_unknown` / `transport_unknown` | sí | conservar | bloqueado |
| Respuesta duplicada o no interpretable | estado incierto correspondiente | sí | conservar | bloqueado |
| Timbrado confirmado | `success` / `success_reconciled` | no | consumir una vez | prohibido |
| Conciliación definitiva sin timbre | `reconciled_not_found` | no | liberar una vez | no automático |

Una excepción del adaptador ya no se interpreta como no-envío: una vez invocado el adaptador existe una ventana de incertidumbre. El retry seguro exige simultáneamente `transport_not_sent`, `request_sent=0`, ausencia de UUID, XML timbrado, respuesta/contingencia PAC y conciliación pendiente. La invalidación marca el prepared document como `superseded`, cierra el intento y desacopla el draft. La siguiente solicitud debe preparar un documento nuevo.

## Evidencia y schema

`AddCanonicalFiscalStampTransportEvidence` agrega, sin DML ni backfill, endpoint PAC, apertura de transporte, evidencia nullable de envío, momento de confirmación y recepción de respuesta. Los registros históricos permanecen con `NULL`: no se inventa si una solicitud antigua salió o no. El endpoint proviene del contexto fiscal P09.

La migración es requisito previo al despliegue del código P10. Debe aplicarse de forma dirigida conforme al ledger P02; no se ejecutó `migrate` global ni se tocó Base real.

## Reconciliación y concurrencia

`FiscalStampReconciliationService` es la única ruta para un posible envío. Usa lock nominal por intento y acepta cuatro resultados del adaptador: timbrado encontrado, no encontrado definitivo, indeterminado y error. Sólo el primero persiste XML/UUID y consume; sólo el segundo cierra y libera. Los dos últimos conservan `requires_reconciliation=1` y la reserva.

Se retiraron las comparaciones hardcodeadas con UUID, serie, folio e importes de una factura concreta. La recuperación valida el XML timbrado contra el XML firmado del propio documento y conserva provider/environment del intento. Si la persistencia local del movimiento de wallet falla después de resolver el estado fiscal, queda un estado local recuperable que permite repetir exclusivamente consume/release, nunca el request PAC.

El retry seguro y la conciliación usan locks nominales independientes por intento. Las filas de cuenta de timbres continúan con `FOR UPDATE`; las claves idempotentes existentes impiden reservas, consumos y liberaciones duplicadas.

## Wallet

- Éxito: `reserve → send → consume`.
- No-envío probado: `reserve → transport_not_sent → release`.
- Resultado incierto: la reserva se conserva.
- Rechazo PAC definitivo: puede liberarse; un rechazo que requiere conciliación no libera.
- Conciliación timbrada: consume sólo si existe un stamp ligado al intento.
- Conciliación no encontrada definitiva: libera sólo con conciliación cerrada, sin UUID.

Las guardas de wallet rechazan consumo sin timbre y liberación desde estados inciertos. La wallet fiscal sigue separada de cuentas y movimientos financieros.

## Validación

- `php tests/FiscalStampingLifecycle/run.php`: 26/26. Cubre éxito fake, no-envío, excepción tras abrir transporte, rechazo, timeout, reglas de invalidación/retry, bloqueo por UUID/evidencia, cuatro resultados de conciliación, snapshots persistidos, locks, idempotencia de wallet y campos de evidencia.
- `php tests/FiscalPreparedDocumentLifecycle/run.php`: 11/11.
- `php tests/FiscalEnvironment/run.php`: 16/16; sandbox y production sólo mediante MOCK, cero HTTP real.
- `php tests/Increment09/run.php`: 25/25, regresión estática del pipeline fiscal.
- Lint PHP de servicios, controller, fake PAC, migración y runner P10: aprobado.

El runner histórico `tests/Increment09/database_integration.php` no pudo iniciar su schema temporal porque una migración P02 anterior (`CanonicalizeAdministrativePayments`) encontró `payment_allocations.fiscal_document_id` ausente en el snapshot de origen. Falló antes de ejecutar fixtures P10 y no modificó las bases fuente. No se usó como evidencia de aprobación.

## Pendientes para P11

P11 debe consumir estos estados sin ofrecer reenvío cuando `requires_reconciliation=1`, y debe conectar el lifecycle a complementos internos/externos/mixtos. Quedan fuera de P10 el `PaymentBuilder`, snapshots de documentos externos, UI completa de complementos y su XML/PDF. La migración P10 y las migraciones P04 deben estar aplicadas antes de validar P11 en un schema canónico temporal.
