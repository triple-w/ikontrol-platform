# iKontrol 2.0 — DOLD preview

## Objetivo y límites

Esta base es una vista previa visual y administrativa. Contiene datos maestros del propietario FC2 `users.id=15`, pero no es una instalación fiscal productiva. No contiene CSD, contraseñas CSD, credenciales PAC, series fiscales, folios reservados, documentos, UUID, XML, PDF, complementos ni cancelaciones.

La plantilla `ikontrol20_clean`, la base operativa `ikontrol_new` y el origen de sólo lectura `fc2_migration_source` no se modificaron.

## Creación

1. Se verificó el SHA-256 del respaldo limpio.
2. Se creó `ikontrol20_dold_preview` con `utf8mb4` y `utf8mb4_general_ci`.
3. Se restauró `writable/backups/ikontrol20_clean_20260804.sql`.
4. Se validaron 144 tablas, 48 migraciones (batch máximo 1), catálogos SAT, cero datos comerciales y `items.rate DECIMAL(18,6)`.
5. El grupo `dold_preview` y `PreviewDatabaseTargetGuard` exigen el nombre físico exacto y rechazan las bases protegidas.

Dry-run:

```bash
php spark legacy:fc2-import-master DOLD860620EW7 --owner-id=15 --target=dold_preview --dry-run
```

Ejecución confirmada:

```bash
php spark legacy:fc2-import-master DOLD860620EW7 --owner-id=15 --target=dold_preview --execute --confirm-database=ikontrol20_dold_preview
```

## Políticas aplicadas

- Emisor: cuenta y perfil se registran por separado; el perfil queda `incomplete`.
- Clientes: 182 filas origen produjeron 182 mappings y 180 clientes. Dos filas fueron duplicados exactos según RFC, razón social, código postal y país normalizados; cada origen conserva mapping propio.
- Los 47 clientes sin régimen se conservaron. Ningún cliente recibió Uso CFDI predeterminado.
- Productos: se conservaron los 255, incluidos 88 dentro de claves internas repetidas y 10 con precio cero.
- El producto sin descripción recibió el marcador administrativo solicitado y warning en su mapping.
- Las referencias SAT se resolvieron por `code`. Sólo las claves referenciadas que faltaban en la plantilla se incorporaron a la preview desde los catálogos FC2, sin copiar IDs numéricos.
- Todos los productos permanecen fiscalmente `incomplete`: sin ObjetoImp ni impuestos inventados.
- Los cinco folios FC2 sólo se auditaron. No se creó ninguna serie; `INGRESO/I` permanece como conflicto pendiente.
- El registro de logotipo declara una imagen PNG, pero el archivo físico no estuvo disponible en las ubicaciones accesibles. El logo quedó vacío.
- La repetición con el mismo hash se validó como `skip` sin duplicar clientes ni productos.

## Bloqueo fiscal

El servidor preview debe configurar explícitamente:

```ini
fiscal.previewMode = true
fiscal.stampingEnabled = false
fiscal.enabled = false
fiscal.allowRealPac = false
```

`FiscalPreviewModeGuard` bloquea timbrado y cancelación antes de cualquier intento PAC o persistencia asociada. El layout muestra: “AMBIENTE DE VISTA PREVIA — TIMBRADO FISCAL DESHABILITADO”. No configurar variables PAC ni CSD.

## Estado final

| Elemento | Conteo |
|---|---:|
| Tablas | 144 |
| Migraciones | 48 |
| Clientes comerciales | 180 |
| Perfiles receptor | 180 |
| Perfiles emisor | 1 |
| Productos | 255 |
| Mappings clientes | 182 |
| Mappings productos | 255 |
| Mappings totales | 439 |
| Series fiscales | 0 |
| Certificados / secretos CSD | 0 / 0 |
| Documentos / intentos PAC | 0 / 0 |

El historial conserva un primer lote fallido sin mappings causado por una validación de tipos durante la preparación local; no produjo datos. Los lotes posteriores completaron la importación y la comprobación idempotente.

## Administrador

La base conserva el administrador local ficticio del baseline, activo y sin credencial operativa en el respaldo. En servidor se debe asignar una contraseña nueva mediante entrada interactiva:

```bash
php spark admin:reset-password admin@ikontrol20-clean.invalid
```

No reutilizar una contraseña local ni incluirla en SQL, documentación o comandos.

## Pruebas

Las 29 suites del repositorio finalizaron con 820 aserciones correctas y cero fallos. La suite `DoldPreview` valida aislamiento físico, activación automática del modo preview, conteos, mappings, ausencia de documentos/intentos y bloqueo de timbrado/cancelación. Las suites usan bases temporales o conexiones explícitas; no escriben en `ikontrol_new`.

## Respaldo y restauración

- Archivo: `writable/backups/ikontrol20_dold_preview_20260804.sql`
- Tamaño: 807642 bytes
- SHA-256: `2a5cc2368c05612a647a293c53a6c065f94cbd6a7d2a73e9c5772cb6d267979a`

La restauración temporal validó 144 tablas, 48 migraciones, 180 clientes, 255 productos, 181 perfiles, 439 mappings, referencias SAT resueltas y cero series, documentos, CSD o intentos PAC. La base temporal se eliminó después de validar.

## Procedimiento de servidor

1. Crear una base nueva con `utf8mb4_general_ci`; no sobrescribir una base existente.
2. Verificar el SHA-256 antes de restaurar.
3. Restaurar el SQL mediante credenciales solicitadas fuera de la línea de comandos.
4. Configurar el grupo destino y confirmar `SELECT DATABASE()`.
5. Configurar los cuatro interruptores de preview anteriores y dejar PAC/CSD vacíos.
6. Configurar `writable`, limpiar caché y comprobar `php spark migrate:status` sin ejecutar migraciones.
7. Cambiar interactivamente la contraseña administrativa.
8. Probar navegación, clientes, productos, búsquedas y edición; confirmar que timbrado y cancelación muestran el bloqueo de preview.

## Recreación

Partir siempre del respaldo limpio validado, restaurarlo en una base nueva, ejecutar primero el dry-run y sólo después el comando confirmado. No clonar `ikontrol_new` ni reutilizar rutas, certificados o credenciales FC2.
