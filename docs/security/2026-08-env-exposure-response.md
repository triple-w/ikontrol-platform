# Respuesta al incidente de exposición de `.env` — agosto de 2026

## Resumen técnico

La excepción producida durante el primer intento de actualizar `.env` incluyó el contenido del archivo dentro de una salida técnica. La contraseña temporal de `fc2_reader` mostrada entonces ya fue revocada y sustituida. La auditoría confirma que las demás categorías activas potencialmente expuestas son credenciales TimbradorXpress, una contraseña MultiPAC y dos llaves maestras locales de cifrado fiscal.

La salida persiste en dos archivos de sesión administrados por Codex. Durante esta auditoría, una lectura directa de `App.php` volvió a imprimir en la salida técnica la línea de una llave histórica hardcoded; por tanto esa llave también debe considerarse expuesta en la sesión, aunque ya estaba presente en Git. No se encontró la salida original en logs de CodeIgniter, Apache/XAMPP, temporales XAMPP, historial con valores vigentes ni en el reporte JSON de FC2. También existen once copias históricas de `.env` dentro de `writable/backups`; no fueron creadas por este incidente, pero contienen los mismos secretos vigentes y amplían su superficie de exposición.

No se rotaron llaves maestras, no se descifraron datos y no se modificaron registros fiscales. Tampoco se ejecutaron importaciones FC2 ni migraciones.

## Alcance y método

Fecha de auditoría: 4 de agosto de 2026.

Se revisaron por coincidencia estructural y por coincidencia exacta en memoria, sin imprimir valores:

- `.env` y nombres de variables;
- archivos versionados, staged, diff y archivos no versionados relevantes;
- sesiones locales de Codex del día del incidente;
- `writable/logs`, `writable/reports`, scripts de diagnóstico y respaldos locales;
- logs Apache/XAMPP, temporales XAMPP e historial de PowerShell;
- migraciones, configuración, servicios de cifrado y esquema operativo mediante consultas de sólo lectura;
- los cinco commits más recientes y todos los commits posteriores a la fecha del incidente.

Una coincidencia estructural indica que un archivo contiene sintaxis relacionada con credenciales; no demuestra por sí sola que tenga un valor real. La confirmación de persistencia se realizó comparando valores activos en memoria y emitiendo únicamente ruta y nombre lógico de la variable.

## Persistencia localizada

| Ubicación | Categoría | Riesgo | Persistencia | Acción recomendada |
|---|---|---|---|---|
| Dos JSONL bajo `C:\Users\iKontrol\.codex\sessions\2026\08\04\` | Salida técnica con credenciales externas y llaves locales | Crítico | Confirmada; archivos gestionados por Codex | Preservar como evidencia hasta obtener una copia forense o hash; solicitar eliminación mediante el mecanismo de retención de Codex y rotar credenciales externas |
| `.env` operativo | Secretos operativos vigentes | Alto, esperado | Confirmada y necesaria | No borrar; restringir ACL después de confirmar qué identidad ejecuta Apache/PHP |
| Once archivos `.env`/`env.backup` bajo `writable/backups` | Copias de credenciales externas y llaves locales | Alto | Confirmada; preexistente al incidente | Definir retención; mover a almacenamiento cifrado o generar respaldos sanitizados antes de eliminar copias planas |
| Historial PowerShell | Referencias estructurales a contraseñas | Bajo | Confirmada sólo como nombres/comandos; no coincidieron valores activos | Conservar; no requiere truncado por este incidente |
| `writable/reports/fc2-dold-audit.json` | Reporte FC2 sanitizado | Bajo | No contiene secretos activos ni PEM | Conservar |
| `writable/logs` | Logs CodeIgniter | Bajo | No se encontró la salida ni valores activos | Conservar |
| `C:\xampp\apache\logs` | Logs Apache | Bajo | No se encontró la salida ni valores activos | Conservar |
| `C:\xampp\tmp` | Temporales XAMPP/PHP | Bajo | No se encontraron valores activos | Conservar; aplicar limpieza normal de temporales fuera de este incidente |
| Repositorio Git tracked/staged/diff | Código, configuración de ejemplo y documentación | Medio | No contiene los secretos activos del `.env`; sí existe una llave histórica hardcoded distinta | Tratar la llave histórica como deuda de seguridad independiente y rotarla mediante un cambio compatible |

No se encontró un archivo temporal de `.env` creado por el intento fallido: la escritura fue rechazada antes de crear o reemplazar el archivo.

## Credenciales potencialmente expuestas

### A. Rotación inmediata

| Categoría | Variable o identidad lógica | Estado | Acción |
|---|---|---|---|
| TimbradorXpress sandbox | `TIMBRADORXPRESS_APIKEY_SANDBOX` | Potencialmente expuesta y aún configurada | Revocar/regenerar en el proveedor y actualizar sólo `.env` |
| TimbradorXpress producción | `TIMBRADORXPRESS_APIKEY_PRODUCTION` | Potencialmente expuesta y aún configurada | Revocar/regenerar aun si producción está deshabilitada |
| MultiPAC | `MULTIPAC_TOOLS_PASSWORD` | Potencialmente expuesta y aún configurada | Cambiar en el proveedor y actualizar sólo `.env` |
| Usuario FC2 de lectura | `FC2_DB_PASSWORD` | La contraseña mostrada fue revocada | No requiere otra rotación por la salida original; conservar la nueva sólo en `.env` |

No existen actualmente variables SMTP, FTP/SFTP, tokens de almacenamiento ni otras API keys externas en el `.env` revisado. La contraseña de la base principal está definida, pero no contenía una credencial activa en el archivo auditado.

### B. Rotación condicionada: no cambiar todavía

| Llave | Dependencia | Riesgo de cambio directo |
|---|---|---|
| `fiscal.csdEncryptionKey` | Un secreto CSD activo en base | Perder la capacidad de descifrar la contraseña CSD y firmar CFDI |
| `fiscal.pacEncryptionKey` | Cinco archivos de contingencia PAC y cinco intentos relacionados | Impedir conciliación/lectura de contingencias existentes |
| `Config\App::$encryption_key` | Codificación reversible de IDs mediante `encode_id`/`decode_id` | Invalidar enlaces o identificadores codificados existentes |

No existe una variable `APP_KEY` en el `.env` actual. Existe, en cambio, una llave histórica hardcoded en `app/Config/App.php`. Está presente en el historial desde el commit `467fc691f372fd40fb16dbe603d8bb0b626e3732`; este hallazgo es preexistente y no fue introducido por el incidente ni por un commit nuevo.

### C. Datos de configuración que no son secretos

Hosts, puertos, nombres de base, URLs públicas, nombres de usuario sin contraseña, adaptador, flags de ambiente y opciones `enabled` no requieren rotación por sí solos. Pueden aportar contexto operativo, por lo que deben evitarse en reportes públicos, pero no sustituyen una credencial.

## Dependencias criptográficas confirmadas

### Contraseña privada del CSD

| Elemento | Estado comprobado |
|---|---|
| Variable maestra | `fiscal.csdEncryptionKey` |
| Autoridad de configuración | `Config\Fiscal` |
| Vault | `App\Services\Fiscal\Signing\CsdSecretVault` |
| Algoritmo | AES-256-GCM autenticado |
| Formato del payload | JSON `csd-secret-v1`, versión interna 1, algoritmo, nonce, tag y ciphertext |
| Tabla/columna | `ikontrol_fiscal_issuer_certificate_secrets.encrypted_payload` |
| Registros afectados | 1 activo |
| Auditoría relacionada | 9 registros en `ikontrol_fiscal_issuer_certificate_secret_audit` |
| Servicio de persistencia | `CsdCertificateSecretService` |
| Detección de formato | `encryption_version=csd-secret-v1` y campos internos del JSON |
| Identificador de llave maestra | No existe actualmente |
| Soporte dual-key | No existe actualmente |

`ikontrol_fiscal_issuer_certificates.encryption_key_version=password-v1` describe el esquema del material privado, pero no identifica de forma fiable la llave maestra usada para cifrar `encrypted_payload`. El payload permite detectar formato y algoritmo, no cuál llave maestra lo produjo.

El servicio ya cifra y actualiza dentro de transacciones y registra configuración, actualización, fallo de validación y fallo de descifrado. No existe una operación por lote que pruebe llave anterior, recifre y asigne una versión nueva.

### Contingencia PAC

| Elemento | Estado comprobado |
|---|---|
| Variable maestra | `fiscal.pacEncryptionKey` |
| Vault | `App\Services\Fiscal\Pac\PacSecretVault` |
| Persistencia | `PacContingencyStorageService` |
| Derivación | SHA-256 de la llave configurada para obtener material binario |
| Cifrado | Encrypter CodeIgniter/OpenSSL; configuración AES-256-CTR con autenticación HMAC SHA-512 |
| Archivos afectados | 5 bajo `writable/fiscal/pac-contingency` |
| Referencias | 5 registros de `fiscal_stamp_attempts.contingency_path` |
| Versión o key ID | No existe actualmente en archivo ni tabla |
| Soporte dual-key | No existe actualmente |

La tabla `ikontrol_fiscal_pac_configurations` conserva una columna histórica `encrypted_api_key`, pero contiene 0 registros y el flujo actual deprecó las credenciales PAC en base. Las API keys activas de TimbradorXpress se leen desde entorno y pueden rotarse sin recifrar tablas.

### Llave histórica de aplicación

`Config\App::$encryption_key` alimenta `get_encrypter()` y se usa en `encode_id()`/`decode_id()`. No se encontró una tabla que almacene esos ciphertexts como datos maestros; pueden existir enlaces, URLs, formularios o referencias externas ya emitidas. No tiene `key_version` ni fallback a una llave anterior.

## Plan seguro de rotación de llaves maestras

### Cambios de soporte necesarios

1. Agregar un identificador explícito de llave, no la llave, para secretos CSD; puede ser `key_version` o `master_key_id`.
2. Añadir el mismo identificador a la metadata de contingencia PAC o a `fiscal_stamp_attempts`.
3. Permitir configuración temporal de llave primaria y llaves legacy por identificador.
4. Hacer que el descifrado seleccione primero la llave indicada y rechace ambigüedades.
5. Crear un comando Spark de inventario y rotación con modos `--dry-run` y `--execute` separados.
6. Generar un reporte sanitizado de registros/archivos no migrables, usando sólo IDs, versión y código de error.
7. Registrar inicio, éxito, fallo y actor de cada rotación sin plaintext ni material criptográfico.

### Secuencia propuesta

1. Generar una llave nueva fuera del repositorio.
2. Mantener temporalmente la llave anterior como `LEGACY/OLD`, con acceso restringido.
3. Iniciar una transacción por lote para registros CSD; para archivos PAC usar escritura a archivo temporal, `fsync` cuando esté disponible y renombrado atómico.
4. Leer cada registro con la llave identificada como anterior.
5. Descifrar únicamente en memoria y limpiar referencias al plaintext al terminar.
6. Cifrar con la llave nueva y nonce fresco.
7. Incrementar `key_version`/`master_key_id` en la misma unidad atómica.
8. Volver a leer y validar autenticación con la llave nueva; para CSD, comprobar además que la contraseña abre la llave privada sin exponerla.
9. Registrar auditoría técnica y conteos de éxito/fallo.
10. Revertir el registro/lote o conservar el archivo original si cualquier validación falla.
11. Ejecutar una segunda pasada completa que demuestre que todos los objetos activos usan la versión nueva.
12. Respaldar de forma cifrada la llave anterior durante una ventana de recuperación definida.
13. Retirar la llave anterior sólo después de validar base, cinco contingencias, procesos de firma y restauración del respaldo.

Para CSD hace falta una migración de metadata y un comando Spark. Para contingencia PAC hace falta metadata versionada y soporte dual-key temporal; la recodificación de archivos no puede depender sólo de una transacción SQL. La llave histórica de aplicación requiere primero inventariar todos los consumidores de IDs codificados y definir una ventana de compatibilidad con decode dual-key.

## Limpieza y conservación

### Artefactos limpiados

Ninguno. No se identificó un log o temporal prescindible que contuviera los valores expuestos. Truncar archivos sin coincidencias no aportaría contención.

### Evidencia conservada deliberadamente

- Dos archivos de sesión Codex: contienen la evidencia primaria; modificarlos durante una sesión activa puede corromper la trazabilidad.
- Once copias históricas de `.env`: forman parte de respaldos más amplios que incluyen base y artefactos fiscales; no se puede afirmar que sean prescindibles sin una política de restauración.
- `.env` operativo: necesario para ejecutar el sistema y expresamente excluido de eliminación.
- Reporte FC2: validado como sanitizado.
- Logs CodeIgniter y Apache: no contienen el incidente y deben conservarse según su retención normal.

Las ACL actuales permiten lectura de `.env` y de los respaldos a grupos locales más amplios que la cuenta operativa. No se cambiaron porque primero debe confirmarse qué identidad ejecuta Apache/PHP. La acción recomendada es restringirlos a administrador, sistema, propietario y cuenta efectiva del servicio, y almacenar respaldos que incluyan secretos en un contenedor cifrado.

## Validación Git

- `.env` está ignorado y no fue agregado a Git.
- `.env.example` contiene únicamente valores vacíos o placeholders para credenciales sensibles.
- No hay archivos staged.
- No hubo commits desde el inicio del incidente.
- La búsqueda exacta no encontró los secretos activos en archivos tracked, documentación, fixtures o pruebas.
- El reporte JSON FC2 no contiene valores activos ni material PEM.
- Los candidatos estructurales encontrados en código y pruebas corresponden a nombres de variables, fixtures explícitamente de prueba o manejo de PEM; no coinciden con credenciales operativas.
- Existe una llave histórica hardcoded en Git desde el commit indicado anteriormente; requiere tratamiento separado y no debe eliminarse sin compatibilidad.

## Limitaciones

- La interfaz y el backend remoto que conservan la conversación Codex no son administrables desde el repositorio; sólo se comprobaron los archivos locales de sesión.
- No se inspeccionaron sistemas del proveedor PAC, por lo que no puede confirmarse si las credenciales fueron utilizadas después de la exposición.
- No se intentó descifrar ningún payload; los conteos y dependencias se obtuvieron por metadata y código.
- No se certificó una política de recuperación para los respaldos locales, por lo que no se eliminaron copias de `.env`.

## Acciones siguientes

1. Rotar inmediatamente las dos API keys TimbradorXpress y la contraseña MultiPAC.
2. Solicitar borrado o reducción de retención de las dos sesiones Codex después de preservar hash/evidencia mínima.
3. Definir una política para los once respaldos con `.env`: reemplazarlos por copias sanitizadas o cifradas y sólo entonces eliminar las copias planas.
4. Confirmar la identidad de Apache/PHP y endurecer ACL de `.env`, `writable/backups` y material fiscal privado.
5. Diseñar y revisar la migración de metadata y comando dual-key antes de rotar `fiscal.csdEncryptionKey` o `fiscal.pacEncryptionKey`.
6. Tratar la llave histórica de `Config\App` mediante compatibilidad dual-key y retiro del valor hardcoded.

## Preguntas que requieren decisión externa

- ¿Cuál es la ventana de retención forense requerida para la sesión Codex?
- ¿Los respaldos locales deben conservar capacidad de restaurar secretos o pueden sanitizarse?
- ¿Qué cuenta de Windows ejecuta Apache/PHP en esta instalación?
- ¿Qué ventana de compatibilidad requieren enlaces e IDs codificados con la llave histórica de aplicación?
