# P05 — Servicios compartidos canónicos

**Estado:** implementado y probado con fixture puro; pendiente de despliegue controlado.
**Fecha:** 2026-09-29

P05 reduce una duplicación concreta de totales de propuestas y deja contratos explícitos para precio/costo y diagnóstico fiscal. No crea migraciones, no recalcula documentos fiscales existentes y no realiza transporte PAC.

## Dinero e importes

No se fusionaron las tres utilidades existentes porque sus contratos no son intercambiables:

| Fuente | Contrato que conserva |
| --- | --- |
| `FinancialMoney` | Importes administrativos `DECIMAL(18,6)`: pagos, aplicaciones y movimientos. |
| `FiscalDecimal` | Operaciones puras de seis decimales para renglones comerciales y evidencia fiscal. |
| `FiscalDecimalCalculator` | Escala configurable y redondeo de preparación CFDI/snapshots. |

Mezclarlas cambiaría precisión o redondeo de flujos ya persistidos. Los cálculos nuevos de proposal usan `FiscalDecimal`; no se modifica semántica de pagos, movimientos, XML ni documentos emitidos.

## Totales de proposals

`ProposalTotalsService` es el contrato puro para subtotal, descuento, total después de descuento, dos impuestos y total general. `Proposals_model::get_proposal_total_summary()` conserva la lectura de SQL, moneda y los campos legacy (`proposal_subtotal`, `tax`, `tax2`, `discount_total`, `discount_type`, `proposal_total`), pero delega la aritmética al servicio.

| Tipo de descuento | Base del descuento | Base de impuestos | `proposal_total` |
| --- | --- | --- | --- |
| `before_tax` | subtotal | subtotal menos descuento | subtotal menos descuento más impuestos |
| `after_tax` | subtotal más impuestos | subtotal | subtotal más impuestos menos descuento |

`total_after_discount` se expone adicionalmente para consumidores nuevos; no reemplaza placeholders ni plantillas legacy. P06 debe adoptar este contrato en UI, preview y PDF antes de retirar cálculos locales.

## Precio, costo y fiscal

- `CommercialMarginService` sigue siendo la autoridad para normalizar costo/margen/precio y decidir `price_origin` (`manual` o `cost_margin`). Proposal, invoice y estimate ya convergen en ese contrato para el origen; no se cambió la validación heredada específica de estimate.
- `SupplierCostHistoryService` mantiene entradas formal y manual separadas. P05 no toca la persistencia ni reclasifica historial; P07 debe consumir ese contrato tras los gates de datos de P02/P03.
- `ProductFiscalConfigurationResolver`, los overrides por partida y `FiscalIssuerResolver` permanecen como resolvers de identidad/configuración. No reconstruyen documentos ya preparados o timbrados.
- `FiscalRuntimeContext` separa `runtime_mode`, ambiente fiscal lógico, proveedor y ambiente de transporte. `FiscalIntegrationStatusService` lo consume sólo para diagnóstico y añade `environment_contract_coherent`; conserva sus claves previas y no habilita PAC. P09 deberá llevar este contrato al selector operativo único y al factory sin cambiar sus guardas antes de entonces.

## Validación

`tests/SharedDomainServices/run.php` usa únicamente valores en memoria y no abre una conexión de negocio ni crea un PAC. Resultado: **16 aserciones aprobadas**.

La cobertura incluye decimales/redondeo, descuento cero, descuento antes y después de impuestos, impuestos múltiples, rechazo de descuento inválido, origen de precio/costo, coexistencia formal/manual, coherencia de ambiente y la delegación del consumidor principal al calculador compartido.

## Dependencias posteriores

- **P06:** reemplazar cálculos de UI/preview/PDF y validar propuestas históricas contra el contrato, sin cambiar templates todavía.
- **P07:** conservar `price_origin` e historial formal/manual al integrar comparador, permisos y edición.
- **P09:** centralizar la selección operativa sandbox/production sobre `FiscalRuntimeContext`; P05 no cambia endpoint, credenciales ni `FiscalPacAdapterFactory`.
- **P11:** usar el mismo contrato de importes sin mezclar complemento fiscal con pago, allocation o movimiento administrativo.
