# Pagos: separar dinero, aplicación y fiscalización

Una venta administrativa no es un CFDI. Un cobro no es su aplicación a una venta. Un complemento fiscal no debe generar un segundo cobro ni un movimiento bancario ficticio.

Tampoco son equivalentes el medio administrativo de pago, la FormaPago/MetodoPago SAT y la cuenta financiera. Los cambios que los confundan pueden cuadrar un total local y aun así duplicar efectos económicos.

## Responsabilidades actuales

| Pregunta | Fuente principal |
| --- | --- |
| ¿Cómo se registra el cobro y su cuenta? | [AdministrativePaymentService](../app/Services/AdministrativePaymentService.php) |
| ¿Cuánto queda disponible y a qué venta se aplica? | [PaymentAllocationService](../app/Services/PaymentAllocationService.php) |
| ¿Qué movimiento y saldo corresponden a la cuenta? | [FinancialAccountMovementService](../app/Services/FinancialAccountMovementService.php), [FinancialAccountBalanceService](../app/Services/FinancialAccountBalanceService.php) |
| ¿Cómo se prepara el complemento? | [PaymentComplementDraftService](../app/Services/PaymentComplementDraftService.php) |
| ¿Cómo se fija su información fiscal? | [PaymentComplementFiscalSnapshotService](../app/Services/PaymentComplementFiscalSnapshotService.php) |
| ¿Cómo se materializa para CFDI? | [PaymentComplementCfdiMaterializer](../app/Services/PaymentComplementCfdiMaterializer.php) |

El servicio de cobro coordina registro, movimiento y aplicación opcional dentro de una transacción. La implementación actual exige una cuenta activa, no eliminada, en MXN. Las aplicaciones administrativas actuales se vinculan a la venta (`invoice_id`), no directamente al documento fiscal. Revisa ese contrato antes de reutilizar consultas antiguas.

Disponibilidad de un cobro y saldo pendiente de una venta se calculan a partir de aplicaciones vigentes. No alteres uno sin considerar reversas, duplicados y consistencia del otro. Mantén la misma conexión transaccional entre servicios.

## Alcance y antecedentes

El complemento parte del pago administrativo y tiene un ciclo fiscal propio; su emisión o cancelación requiere revisar ambos dominios, sin asumir que cancelar CFDI revierte automáticamente el dinero. El soporte Navika para documentos externos/mixtos sigue pendiente; consulta el [registro de ejecución](reconciliation/BASE_V1_RECONCILIATION_EXECUTION.md).

El [modelo comercial/fiscal](INCREMENTO_C2_1_MODELO_COMERCIAL_FISCAL.md) explica la separación conceptual. La [auditoría antigua de pagos](auditoria-fiscal-cuentas-complementos.md) es histórica: sus referencias a aplicaciones sobre `fiscal_document_id` y limitaciones de timbrado no describen por sí solas el estado actual. El esquema verificable se consulta en [DATABASE.md](DATABASE.md), no en los ejemplos SQL de reportes antiguos.
