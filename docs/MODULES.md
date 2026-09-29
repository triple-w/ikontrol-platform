# Módulos: dónde iniciar una tarea

Mapa de entrada, no catálogo exhaustivo de capacidades. Sigue desde cada entrada a sus colaboradores y consumidores; la presencia de un archivo no acredita que una feature esté terminada o desplegada.

| Área de la tarea | Entrada recomendada | Qué revisar además |
| --- | --- | --- |
| Clientes y productos | [Clients](../app/Controllers/Clients.php), [Items](../app/Controllers/Items.php) | Permisos, referencias comerciales e históricos antes de cambiar catálogos |
| Estimaciones | [EstimateAcceptanceService](../app/Services/EstimateAcceptanceService.php) | Conversión y transacción administrativa |
| Propuestas, plantillas y PDF | [Proposals](../app/Controllers/Proposals.php), [ProposalAcceptanceService](../app/Services/ProposalAcceptanceService.php) | Totales entre UI/preview/PDF y compatibilidad de plantillas; [estado P01](reconciliation/P01_PROPOSAL_TEMPLATE_SCHEMA.md) |
| Ventas | [Invoices](../app/Controllers/Invoices.php) | Separación comercial/fiscal y consumidores de impuestos |
| Cobros y cuentas | [Pagos](PAYMENTS.md) | Aplicaciones, saldo y movimiento financiero |
| CFDI y timbres | [Fiscal](FISCAL.md) | Evidencia histórica, transporte e intentos inciertos |
| Complementos | [Payment_complements](../app/Controllers/Payment_complements.php) | [Pagos](PAYMENTS.md) y ciclo [fiscal](FISCAL.md) |
| Proveedores y costos | [Suppliers](../app/Controllers/Suppliers.php), [SupplierCostHistoryService](../app/Services/SupplierCostHistoryService.php) | Origen de costos, referencias comerciales y comparación; costos manuales Navika aún pendientes |
| Almacenes y transferencias | [WarehouseMovementService](../app/Services/WarehouseMovementService.php), [WarehouseTransferService](../app/Services/WarehouseTransferService.php) | Stock, tránsito, recepción parcial y [etiquetas](../app/Controllers/Warehouse_labels.php) |
| Acceso y permisos | [Security_Controller](../app/Controllers/Security_Controller.php), [Roles_model](../app/Models/Roles_model.php) | Registro dinámico de [rutas](../app/Config/Routes.php), verbos y CSRF |
| Configuración y reportes | [Settings](../app/Controllers/Settings.php), [Reports](../app/Controllers/Reports.php), [Dashboard](../app/Controllers/Dashboard.php) | Alcance de settings, permisos y consultas consumidoras |
| Diagnóstico de Base | [IkontrolBaselineCheckService](../app/Services/Baseline/IkontrolBaselineCheckService.php) | Distinguir diagnóstico de reparación; [pruebas](TESTING.md) |

Las convenciones transversales están en [Arquitectura](ARCHITECTURE.md). Para suites y condiciones de ejecución usa [Pruebas](TESTING.md); para tablas y restricciones, [Base de datos](DATABASE.md).
