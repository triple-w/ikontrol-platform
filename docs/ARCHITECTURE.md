# Arquitectura y convenciones

Este documento explica límites de responsabilidad; [Módulos](MODULES.md) indica dónde empezar a buscar. No es un inventario de clases.

## Forma del sistema

iKontrol es un monolito administrativo basado en RISE/CodeIgniter con servicios fiscales incorporados progresivamente. Conviven controladores y helpers con lógica heredada y servicios de dominio; no debe suponerse que todos los controladores sean delgados.

El arranque y las dependencias efectivas se verifican en [spark](../spark), [Autoload](../app/Config/Autoload.php) y el framework incluido en `system/`. No hay un manifiesto Composer raíz que describa por sí solo esta instalación.

[Routes.php](../app/Config/Routes.php) combina rutas explícitas con registro dinámico de controladores y métodos GET/POST. También incluye [FiscalRoutes.php](../app/Config/FiscalRoutes.php). Una ruta explícita no demuestra que un método carezca de otra vía de acceso: revisar el registro dinámico, autorización y CSRF juntos. [Events.php](../app/Config/Events.php) contiene puntos de extensión adicionales.

## Límites que deben preservarse

- Venta administrativa y documento fiscal son entidades distintas: emitir un CFDI no debe crear otra venta.
- Cobro, aplicación a una venta y movimiento de cuenta son hechos relacionados, no intercambiables. Véase [Pagos](PAYMENTS.md).
- El borrador fiscal es mutable; el documento emitido y su evidencia histórica no deben reconstruirse desde catálogos actuales. Véase [Fiscal](FISCAL.md).
- El inventario logístico tiene movimientos propios; cambiar partidas comerciales no equivale automáticamente a mover existencias.
- `app/Services/Fiscal/` y `app/FiscalServices/` contienen responsabilidades activas. La semejanza de nombres no justifica fusionarlos o eliminarlos.

## Transacciones y cambios

La operación de negocio debe conservar una conexión coherente entre sus colaboradores. Por ejemplo, [ProposalAcceptanceService](../app/Services/ProposalAcceptanceService.php) coordina bloqueo, conversión, enlaces y confirmación o rollback. Abrir una conexión por defecto dentro de un colaborador puede escapar de la transacción o del fixture de pruebas.

Sigue las convenciones del archivo y módulo vecino: el repositorio mezcla estilos históricos. Mantén cambios funcionales separados de renombrados, EOL y limpieza. Reutiliza los servicios existentes antes de agregar otro cálculo de importes o impuestos. Verifica consumidores de HTML, PDF y persistencia cuando cambies un cálculo compartido.

Para las decisiones de conciliación y compatibilidad consulta el [registro de ejecución](reconciliation/BASE_V1_RECONCILIATION_EXECUTION.md); no interpretes una capacidad prevista allí como ya implementada.
