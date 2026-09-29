# Mapa para trabajar en iKontrol

Empieza por [estado actual](docs/CURRENT_STATE.md); después lee sólo el área de la tarea.

| Necesidad | Referencia |
| --- | --- |
| Límites y convenciones | [Arquitectura](docs/ARCHITECTURE.md) |
| Encontrar un módulo | [Módulos](docs/MODULES.md) |
| Esquema y migraciones | [Base de datos](docs/DATABASE.md) |
| Elegir pruebas seguras | [Pruebas](docs/TESTING.md) |
| CFDI, PAC y timbres | [Fiscal](docs/FISCAL.md) |
| Cobros, aplicaciones y bancos | [Pagos](docs/PAYMENTS.md) |

## Reglas de trabajo

- Este repositorio es la Base canónica. Navika es una fuente de comparación, nunca un reemplazo completo.
- Respeta el alcance solicitado y conserva trabajo local y capacidades exclusivas de Base. Evita refactors y normalización EOL ajenos a la tarea.
- No copies `.env`, credenciales, datos ni contenido de clientes. No ejecutes PAC real en pruebas.
- Antes de tocar BD, distingue esquema observado, migraciones y registro de ejecución. No ejecutes `spark migrate` global para resolver divergencias históricas.
- Revisa el aislamiento de cada runner antes de ejecutarlo; `ENVIRONMENT=testing` no basta.
- El código determina el comportamiento implementado; las referencias fechadas documentan observaciones. Los reportes históricos no prueban el estado actual.
- Actualiza la página correspondiente cuando cambie una decisión. No dupliques inventarios del código ni abras otra auditoría para repetirlos.
