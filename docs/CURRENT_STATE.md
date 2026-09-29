# Estado actual

Revisión: 2026-09-28. Corte del código inspeccionado: `d59eca7b28c4376d7717f4f3956783ac04b6f1e4`, rama `main`. El árbol estaba limpio antes de esta preparación documental. Este corte no pretende ser el HEAD permanente después de editar documentación.

## Situación operativa

`C:/xampp/htdocs/ikontrol-platform` sigue siendo el único destino canónico. `C:/xampp/htdocs/ikontrol2/ikon2.0` es únicamente fuente de comparación. iKontrol 1.0.0 **no está liberado**; no había tag `v1.0.0` en este corte.

El trabajo exclusivo de Base quedó preservado en el checkpoint `e044301`. Se validó el diagnóstico Baseline (`3a90daf`) y se incorporó el fundamento estructural de selección de plantilla (`d59eca7`), con pruebas aisladas. Esa nueva migración **no se aplicó** a las BD de Base ni Navika. No implica que la selección de plantilla en UI ya esté integrada.

El [registro de ejecución](reconciliation/BASE_V1_RECONCILIATION_EXECUTION.md) es la única checklist detallada de paquetes; [P01](reconciliation/P01_PROPOSAL_TEMPLATE_SCHEMA.md) conserva decisiones y resultados de pruebas. El siguiente paquete funcional pendiente es la revisión individual de las 19 migraciones históricas sin registro. Los costos manuales, documentos externos de complementos y mejoras Navika de producción/reintentos siguen pendientes de conciliación.

**Alcance de esta preparación:** documentación y referencia de esquema. No reanuda los paquetes funcionales, no aplica migraciones ni habilita PAC. La autorización anterior de implementación no cambia este alcance solicitado.

## Cómo interpretar la evidencia

- [DATABASE.md](DATABASE.md) apunta a una captura estructural de la Base local. No certifica una instalación productiva ni equivalencia de datos con Navika.
- La [auditoría Base/Navika](BASELINE_RECONCILIATION_BASE_VS_NAVIKA.md) y su [plan original](BASELINE_RECONCILIATION_ACTION_PLAN.md) son antecedentes fechados. Busca sólo la sección necesaria: no hace falta cargar la auditoría completa para cada tarea.
- Los informes `INCREMENTO_*`, `HOTFIX_*` y `docs/architecture/BASELINE_*` conservan contexto histórico. Sus resultados y pendientes no sustituyen una comprobación actual.
- No se ejecutó la batería funcional completa para esta preparación documental. Los resultados recientes disponibles están enlazados en P01 y en el registro de ejecución.

Al cerrar un paquete, actualiza el registro de ejecución; modifica esta página sólo si cambia el punto de partida, una restricción o el estado de liberación.
