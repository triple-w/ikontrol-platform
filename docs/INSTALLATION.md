# Instalación

Esta guía instala una instancia nueva de iKontrol. No use este procedimiento para actualizar una instalación con datos.

## Instancia nueva

1. Haga un checkout limpio y prepare el PHP/XAMPP requerido por el proyecto.
2. Copie `.env.example` a `.env`. Defina la URL, conexión MySQL, correo si se usará, y claves de cifrado distintas para CSD y contingencias PAC. No agregue credenciales PAC, CSD, RFC, logos ni datos de otro cliente.
3. Cree una base MySQL vacía con `utf8mb4` y configure `database.default.*` y `database.default.DBPrefix = ikontrol_`.
4. Ejecute, escribiendo la contraseña inicial por stdin:

   ```powershell
   'una-contraseña-larga' | php spark ikontrol:install-canonical --group default --expected-database NOMBRE_BD --admin-name 'Administrador Inicial' --admin-email admin@example.test --allow-empty-install
   ```

   El comando rechaza otro destino, un prefijo distinto y una base con tablas. Importa el baseline RISE incluido, registra el schema canónico, instala la infraestructura SAT e importa los diez artefactos verificados del manifest. Si faltan archivos, checksum o conteos, la instalación falla cerrada.
5. Complete empresa, perfiles fiscales, impuestos comerciales, métodos de pago, cuentas financieras y series antes de operar. Cargue CSD y habilite el ambiente fiscal sólo después de comprobar sandbox.
6. Ejecute `php tests/CleanInstallCanonical/run.php` contra un grupo `clean_build` que apunte a la nueva base.

## Configuración compartida de servicios fiscales

La instalación limpia hereda únicamente valores no secretos y política versionados en `.env.example` y las clases `Config`: identificador del adaptador autorizado, endpoints oficiales por ambiente, timeouts acotados, política TLS/allowlist, proveedor PDF, capacidades habilitables y defaults de plantillas generales. Estos valores son defaults de software; `fiscal.enabled`, timbrado real, PDF externo y producción permanecen cerrados hasta una decisión de operación. La configuración de WSTools33 es independiente y no se necesita para timbrar.

No forman parte de una plantilla común ni del instalador los API keys del PAC, llaves maestras, RFC, CSD, contraseña CSD, series, folios, documentos, mappings fiscales, logos ni saldo del monedero local. La cuenta comercial del proveedor PAC pertenece a la operación central de iKontrol; cada instalación conserva y audita su propio monedero local.

No se distribuyen credenciales maestras desde Git, `.env.example` o una base compartida. Si una transición temporal exige aprovisionarlas por instancia, la operación debe recibir un secreto de corta duración desde un gestor externo, comprobar la identidad de la instancia, escribirlo con permisos de sistema restringidos fuera del control de versiones, auditar actor/instancia/rotación sin registrar el valor y revocarlo al terminar. Este mecanismo no está implementado aquí; no debe sustituirse por copiar `.env`. La alternativa preferida es un broker PAC central con autenticación por instancia y sin exponer la credencial maestra.

Contrato de integración futuro para iKontrolAdmin: consumir un manifiesto de capacidades y configuración no secreta versionado; solicitar al servicio seguro de aprovisionamiento únicamente secretos destinados a una instancia autenticada; aplicar por separado políticas comunes y secretos efímeros; no leer ni copiar datos fiscales o saldo de otra instancia. Esta tarea no modifica Admin.

## Datos incluidos

El baseline incluye un administrador inicial, roles/permisos RISE, compañía vacía, métodos de pago y el impuesto administrativo heredado. El instalador agrega catálogos SAT mínimos. No crea clientes, proveedores, artículos, ventas, cobros, documentos fiscales, cuentas bancarias, series fiscales activas ni branding de cliente. Cree Público en General y Exportación sólo si la operación fiscal de esa instancia los requiere.

## Migraciones y actualizaciones

`ikontrol:install-canonical` es exclusivo para bases vacías. Ejecuta la cadena completa porque los DML históricos son inertes tras verificar ausencia de datos. No use `php spark migrate` ni `db:build-clean` para una instancia existente. Para una baseline 1.0.0 use primero `php spark ikontrol:upgrade:plan --target=1.1.0 --json` y después el upgrade dirigido con backup probado.

Para actualizar una instancia existente, haga backup probado y siga el ledger P02: inspeccione schema y datos, aplique sólo migraciones aditivas/correctivas mediante runner dirigido y valide cada postcondición. `CanonicalizeAdministrativePayments`, `ApplyAdministrativePaymentsToSales` y `PreparePaymentComplementDraftsFromAllocations` son antecedentes de instalación, nunca un paso automático de actualización; contienen reconciliación, vaciado o truncado de datos.

Si falla una instalación nueva, elimine únicamente la base vacía de destino y vuelva a empezar. Si falla una actualización, deténgase, conserve el error y restaure el backup; no altere `migrations` para forzar el avance.

Nunca copie entre clientes `.env`, certificados, llaves, credenciales PAC, bases de datos, XML/PDF fiscales, logos, RFC, usuarios ni documentos comerciales.
