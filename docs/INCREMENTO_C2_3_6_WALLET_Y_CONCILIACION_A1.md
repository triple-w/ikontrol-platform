# Incremento C2.3.6 — wallet comercial y conciliación A-1

Base activa: `ikontrol20_dold_preview`. Fecha: 2026-08-13.

## Modelo y corrección

`fiscal_stamp_accounts` mantiene por `issuer_profile_id + environment` dos contadores: `available_balance` (timbres comerciales libres) y `reserved_balance` (timbres apartados para intentos no resueltos). `fiscal_stamp_movements` es el ledger inmutable con actor, tipo, saldos antes/después y referencias a documento/intento/timbre.

El movimiento 1 había acreditado 49 como `pac_development_sync`, copiando incorrectamente el crédito técnico de la API key al wallet del emisor. Se asignaron administrativamente 20 timbres de prueba (`development_test_allocation`) y se compensaron los 49 mediante `reverse_invalid_pac_sync`; no se borró evidencia. Antes de conciliar: 19 disponibles y 1 reservado.

Los créditos técnicos se almacenan separadamente en `fiscal_pac_credit_snapshots`. Las consultas futuras no mutan el wallet. `synchronizeDevelopment()` y su comando legacy fallan cerrados. La UI normal lee sólo el wallet; administradores pueden ver el snapshot PAC con la etiqueta “Cuenta técnica del proveedor”.

Cancelación, consulta SAT, XML y PDF no reservan ni consumen timbres comerciales. Sólo el timbrado confirmado convierte una reserva en consumo.

## Conciliación A-1

Sin red y sin reenviar se descifró la evidencia del intento 15. El parser extrajo `data` como XML directo. `StampedXmlValidator` confirmó CFDI 4.0, TFD 1.1, UUID `CEB0CA60-4680-4298-B68B-E0638C0EEAEE`, serie A, folio 1, RFC de emisor/receptor, subtotal 11,976.00, IVA 1,916.16, total 13,892.16, certificados, sellos y fecha.

Resultado: intento `success_reconciled`, `requires_reconciliation=0`, fuente `stored_provider_response`; documento y borrador `stamped`; XML persistido; asignación venta 7/documento 1 convertida; exactamente una reserva consumida. Wallet final: 19 disponibles, 0 reservados.

El PDF fue generado por `FiscalPacPdfGenerationService → WSTools33`, plantilla 1, provider code 210, archivo `%PDF-` de 7,898 bytes. XML y PDF respondieron HTTP 200 desde sus endpoints de descarga.

No se consultaron créditos PAC ni se llamó a `timbrarConSello` durante este incremento. No hubo commit ni push.

Pruebas acumuladas: C2.3.2 20/20; C2.3.3 32/32; C2.3.4 15/15; C2.3.5 27/27; C2.3.6 23/23.
