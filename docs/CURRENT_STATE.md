# Estado actual

> P15 (2026-10-01): iKontrol v1.0.0 está liberado en el tag `v1.0.0`. La instalación canónica, onboarding y actualización dirigida están documentados.

Revisión: 2026-10-01. Rama canónica: `main`. iKontrol v1.0.0 liberado.

## Situación operativa

`C:/xampp/htdocs/ikontrol-platform` es el único destino canónico. iKontrol v1.0.0 es la base para todo cambio genérico y toda instancia nueva.

El trabajo exclusivo de Base quedó preservado desde el checkpoint `e044301`. La conciliación implementó y validó de forma aislada schema aditivo, proposals, costos manuales de proveedor, documentos externos de complementos y ambiente fiscal; Warehouses quedó validado sin cambio funcional. Ninguna migración canónica nueva se ha aplicado a las BD de Base o Navika.

El [registro de ejecución](reconciliation/BASE_V1_RECONCILIATION_EXECUTION.md) conserva la evidencia P00–P15. Las migraciones de instancias existentes siguen el procedimiento dirigido P02; no se aplican globalmente. Sandbox y production se validaron únicamente con transporte MOCK y configuración fail-closed.

No se han aplicado migraciones sobre Base/Navika ni se ha ejecutado PAC real durante la conciliación.

## Cómo interpretar la evidencia

- [DATABASE.md](DATABASE.md) apunta a una captura estructural de la Base local. No certifica una instalación productiva ni equivalencia de datos con Navika.
- La [auditoría Base/Navika](BASELINE_RECONCILIATION_BASE_VS_NAVIKA.md) y su [plan original](BASELINE_RECONCILIATION_ACTION_PLAN.md) son antecedentes fechados. Busca sólo la sección necesaria: no hace falta cargar la auditoría completa para cada tarea.
- Los informes `INCREMENTO_*`, `HOTFIX_*` y `docs/architecture/BASELINE_*` conservan contexto histórico. Sus resultados y pendientes no sustituyen una comprobación actual.
- No se ejecutó la batería funcional completa para esta preparación documental. Los resultados recientes disponibles están enlazados en P01 y en el registro de ejecución.

Al cerrar un paquete, actualiza el registro de ejecución; modifica esta página sólo si cambia el punto de partida, una restricción o el estado de liberación.
