# DEPLOY-P02 — Builder reproducible de Platform

## Objetivo

`ikontrol:release:build` construye el ZIP de código que iKontrolAdmin valida, prepara y despliega. El builder no publica releases, no conecta instancias y no ejecuta upgrades ni migraciones.

La implementación canónica única es `App\Services\Release\ReleaseArtifactBuilder`. `tools/release/ReleaseArtifactBuilder.php` conserva sólo un `class_alias` de compatibilidad y `tools/release/build.php` usa directamente el mismo servicio; no existe un segundo algoritmo de empaquetado.

El build oficial siempre usa los blobs del commit `HEAD` de un checkout limpio. `--allow-dirty` existe sólo para desarrollo: omite el bloqueo del estado Git, pero sigue empaquetando el commit, nunca cambios sin commit.

## Contratos de release

Cada versión requiere dos archivos conscientes en el repositorio:

- `updates/{VERSION}/manifest.json`: manifest lógico consumido desde GitHub por Admin. Declara `schema_version`, `product`, `version`, `channel`, `from_versions`, `migrations`, `commands`, `health_checks` y `requires_backup`. El builder lo valida, pero nunca inventa migraciones o comandos.
- `tools/release/releases/{VERSION}.json`: especificación del build. Declara el `release_id`, el tag `v{VERSION}` y el commit inmutable correspondiente a cada `from_version`, necesario para calcular hashes de origen.

El tag declarado debe existir localmente y resolver exactamente al commit `HEAD` empaquetado. Cada `source_refs` debe resolver a un commit cuya `Version::VERSION` coincida con la versión origen declarada; un SHA existente pero correspondiente a otra versión se rechaza.

El manifest lógico 1.1.5 conserva el upgrade dirigido existente: no declara migraciones ni comandos adicionales, exige backup y mantiene `ikontrol:database-check` e `ikontrol:baseline-check` como comprobaciones de salud.

## Compatibilidad comprobada con iKontrolAdmin

El contrato se contrastó en lectura con `app/Services/Versioning/ReleaseManifestValidator.php`, `ReleaseSyncService.php` y `ReleaseArtifactService.php` del checkout local de iKontrolAdmin:

- el tag debe ser SemVer (`v1.1.5` o `1.1.5`), el nombre del GitHub Release debe coincidir con `release_id`, el asset debe llamarse `ikontrol-platform-{VERSION}.zip` o `ikontrol-{VERSION}.zip` y GitHub debe exponer su digest `sha256:`;
- el manifest lógico admite exactamente los nueve campos enumerados arriba y debe coincidir con versión y canal del GitHub Release;
- el deployment manifest debe coincidir en `schema_version`, `product`, `version`, `release_id` y `commit_sha` con la release sincronizada;
- cada entrada de `files` admite únicamente `path`, `sha256`, `size` y el objeto opcional `from_sha256`; sus claves de versión deben pertenecer a `from_versions`;
- `index.php` y `spark` son obligatorios, los paths protegidos son rechazados y el ZIP debe respetar los límites de tamaño y cantidad configurados por Admin.

### Estado observado de 1.1.5 (2026-10-20)

Platform declara `1.1.5`; su origen `1.1.4` es el commit `00ac0766c549917c4ee8a355062449fd78b64c8f`, que declara efectivamente esa versión. El remoto contiene el tag legacy `release/1.1.5` sobre `c211b9bac1bab1d01157ebdef05884240e8733e2`; Admin no lo descubre porque no es un tag SemVer. No existe `v1.1.5` remoto y la API de GitHub no reportó ningún GitHub Release ni asset publicado.

Por ello no hay un artefacto publicado que pueda sobrescribirse, pero el build formal aún no debe ejecutarse desde `c211b9b` ni desde el `HEAD` actual. Después de revisar y comprometer P02, se debe crear una sola vez `v1.1.5` sobre ese commit final y construir desde su checkout limpio. A partir de la publicación, ese tag, commit y asset son inmutables; cualquier corrección posterior exige otra versión.

## Estructura de salida

```text
dist/
├── ikontrol-platform-{VERSION}.zip
├── ikontrol-platform-{VERSION}.sha256
├── deployment-manifest.json
└── release-build.json
```

Dentro del ZIP existe exactamente un `updates/{VERSION}/deployment-manifest.json`. Su esquema coincide con `ReleaseArtifactService` de Admin:

```json
{
  "schema_version": 1,
  "product": "ikontrol",
  "version": "1.1.5",
  "release_id": "ikontrol-1.1.5-canonical-stamp-wallet-resolution",
  "commit_sha": "<SHA Git de 40 caracteres>",
  "files": [
    {
      "path": "app/Config/Version.php",
      "sha256": "<SHA-256 destino>",
      "size": 261,
      "from_sha256": {
        "1.1.4": "<SHA-256 origen>"
      }
    }
  ]
}
```

`from_sha256` se omite cuando el archivo no existía en una versión origen. Nunca se envía como string ni como `null`. El manifest se ordena por `path`; no se enumera a sí mismo para evitar una dependencia circular. Tanto el manifest lógico como el de despliegue deben medir como máximo 262,144 bytes, que es el límite predeterminado del consumidor; el builder falla antes de crear un ZIP incompatible.

`release-build.json` registra esquema y versión del builder, versión/release/tag, commit, hora del build, nombre y SHA-256 del ZIP, hash del manifest lógico, conteos y si el árbol estaba limpio. No contiene rutas locales ni secretos. Su timestamp no forma parte del ZIP y no afecta la reproducibilidad.

## Archivos incluidos

La distribución es un delta reproducible calculado desde los commits declarados en `source_refs`. Incluye la unión de archivos agregados o modificados respecto de cualquiera de los orígenes compatibles, más `index.php` y `spark` como mínimos exigidos por Admin. La allowlist del runtime incluye:

- `app/`;
- `assets/`;
- `plugins/`;
- `public/`;
- `resources/`;
- `system/`;
- `updates/`;
- `index.php` y `spark`;
- `.ikontrol-template.json` y `.ikontroladmin-diagnostic-tools.json`.

El comando y servicio del builder (`app/Commands/IkontrolReleaseBuild.php` y `app/Services/Release/`) son herramientas de la Base y se excluyen expresamente del payload de instancias, igual que `tools/`.

Cada archivo existente recibe un `from_sha256` por versión origen para que Admin detecte modificaciones locales sin necesitar un ZIP base completo. Las eliminaciones de runtime se rechazan: el contrato actual de Admin no autoriza borrar archivos desconocidos ni ofrece una operación de eliminación declarativa.

## Archivos excluidos y seguridad

Se excluyen `.env`, `.env.*`, `.git/`, `.github/`, `writable/`, `files/`, `uploads/`, logs, cache, sessions, backups, `dist/`, `tests/`, `tools/`, documentación, `node_modules/` y cualquier `vendor/` raíz. El builder rechaza symlinks seleccionados, paths absolutos, `..`, barras inversas, duplicados lógicos y material con extensiones `.cer`, `.key`, `.pem`, `.pfx`, `.p12` o `.jks` dentro de la allowlist.

El deployer no recibe datos, uploads, branding de cliente, credenciales, CSD, configuración PAC, logs, sesiones o recibos runtime. Por ello no puede sustituirlos.

## Tratamiento de dependencias

Platform no tiene `composer.json` ni `vendor/` en la raíz. CodeIgniter 4.6.1 está versionado en `system/`, y las dependencias PHP utilizadas por la aplicación están vendorizadas en `app/ThirdParty/`. Ambos paths son elegibles para el delta; cuando cambian, sus archivos reciben tamaño, SHA-256 destino y hashes de origen.

Un `vendor/` raíz futuro queda excluido deliberadamente. Incorporarlo exige primero definir un lockfile raíz, una instalación reproducible y actualizar conscientemente la allowlist; el builder actual no presupone Composer en las instancias.

## Reproducibilidad y validación

El contenido se lee por objeto Git mediante `git cat-file`, evitando diferencias CRLF/LF del working tree. Las entradas se ordenan por path, usan la fecha del commit como mtime fijo y compresión DEFLATE nivel 9. Dos builds consecutivos con la misma versión de PHP/libzip y el mismo commit producen el mismo manifest y ZIP byte por byte.

Después de cerrar el ZIP, el builder vuelve a abrirlo y verifica:

- checksum final opcional/esperado;
- rutas y symlinks;
- duplicados case-insensitive;
- presencia única del deployment manifest;
- igualdad exacta entre payload y archivos declarados;
- tamaño y SHA-256 de cada archivo;
- presencia de `spark` e `index.php`.

Cualquier inconsistencia termina el comando con código distinto de cero.

## Construcción local

Después de comprometer el manifest lógico, la especificación y el builder:

```bash
git status --short
php spark ikontrol:release:build --version=1.1.5 --json
```

El primer comando debe quedar vacío. Como wrapper independiente para CI también está disponible:

```bash
php tools/release/build.php --version=1.1.5 --output-dir=dist
```

`--allow-dirty` sólo permite pruebas del builder; el artefacto continúa representando `HEAD` y `release-build.json` registra `working_tree_clean=false`. No debe publicarse ese resultado.

Validación local adicional:

```bash
php tests/ReleaseArtifact/run.php
sha256sum -c dist/ikontrol-platform-1.1.5.sha256
```

## Publicación futura en GitHub

1. Ejecutar las pruebas de Platform y `git diff --check`.
2. Confirmar que `Version::VERSION`, el directorio `updates/{VERSION}`, el manifest lógico y la especificación coinciden.
3. Crear el tag `v{VERSION}` sobre el mismo commit registrado por el builder. Admin sólo reconoce tags SemVer con `v` opcional; `release/{VERSION}` no es compatible con su parser actual.
4. Construir nuevamente desde un checkout limpio del tag.
5. Verificar que el `commit_sha` del deployment manifest coincide con el tag y validar el `.sha256`.
6. Crear el GitHub Release con nombre igual al `release_id`, canal/prerelease coherente con el manifest lógico y adjuntar el ZIP. El digest del asset es obligatorio para Admin.
7. Adjuntar opcionalmente el `.sha256` para inspección humana. `deployment-manifest.json` y `release-build.json` quedan como evidencia local; el manifest de despliegue autoritativo ya viaja dentro del ZIP.

Este paquete no crea tags, no hace push y no publica el GitHub Release.
