# Dry-run de datos maestros FC2

## Alcance

`legacy:fc2-audit` inspecciona emisor, clientes, productos y series de FC2. No importa datos, no crea lotes ni mappings, no ejecuta migraciones y no escribe en las tablas operativas de FC2 o iKontrol. El único archivo opcional es el reporte solicitado explícitamente bajo `writable/`.

El origen queda identificado con `source_system=fc2`, `source_owner_id`, RFC normalizado, tabla e ID legacy. Cada registro obtiene un SHA-256 sobre JSON canónico; importes se mantienen como cadenas decimales y nunca se convierten a `float`.

## Conexión separada y de sólo lectura

Configure exclusivamente por entorno:

```dotenv
FC2_DB_HOST=127.0.0.1
FC2_DB_PORT=3306
FC2_DB_DATABASE=factucare2
FC2_DB_USERNAME=fc2_auditor
FC2_DB_PASSWORD=
FC2_DB_CHARSET=utf8mb4
```

La cuenta `fc2_auditor` debe tener únicamente `SELECT` sobre la base FC2. No conceder `INSERT`, `UPDATE`, `DELETE`, `CREATE`, `ALTER`, `DROP`, `TRIGGER`, `EXECUTE` ni privilegios administrativos. La aplicación no puede demostrar por sí sola que el servidor revocó todos esos permisos; esa comprobación es un requisito operativo previo.

La conexión usa el grupo `fc2_legacy`, sin prefijo de tablas y sin credenciales en el repositorio. El comando se detiene si faltan variables, tablas requeridas o si el nombre de la base origen coincide con el destino iKontrol.

## Ejecución

Auditoría completa del emisor confirmado:

```console
php spark legacy:fc2-audit DOLD860620EW7 --owner-id=15
```

Alcance individual y JSON:

```console
php spark legacy:fc2-audit DOLD860620EW7 --owner-id=15 --only=clients --format=json
```

Reporte privado dentro de `writable/`:

```console
php spark legacy:fc2-audit DOLD860620EW7 --owner-id=15 --format=json --output=reports/fc2-audit.json
```

`--only` admite `issuer`, `clients`, `products` o `series`. `--sample-errors=N` limita únicamente la muestra de consola; el JSON conserva todos los hallazgos. Una ruta absoluta o que contenga `..` es rechazada. El archivo se intenta crear con permisos `0600` y el directorio con `0700`.

## Guardas previas

Antes de leer datos maestros se exige:

- base origen distinta a la conexión por defecto de iKontrol;
- existencia de `users`, `users_perfil`, `users_info_factura`, `users_info_factura_documentos`, `users_logo`, `clientes`, `productos`, `folios`, `clave_prod_serv` y `clave_unidad`;
- exactamente un `users_perfil` para el RFC solicitado;
- coincidencia de ese perfil con el `users.id` esperado;
- coincidencia del `users.username` normalizado con el RFC.

Un cliente/receptor que tenga el RFC del emisor no participa en esta resolución. Los adaptadores aplican `users_id` en cada consulta y ordenan por ID. Clientes y productos usan paginación por llave (`id > último_id`) para evitar cargar tablas completas en memoria durante la lectura.

## Contenido del reporte

El reporte incluye:

- fecha UTC, sistema, propietario, RFC y versión de esquema;
- resumen por entidad con totales y severidades;
- hallazgos con `severity`, código, entidad e ID legacy;
- hash agregado reproducible de los registros leídos;
- metadatos mínimos de CSD y logotipo, nunca contenido de archivos.

No incluye contraseñas, nombres de archivos, rutas legacy, blobs, CER, KEY, PEM ni contenido fiscal. El nombre de la base origen se enmascara.

Severidades:

- `info`: diferencia conocida que no invalida el registro;
- `warning`: dato incompleto o decisión posterior sin bloqueo técnico inmediato;
- `conflict`: requiere política explícita antes de importar;
- `error`: integridad o dato obligatorio inválido; el comando termina con código no exitoso.

Entre los hallazgos están RFC y claves internas duplicadas, régimen/uso CFDI ausentes, RFC genéricos, código postal inválido, descripción y precio inválidos, precio cero, referencias SAT ausentes, falta de ObjetoImp/impuestos, tipo producto-servicio pendiente y series duplicadas o no traducibles. El dry-run no decide cuál serie duplicada está vigente.

## Límites deliberados

- No consulta ni registra mappings del foundation legacy.
- No resuelve IDs destino ni crea registros.
- No copia certificados, llaves, logotipos ni rutas.
- No valida criptográficamente el CSD.
- No modifica folios.
- No incluye facturación histórica.
- No resuelve aún si cada producto es bien o servicio, ni las políticas definitivas de duplicados.

## Pruebas

```console
php tests/Fc2MasterDataDryRun/run.php
```

La suite crea dos bases temporales locales (origen y destino), usa fixtures sin datos reales, cubre aislamiento, paginación, precisión decimal, catálogos SAT, duplicados y sanitización, compara conteos/checksums antes y después, y elimina las bases temporales al finalizar. No apunta al respaldo FC2 real.

## Diagnóstico seguro

Si falla la conexión, confirmar host, puerto, nombre de base y usuario sin imprimir la contraseña. Si falta una tabla, comprobar que se seleccionó el respaldo correcto. Si el emisor es ambiguo o no coincide con `users.id=15`, no continuar: debe corregirse la selección del origen, no el dato mediante este comando. Nunca pegue credenciales, contraseñas CSD ni contenido de certificados en reportes o incidencias.
