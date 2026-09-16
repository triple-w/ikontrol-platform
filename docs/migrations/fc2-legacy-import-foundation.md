# Fundamento de importación legacy FC2

## Alcance y frontera tenant

Cada cliente opera en una instalación y base independientes. La base completa es la frontera tenant; por ello no se agregó `company_id` a `clients` ni `items`. `company` representa la empresa administrativa y `fiscal_profiles(profile_type='issuer')` su identidad fiscal.

Esta fase no importa datos. El primer origen previsto será `source_system=fc2`, `source_owner_id=15`, `source_owner_key=DOLD860620EW7`, pero esos valores no se insertan automáticamente.

## Tablas

### `legacy_import_batches`

Registra procedencia, propietario, alcance, respaldo/hash opcional, estado, tiempos, resumen y error. Estados permitidos por el servicio: `pending`, `running`, `completed`, `completed_with_warnings`, `failed`, `rolled_back`.

Índices: estado y `(source_system, source_owner_key)`.

### `legacy_import_mappings`

Relaciona una fila fuente con su destino y conserva hashes, snapshot saneado, advertencias, acción y estado. Estados: `pending`, `imported`, `updated`, `skipped`, `conflict`, `failed`. Acciones: `insert`, `update`, `skip`, `conflict`, `error`.

La identidad única es `(source_system, source_table, source_owner_id, source_id)`. En mappings, `source_owner_id` es `NOT NULL DEFAULT ''`; el servicio normaliza propietario ausente a cadena vacía. Así la idempotencia no depende del comportamiento de `UNIQUE` con NULL en MySQL.

Índices adicionales: lote, destino y estado. No se agregó FK para mantener compatibilidad con las convenciones actuales y permitir evidencia de un destino eliminado.

## Algoritmo de idempotencia

1. Construir `LegacySourceReference` con sistema, tabla, propietario e ID.
2. Sanear el snapshot antes de persistirlo.
3. Ordenar recursivamente claves de objetos; preservar el orden de listas y los strings.
4. Rechazar floats: importes deben entregarse como strings decimales.
5. Serializar JSON canónico y calcular SHA-256.
6. Buscar el mapping por la clave fuente única.
7. Sin mapping: candidato `insert`.
8. Mapping y destino ausente: `conflict`.
9. Mismo hash: `skip`.
10. Hash diferente: candidato `update`.

El registro se ejecuta dentro de una transacción, bloquea el mapping existente con `FOR UPDATE` y se apoya además en el índice unique para cerrar carreras concurrentes. El servicio no escribe clientes, productos ni otras entidades de negocio.

## Datos sensibles

Los adaptadores futuros deben entregar snapshots previamente saneados. Como defensa adicional el servicio elimina recursivamente campos cuyos nombres indiquen passwords, tokens, API keys, secretos, llaves privadas/PEM o contraseña CSD. Nunca se deben incluir contenidos CER/KEY/PEM, credenciales PAC ni tokens en `source_snapshot_json`, advertencias o resúmenes.

El saneamiento por nombre no sustituye una lista blanca del adaptador. Cada adaptador deberá construir un snapshot sólo con campos de negocio aprobados.

## Uso futuro

El importador deberá iniciar y marcar running un lote; por cada fila calcular decisión; escribir la entidad en su propia transacción coordinada con el mapping; registrar insert/update/skip/conflict/error; finalmente completar el lote con resumen o marcarlo failed. Los nombres de destino son lógicos y el acceso debe respetar `DBPrefix`.

## Limitaciones y siguiente fase

- No existe todavía un adaptador FC2.
- No se leyó la base productiva FC2.
- No existe rollback de entidades importadas; `rolled_back` sólo está reservado como estado hasta diseñar esa política.
- Los snapshots no deben emplearse como respaldo integral del origen.
- No se implementó escritura de clientes, productos, perfiles, series, CSD ni históricos.

Siguiente fase recomendada: definir contrato y preflight del respaldo FC2, matriz de campos, políticas explícitas de duplicados y dry-run que sólo produzca decisiones/conflictos usando este registro.

