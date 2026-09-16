# Incremento C2.3.1 — Integración real local

Fecha: 2026-07-30  
Base: `ikontrol_new` (`ikontrol_`)  
Resultado de la prueba real: rechazo conocido PAC `701`, créditos de prueba insuficientes.

## Respaldo

Se creó `writable/backups/c2_3_1_real_integration_20260730_084020`.
El SQL mide 9,257,121 bytes y su SHA-256 es
`7bf819ff5acd9519e1509c790e1675c0e762241a4c167876c3f8703f9e086152`.
El inventario contiene 388 archivos. El respaldo no expone secretos en este documento.

## Configuración

La configuración anterior impedía la integración porque usaba adaptadores fake y
`fiscal.allowRealPac=false`. La configuración efectiva final es:

- `fiscal.runtimeMode=integration`;
- `fiscal.environment=development`;
- `fiscal.allowRealPac=true`;
- `fiscal.pacAdapter=timbradorxpress`;
- PAC `sandbox` en el endpoint development oficial;
- PDF `timbradorxpress-tools`, WSTools33 habilitado y plantilla de ingreso `1`.

`production` exige configuración productiva coherente. `automated_test` sólo se
admite en `ENVIRONMENT=testing`, por CLI. El navegador no puede seleccionar fake.
La API key, credenciales WSTools y contraseña CSD sólo se leen del entorno o del
almacén cifrado; no se documentan.

## Emisor, CSD y serie

`fiscal:integration:prepare` validó y preparó idempotentemente el emisor 2, su CSD
activo y vigente, y la serie `TEST` (id 2) en `development`. Se verificó:

- contraseña CSD utilizable;
- pareja certificado/llave;
- RFC del certificado coincidente con el emisor;
- exportación temporal del PEM en memoria;
- SoapClient y cURL disponibles;
- cliente fiscal y producto configurados.

En Windows/XAMPP, OpenSSL 3 necesitó el `openssl.cnf` explícito para exportar el
PEM temporal. La llave privada no se escribe sin cifrar en disco.

## Flujo comercial

El cierre comercial ya no depende del pago:

- pagada, no pagada y parcialmente pagada pueden quedar `closed`;
- el estado de pago continúa en el campo `status` del modelo actual;
- una venta `closed` no admite cambios estructurales;
- sí admite pagos/abonos y facturación.

Se creó el candidato por modelos y servicios normales:

- venta 57: `closed`, `not_paid`, total 116.00;
- borrador 3: `ready`, snapshot v2, serie TEST;
- un concepto gravado y un impuesto durable;
- asignación 116.000000 en estado `reserved`;
- documento durable 23 creado durante el intento.

No se reutilizaron la venta 56 ni el borrador 2.

## Preflight y operación PAC

El comando seguro:

```text
php spark fiscal:integration:status
php spark fiscal:integration:stamp 3
```

realizó el preflight sin red. La ejecución exige la confirmación literal
`TIMBRAR-DESARROLLO`. El contrato implementado es `timbrarConSello` con API key,
XML CFDI con `Sello` vacío y `keyPEM` sólo en memoria.

Tres bloqueos locales fueron detectados antes del transporte y quedaron como
`transport_not_sent`: exportación PEM sin configuración OpenSSL y mezcla entre
el ambiente documental `development` y el ambiente técnico PAC `sandbox`.
Todos quedaron cerrados y reintentables; ningún camino permanece `sending`.

La única llamada efectiva llegó al PAC development y recibió:

- código `701`;
- rechazo conocido por créditos insuficientes;
- `requires_reconciliation=0`;
- sin UUID;
- sin TimbreFiscalDigital;
- sin XML timbrado;
- sin PDF.

No hubo reintento automático. El borrador volvió a `ready`, la reserva se conserva
y el documento quedó `stamping_error`. Antes de repetir manualmente se deben
habilitar créditos de prueba en la cuenta PAC.

## PDF

Un timbrado exitoso delegará en `FiscalPacPdfGenerationService` y WSTools33.
Se exige plantilla `1`, respuesta `210`, Base64 estricto y PDF válido. No existe
fallback fake. Como el PAC rechazó el CFDI, no se llamó a WSTools33 y no se creó
PDF.

## Fixtures e interfaz

La migración añadió `environment`, `data_origin` e `is_test_fixture`. Cinco
documentos fake confirmados quedaron clasificados como `automated_test`.
El borrador 2 quedó como evidencia previa. Los fixtures se excluyen del listado
normal y del detalle para usuarios sin `fiscal.advanced.view`.

El encabezado muestra `AMBIENTE DE PRUEBAS PAC`. Los documentos reales de
development se presentan como CFDI de prueba, no como simulación.

## Seguridad e idempotencia

- no se imprimieron API keys, passwords, PEM, XML ni PDF Base64;
- `.env`, CSD y artefactos privados permanecen ignorados por Git;
- la selección fake está bloqueada en navegador;
- el comando real requiere confirmación explícita;
- no hay reintentos automáticos tras rechazo o resultado incierto;
- los artefactos previos se conservan hasta validar una nueva versión.

## Pruebas

Se agregó `tests/IncrementC231/run.php` con 24 comprobaciones sin red. También se
ejecutaron las suites Increment00, 02–09, A1, A2, B, C1, C11, C13, C21, C22,
C221, C222, C223 y C23. Las expectativas históricas se alinearon con el contrato
real `timbrarConSello`, el cierre comercial independiente del pago y la prohibición
de fake en navegador.

Resultado final de las suites reejecutadas: sin fallos en el subconjunto corregido;
C2.3 conserva 47/47 y C2.3.1 obtiene 24/24.

## Pendientes

1. Cargar créditos de desarrollo en la cuenta PAC.
2. Repetir manualmente el mismo borrador mediante un nuevo intento explícito.
3. Validar entonces XML CFDI 4.0, Timbre 1.1, UUID y PDF WSTools33.
4. Implementar cancelación real de pruebas en el incremento autorizado posterior.

No se hizo commit ni push.
