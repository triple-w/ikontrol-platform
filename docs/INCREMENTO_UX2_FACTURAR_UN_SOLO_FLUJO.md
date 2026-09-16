# Incremento UX2 — Facturar en un solo flujo

La revisión fiscal normal ofrece una confirmación sencilla y ejecuta un endpoint AJAX único. `FiscalInvoiceFlowService` vuelve a validar snapshot v2, venta cerrada, wallet e intentos ambiguos; marca el draft como listo internamente y delega en los servicios fiscales existentes.

- Un blocker mantiene abierto el modal y evita el PAC.
- Un rechazo definitivo conserva la revisión corregible; el motor libera la reserva.
- Un resultado incierto mantiene la reserva, bloquea reenvíos y muestra el estado de verificación.
- XML válido consume un timbre. Un fallo posterior del PDF no retimbra ni devuelve el timbre.
- El éxito cierra el modal y redirige a `fiscal/invoices/{document_id}`.

El orquestador reutiliza `FiscalDraftWorkflowService`, `FiscalDraftValidationService`, `FiscalDraftStampingPreflightService`, `FiscalDraftStampingService`, `FiscalStampAccountService` y `FiscalPacPdfGenerationService`. No replica firma, parser, persistencia, reserva, consumo o PDF.

El endpoint exige `fiscal.invoices.stamp`, CSRF y acceso al draft. Locks no bloqueantes protegen UX y motor. Los mensajes normales no exponen payload, endpoint, parser ni credenciales.

Las pruebas automatizadas no usan red. Antes de la llamada development autorizada se documenta el preflight real y se detiene el flujo para revisión operativa.
