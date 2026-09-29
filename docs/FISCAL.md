# Fiscal: límites y puntos de entrada

Lee [Pagos](PAYMENTS.md) para la separación administrativa y [estado actual](CURRENT_STATE.md) antes de asumir que las mejoras de Navika ya están integradas.

## Dónde seguir una operación

| Operación | Punto de entrada |
| --- | --- |
| Preparación de CFDI de venta | [FiscalInvoiceFlowService](../app/Services/Fiscal/FiscalInvoiceFlowService.php) |
| Documento preparado e invalidación | [FiscalPreparedDocumentLifecycleService](../app/Services/Fiscal/FiscalPreparedDocumentLifecycleService.php) |
| Intento de timbrado | [FiscalStampingService](../app/Services/Fiscal/Pac/FiscalStampingService.php) |
| Resultado incierto | [FiscalStampReconciliationService](../app/Services/Fiscal/Pac/FiscalStampReconciliationService.php) |
| Cancelación | [FiscalCancellationService](../app/Services/Fiscal/Cancellation/FiscalCancellationService.php) |
| Cuenta de timbres | [FiscalStampAccountService](../app/Services/Fiscal/Stamps/FiscalStampAccountService.php) |
| Complemento de pago | [PaymentComplementFiscalDocumentService](../app/FiscalServices/PaymentComplementFiscalDocumentService.php) |

## Invariantes para cambios

Un borrador mutable, su snapshot fiscal, un intento PAC y el XML timbrado son estados/evidencias diferentes. No regeneres documentos históricos a partir de series, folios, impuestos o catálogos actuales. Preserva UUID, XML y trazabilidad de cada respuesta.

Si existe posibilidad de que una solicitud haya salido al PAC, no autorices reintento automático: primero debe resolverse el resultado incierto. Reserva, liberación y consumo de timbres deben conservar correspondencia con el intento; esta cuenta no es una cuenta bancaria.

Los guards no deben deshabilitarse para hacer pasar pruebas. No se realizan operaciones PAC reales durante desarrollo automatizado de este repositorio.

## Ambientes: implementación frente a objetivo

[Fiscal.php](../app/Config/Fiscal.php), [TimbradorXpress.php](../app/Config/TimbradorXpress.php) y el [adaptador](../app/Services/Fiscal/Pac/TimbradorXpressRestAdapter.php) son las fuentes de comportamiento actual. Entorno de CodeIgniter, modo de ejecución fiscal y destino PAC son conceptos distintos; actualmente todavía existen dependencias y guardas específicas de entorno. El adaptador Base bloquea producción.

Unificar sandbox/production bajo el mismo pipeline y una sola selección fiscal es trabajo pendiente de conciliación, no una capacidad certificada por este documento. No copies la configuración operativa de Navika para habilitarlo.

Para contexto de diseño consulta [control comercial de timbres](fiscal/stamp-commercial-control.md) y [vault CSD](INCREMENTO_A2_VAULT_CSD.md). Son antecedentes: verifica sus afirmaciones contra el código al modificar esos flujos; no contienen autorización para operar credenciales o servicios reales.
