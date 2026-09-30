# Estado actual

Revisión: 2026-09-29. Rama canónica: `main`. iKontrol 1.0.0 aún no está liberado.

## Situación operativa

`C:/xampp/htdocs/ikontrol-platform` sigue siendo el único destino canónico. `C:/xampp/htdocs/ikontrol2/ikon2.0` es únicamente fuente de comparación. iKontrol 1.0.0 **no está liberado**; no había tag `v1.0.0` en este corte.

El trabajo exclusivo de Base quedó preservado desde el checkpoint `e044301`. La conciliación implementó y validó de forma aislada schema aditivo, proposals, costos manuales de proveedor, documentos externos de complementos y ambiente fiscal; Warehouses quedó validado sin cambio funcional. Ninguna migración canónica nueva se ha aplicado a las BD de Base o Navika.

El [registro de ejecución](reconciliation/BASE_V1_RECONCILIATION_EXECUTION.md) es la checklist detallada. P01–P12 cuentan con decisiones y evidencia; sus migraciones siguen sin aplicarse a las bases fuente. P09 centralizó el ambiente fiscal en `FiscalRuntimeContext`. P10 cerró el lifecycle seguro y P11 unificó complementos internos, externos y mixtos. P12 congeló el contrato de serie/folio y confirmó que XML/PDF históricos de ingreso, pago y egreso consumen evidencia persistida; el logo vigente sólo afecta presentación. Sandbox y production se validaron únicamente con transporte MOCK y configuración fail-closed. El siguiente paquete es P13.

No se han aplicado migraciones sobre Base/Navika ni se ha ejecutado PAC real durante la conciliación.

## Cómo interpretar la evidencia

- [DATABASE.md](DATABASE.md) apunta a una captura estructural de la Base local. No certifica una instalación productiva ni equivalencia de datos con Navika.
- La [auditoría Base/Navika](BASELINE_RECONCILIATION_BASE_VS_NAVIKA.md) y su [plan original](BASELINE_RECONCILIATION_ACTION_PLAN.md) son antecedentes fechados. Busca sólo la sección necesaria: no hace falta cargar la auditoría completa para cada tarea.
- Los informes `INCREMENTO_*`, `HOTFIX_*` y `docs/architecture/BASELINE_*` conservan contexto histórico. Sus resultados y pendientes no sustituyen una comprobación actual.
- No se ejecutó la batería funcional completa para esta preparación documental. Los resultados recientes disponibles están enlazados en P01 y en el registro de ejecución.

Al cerrar un paquete, actualiza el registro de ejecución; modifica esta página sólo si cambia el punto de partida, una restricción o el estado de liberación.
