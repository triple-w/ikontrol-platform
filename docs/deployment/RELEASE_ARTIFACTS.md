# Artefactos de release de iKontrol

Los artefactos administrados son paquetes delta entre dos commits inmutables. El constructor lee blobs de Git, no el contenido del working tree, y sólo opera cuando el repositorio fuente está limpio y `HEAD` coincide exactamente con `commit_sha`.

## Contrato del manifest

Cada ZIP contiene `updates/<version>/deployment-manifest.json`. El manifest declara el release, los SHA completos de origen y destino, y cada archivo runtime agregado o modificado. `from_sha256` contiene el SHA-256 del blob de la versión anterior; para un archivo nuevo vale `null` y `change` vale `added`. `removed_files` informa eliminaciones para revisión explícita y no autoriza por sí mismo a borrar archivos en una instancia.

El manifest no se enumera a sí mismo porque eso produciría un hash recursivo. El ZIP se valida contra todos los archivos declarados y se publica con un archivo lateral `.sha256`.

El allowlist runtime admite `app/`, `assets/`, `plugins/`, `public/`, `resources/`, `system/`, `updates/`, `index.php` y `spark`. Se excluyen `.env`, `.git`, `writable/`, `files/`, `uploads/`, logs, cache, sessions, backups, documentación, pruebas, herramientas, recibos bootstrap y material privado (`.cer`, `.key`, `.pem`, `.pfx`, `.p12`, `.jks`). El despliegue debe preservar esos paths y cualquier branding o configuración privada de la instancia.

## Construcción de 1.1.5

La especificación verificada está en `tools/release/releases/1.1.5.json`. Su origen canónico 1.1.4 es `00ac0766c549917c4ee8a355062449fd78b64c8f` y su destino de aplicación es `c211b9bac1bab1d01157ebdef05884240e8733e2`.

Desde un checkout limpio y separado del commit destino:

```bash
php /ruta/al/builder/tools/release/build.php \
  --repo=/ruta/al/checkout-limpio-1.1.5 \
  --spec=/ruta/al/builder/tools/release/releases/1.1.5.json \
  --output-dir=/ruta/salida/1.1.5
```

El resultado incluye `ikontrol-platform-1.1.5.zip`, `ikontrol-platform-1.1.5.zip.sha256` y una copia inspeccionable del manifest bajo `updates/1.1.5/`.

## Publicación

1. Verificar que el commit de aplicación 1.1.5 esté en `origin/main` y que las pruebas estén en verde.
2. Crear la referencia anotada `release/1.1.5` apuntando explícitamente al commit de aplicación, sin moverla a un commit posterior de tooling.
3. Crear un checkout limpio y detached de esa referencia.
4. Construir el ZIP contra ese checkout y verificar que `manifest.commit_sha` sea el SHA resuelto por la referencia.
5. Repetir la construcción y comprobar que ambos SHA-256 sean iguales.
6. Crear el GitHub Release desde `release/1.1.5`, adjuntar el ZIP y su `.sha256`, y registrar el checksum en la publicación.
7. iKontrolAdmin debe verificar el checksum del asset, el manifest, el `from_sha256` de cada archivo existente y los paths protegidos antes de reemplazar archivos. Después del despliegue de código debe ejecutar el upgrade dirigido `1.1.4 -> 1.1.5`, que sólo registra la versión.

El comando `ikontrol:upgrade` no distribuye código. La descarga, verificación, staging, reemplazo atómico y rollback de archivos corresponde al deployer de iKontrolAdmin; mientras ese componente no exista, no debe sustituirse con `git pull` en instancias cliente.
