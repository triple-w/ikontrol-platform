# P04 — Documentos externos en complementos de pago

**Estado:** implementado y probado en fixtures aislados; pendiente de despliegue controlado.
**Fecha:** 2026-09-29

P04 introduce sólo la base estructural y el servicio de borrador para CFDI relacionados emitidos fuera de iKontrol. Un documento externo es una referencia fiscal: no crea ni modifica `invoices`, `invoice_payments`, `payment_allocations`, movimientos financieros ni documentos internos del complemento.

## Migración canónica

`2026-09-29-120000_CreateCanonicalPaymentComplementExternalDocuments` es aditiva e idempotente para MySQL/MariaDB. Comprueba primero las tablas padre y el tipo de `fiscal_documents.invoice_id`; no contiene DML. Conserva todos los valores `invoice_id` existentes y sólo permite `NULL` para que P11 pueda crear documentos fiscales cuyo origen sea exclusivamente externo.

| Estructura | Propósito y relaciones |
| --- | --- |
| `payment_complement_external_documents` | Referencia fiscal externa ligada al complemento y a uno de sus pagos fiscales. No tiene FK a invoice, pago administrativo, allocation o movimiento financiero. |
| `payment_complement_external_taxes` | Impuestos DR del documento externo; FK al documento externo. |
| `fiscal_documents.invoice_id` | Se vuelve nullable sin eliminar relaciones ni valores históricos. |

La identidad activa es única por `(payment_complement_id, active_uuid)`. `active_uuid` se genera sólo mientras `deleted = 0`; así la eliminación lógica conserva trazabilidad y permite capturar de nuevo el UUID en un nuevo borrador. Las FKs usan `RESTRICT` para no borrar evidencia fiscal mediante cascada.

La migración no se aplicó a Base. El runner MySQL la ejecutó dos veces contra un esquema temporal propio y comprobó que deja vacías las nuevas tablas y no cambia los registros administrativos de fixture.

## Servicio mínimo

`PaymentComplementExternalDocumentService` proporciona lectura, listado, alta/actualización atómica de impuestos y eliminación lógica. Sólo admite complementos en `draft` o `complete_draft`; no sincroniza totales, snapshots, XML, PaymentBuilder ni timbrado.

Antes de escribir valida UUID, moneda SAT activa, PPD, NumParcialidad, saldos DR, equivalencia, ObjetoImpDR y renglones de traslado/retención/exento. Rechaza UUID duplicado dentro del complemento y UUID que ya pertenezca a un documento interno del mismo pago. La escritura toma bloqueos de complemento y pago en MySQL; la restricción única cubre la identidad concurrente.

## Compatibilidad y límite de paquete

Los documentos internos de `payment_complement_documents`, sus allocations y los complementos existentes no se transforman. La nulabilidad de `fiscal_documents.invoice_id` no hace que el flujo actual emita un complemento externo: los lectores, snapshot, materializador y servicio fiscal siguen siendo internos.

P11 debe reconciliar la lectura mixta, snapshots, totales y conversión de moneda, `PaymentBuilder`, materialización XML/PDF, permisos/rutas/UI y los bloqueos definitivos una vez preparado o timbrado. No hubo operaciones PAC, cambios a Navika ni migración global.

## Validación realizada

`tests/PaymentComplementExternalSchema/run.php` usa SQLite en memoria para el servicio y, con `--mysql`, crea y elimina un esquema MySQL temporal. PAC queda explícitamente deshabilitado.

| Entorno | Resultado |
| --- | --- |
| SQLite en memoria | 24 aserciones aprobadas |
| MySQL temporal | 28 aserciones aprobadas; esquema temporal eliminado |

La cobertura incluye documentos MXN y con equivalencia, UUID duplicado interno/externo, parcialidad y saldos, traslados, retenciones, exento, impuestos múltiples, eliminación lógica, bloqueo de complementos timbrados, `invoice_id` nullable sólo en MySQL y preservación de todas las estructuras administrativas de fixture.
