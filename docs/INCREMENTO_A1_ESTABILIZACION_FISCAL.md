# Incremento A1 — Estabilización fiscal

Fecha: 24 de julio de 2026.

## 1. Objetivo

Estabilizar configuración, selección de adaptador, intentos durables, idempotencia, proyección de estados, resultado de timbrado y esquema PDF antes de implementar el vault CSD o el orquestador “Generar factura”.

Este incremento no realizó llamadas a TimbradorXpress ni modificó XML, certificados, firmas, documentos fiscales históricos o secretos.

## 2. Situación inicial

- Base activa: `ikontrol_new`.
- Prefijo: `ikontrol_`.
- Diez documentos fiscales.
- Veinte artefactos.
- Seis firmas.
- Cero timbres.
- Documento 9: `stamping`, sin intento durable presente.
- Documento 10: `stamping`, intento 11 en `sending`.
- Intento 11 sin respuesta confirmada.
- Migraciones `090700` y `090800` pendientes.
- Tabla binary artifacts inexistente en la base principal.
- Vista de resultado consultaba esa tabla incondicionalmente y devolvía 500.
- `Config\Fiscal` no gobernaba el adaptador real.

No se encontró un respaldo reciente verificable dentro del proyecto. Antes de aplicar las migraciones pendientes a la base principal debe respaldarse externamente:

- base `ikontrol_new`;
- `.env`;
- `writable/fiscal`.

No se creó una copia dentro del repositorio para no duplicar secretos o datos privados.

## 3. Archivos del A1

### Creados

- `app/Domain/Fiscal/Pac/FiscalDocumentStatusView.php`
- `app/Domain/Fiscal/Pac/FiscalStampingResult.php`
- `app/Services/Fiscal/Pac/FakePacAdapter.php`
- `app/Services/Fiscal/Pac/FiscalDocumentStatusPresenter.php`
- `app/Services/Fiscal/Pac/FiscalPacAdapterFactory.php`
- `tests/IncrementA1/run.php`
- `tests/IncrementA1/migration.php`
- `tests/IncrementA1/projection.php`
- este documento

### Modificados en A1

- `.env.example`
- `app/Config/Fiscal.php`
- `app/Controllers/Fiscal/InvoiceReview.php`
- `app/Controllers/Fiscal/Stamping.php`
- `app/Database/Migrations/2026-07-29-090700_CreateFiscalBinaryArtifacts.php`
- `app/Services/Fiscal/Pac/FiscalStampingService.php`
- `app/Views/fiscal/invoices/draft.php`
- `app/Views/fiscal/pac/result.php`
- pruebas del Incremento 9 adaptadas a resultados tipados/factory
- auditoría general, únicamente con una referencia a este incremento

Los demás cambios visibles en Git ya existían al comenzar A1.

## 4. Autoridad de configuración

`Config\Fiscal` es ahora la autoridad maestra:

- `enabled`: habilita el dominio operativo;
- `environment`: ambiente lógico general;
- `allowRealPac`: autoriza o bloquea adaptadores externos;
- `pacAdapter`: selecciona `fake` o `timbradorxpress`;
- `stampingSendingStaleMinutes`: umbral exclusivamente visual para intentos antiguos.

`Config\TimbradorXpress` conserva únicamente:

- ambiente específico del proveedor;
- API key;
- URL allowlisted;
- timeouts;
- guarda de producción;
- límites técnicos.

Ni el controlador ni `FiscalStampingService` construyen directamente `TimbradorXpressRestAdapter`.

## 5. Factory de adaptadores

`FiscalPacAdapterFactory` es el único punto de construcción del adaptador REST.

### Fake

Requiere:

```dotenv
fiscal.enabled = true
fiscal.pacAdapter = fake
```

No abre sockets, no crea cliente HTTP y no depende de API keys.

### TimbradorXpress

Requiere simultáneamente:

- módulo fiscal habilitado;
- `allowRealPac=true`;
- adaptador `timbradorxpress`;
- ambiente maestro `sandbox`;
- ambiente del proveedor `sandbox`;
- producción deshabilitada;
- endpoint allowlisted;
- API key presente.

Cualquier contradicción se detiene antes de crear el intento.

## 6. FakePacAdapter

Escenarios deterministas:

- `success`;
- `rejected`;
- `timeout_unknown`;
- `transport_not_sent`;
- `persistence_error`.

Todos son locales. `transport_not_sent` indica expresamente `request_sent=false`. `timeout_unknown` indica que el resultado no puede confirmarse. El fixture de éxito puede incluir o no PDF Base64.

## 7. Flujo durable

El contrato quedó:

1. Resolver factory y guardas maestras.
2. Verificar independientemente el XML firmado.
3. Abrir transacción corta.
4. Bloquear `fiscal_documents` con `FOR UPDATE`.
5. Validar documento, firma, certificado y artefacto.
6. Calcular idempotency key.
7. Consultar intento existente.
8. Insertar intento `pending`.
9. Verificar un ID real.
10. Cambiar documento a `stamping`.
11. Confirmar transacción.
12. Cambiar el intento exacto a `sending`.
13. Sólo entonces invocar el adaptador.
14. Persistir resultado sobre el mismo attempt ID.

Si el intento no se inserta o no obtiene ID, el adaptador no se invoca.

## 8. Idempotencia

La clave usa:

```text
document_id
| signed_xml_sha256
| provider
| environment
| operation
```

La restricción única permanece en base. Un segundo clic devuelve el intento existente y no vuelve a invocar el adaptador.

Reglas:

- `pending/sending`: devolver intento existente;
- `unknown/reconciliation_required`: devolver intento existente, sin reenvío;
- rechazo con el mismo XML: no reenviar automáticamente;
- documento timbrado: no crear otro intento;
- un XML distinto sólo puede provenir de una nueva versión fiscal válida.

## 9. Persistencia posterior

### Éxito

- valida XML timbrado;
- conserva contingencia cifrada;
- persiste artefacto XML;
- persiste timbre/UUID;
- actualiza el intento;
- cambia documento a `stamped`;
- persiste PDF opcional.

### Rechazo confirmado

- intento `rejected`;
- código y mensaje sanitizados;
- documento `stamping_error`;
- sin UUID/timbre.

### Timeout o resultado incierto

- intento de clase desconocida;
- `requires_reconciliation=1`;
- documento `stamp_status_unknown`;
- no reenvío.

### Transporte confirmado como no enviado

- intento `transport_not_sent`;
- documento `stamping_error`;
- se distingue de timeout.

### Error de persistencia posterior

- intento `reconciliation_required`;
- documento `stamp_status_unknown`;
- conserva contingencia;
- nunca reenvía automáticamente.

## 10. Resultados tipados

`FiscalStampingResult` sustituye arrays ambiguos en el servicio principal. Expone únicamente:

- success/status/stage;
- IDs;
- código y mensaje del proveedor;
- HTTP status;
- UUID;
- retryable;
- requiresReconciliation;
- acción recomendada;
- disponibilidad XML/PDF.

No contiene API key, XML, PDF Base64, contraseña, certificado o llave.

## 11. Proyección única de estados

`FiscalDocumentStatusPresenter` reúne, en modo sólo lectura:

- documento;
- firma;
- último intento;
- timbre;
- XML timbrado;
- PDF.

Estados visibles:

| Estado | Condición principal |
|---|---|
| `draft` | documento aún en preparación |
| `ready` | firmado/listo y sin intento activo |
| `processing` | `stamping` con intento reciente |
| `stamped` | timbre con UUID y XML válido |
| `correctable_error` | rechazo o transporte confirmado no enviado |
| `unknown` | timeout, conciliación, `stamping` huérfano o intento antiguo |
| `cancelled` | cancelado o reemplazado |

La proyección no modifica base.

## 12. Documentos 9, 10 e intento 11

Verificación real de sólo lectura:

| Documento | Persistido | Intento | Proyección | Reenvío |
|---|---|---|---|---|
| 9 | `stamping` | ninguno | `unknown` | bloqueado |
| 10 | `stamping` | 11 / `sending` | `unknown` | bloqueado |

Mensaje:

> No fue posible confirmar el resultado del intento de timbrado. No reenvíe este CFDI hasta completar una conciliación.

No se cambiaron documento 9, documento 10 ni intento 11.

## 13. Umbral de `sending`

Configuración:

```dotenv
fiscal.stampingSendingStaleMinutes = 5
```

El umbral sólo afecta la proyección. No cambia el intento, no ejecuta cron, no consulta PAC y no autoriza reenvío.

## 14. Migración PDF

Se corrigió `090700` porque:

- sigue sin aplicarse;
- es un archivo nuevo no rastreado del desarrollo actual;
- no existe evidencia de distribución o aplicación compartida;
- el fallo impedía cualquier `migrate`.

La causa era pasar un cuarto argumento no soportado a `Forge::addKey()` en CI 4.6.1.

La migración fue validada desde cero en base aislada:

- crea `fiscal_document_binary_artifacts`;
- usa `LONGTEXT` para Base64;
- crea protección única documento/tipo;
- indexa intento y UUID;
- agrega `pac_pdf_artifact_id`;
- agrega `pdf_status`;
- agrega `pdf_template`;
- crea cero datos/PDF.

No se aplicó a `ikontrol_new` porque no se pudo verificar un respaldo reciente. Las migraciones `090700` y `090800` continúan pendientes en la base principal.

## 15. Vista de resultado

`Stamping::result()`:

- recibe `document_id`;
- usa una proyección de sólo lectura;
- tolera intento, timbre, XML o PDF ausentes;
- consulta binary artifacts sólo si la tabla existe;
- muestra estado visible/interno, intento, fecha, proveedor, ambiente, HTTP, código/mensaje, UUID, disponibilidad y conciliación.

La vista se probó con documento incompleto y sin PDF en base aislada sin errores de null.

## 16. Errores AJAX

El endpoint de timbrado devuelve una estructura controlada:

- `success`;
- `stage`;
- `status`;
- `code`;
- `message`;
- `retryable`;
- `requires_reconciliation`;
- `action`;
- DTO sanitizado.

Las excepciones técnicas se registran por tipo y document ID. No se devuelve stack trace ni mensaje interno crudo.

## 17. Pruebas

Estrategia:

- bases MySQL temporales creadas por `isolated_database.php`;
- almacenamiento bajo el directorio temporal del sistema;
- adaptadores fake o clientes HTTP en memoria;
- cero uso de documentos 9/10;
- cero llamadas reales;
- cero consumo de IDs en `ikontrol_new`.

Resultados:

- todos los `run.php` de Incrementos 0, 2–9 y A1: aprobados;
- todas las integraciones/HTTP/caracterizaciones ejecutables: aprobadas;
- Incremento 9 DB: 39/39;
- A1 configuración: 8/8;
- A1 migración: 16/16;
- A1 proyección/vista/HTTP: 10/10;
- lint: 49 archivos, 0 errores;
- rutas fiscales: 52 registradas;
- `git diff --check`: sin errores (sólo avisos LF/CRLF).

No se ejecutaron generadores de fixtures/golden como pruebas porque escriben artefactos de referencia.

## 18. Seguridad

Se localizaron valores con apariencia de credenciales en `.env.example`. Fueron retirados y sustituidos por valores vacíos. El `.env` real no fue leído en la salida ni modificado.

Como medida externa prudente, las credenciales que alguna vez estuvieron en un archivo versionable deben rotarse si eran reales.

No se registran:

- API keys;
- XML completo;
- PDF Base64;
- contraseña CSD;
- llave privada.

## 19. Configuración recomendada para A1

```dotenv
fiscal.enabled = true
fiscal.environment = local
fiscal.allowRealPac = false
fiscal.pacAdapter = fake
fiscal.stampingSendingStaleMinutes = 5

TIMBRADORXPRESS_ENVIRONMENT = sandbox
TIMBRADORXPRESS_PRODUCTION_ENABLED = false
```

La presencia de una API key no cambia el adaptador efectivo ni abre red.

## 20. Configuración futura de sandbox

Sólo después de A1 aplicado, respaldo, pruebas y autorización:

```dotenv
fiscal.enabled = true
fiscal.environment = sandbox
fiscal.allowRealPac = true
fiscal.pacAdapter = timbradorxpress

TIMBRADORXPRESS_ENVIRONMENT = sandbox
TIMBRADORXPRESS_APIKEY_SANDBOX =
TIMBRADORXPRESS_PRODUCTION_ENABLED = false
```

## 21. Riesgos pendientes

- Aplicar `090700/090800` a la base principal después del respaldo.
- Conciliar documentos 9/10 sin reenvío.
- Investigar la desaparición histórica de intentos 1–10.
- Probar `Stamping::result()` por HTTP autenticado tras aplicar esquema principal.
- Definir una operación oficial de recuperación exclusiva de PDF.
- Mantener producción bloqueada.
- Rotar posibles credenciales que estuvieron en `.env.example`.

## 22. Integración para A2

A2 deberá:

- crear un vault dedicado a contraseña CSD;
- usar una llave distinta de `fiscal.pacEncryptionKey`;
- versionar cifrado/rotación;
- descifrar sólo en memoria;
- eliminar la captura repetida durante facturación;
- no cambiar la factory PAC ni la proyección A1.

## 23. Integración para B

El orquestador futuro deberá reutilizar:

- readiness y preparación de precios;
- snapshot;
- XML/XSD;
- firma;
- `FiscalPacAdapterFactory`;
- `FiscalStampingService`;
- `FiscalStampingResult`;
- `FiscalDocumentStatusPresenter`.

No debe construir adaptadores ni deducir estados directamente.

## 24. Confirmaciones

- Cero llamadas externas.
- Cero llamadas a TimbradorXpress.
- Producción bloqueada.
- Documentos 9/10 intactos.
- Intento 11 intacto.
- Sin cambios CSD.
- Sin vault CSD.
- Sin botón único.
- Sin commit.
- Sin push.

## A1.1 aplicado en instalación local principal

Fecha: 24 de julio de 2026.

### Respaldo y base

- Respaldo externo verificado en
  `C:\Users\iKontrol\Backups\ikontrol-A1.1-20260724-174105`.
- El dump de `ikontrol_new`, la copia de `.env` y la copia del almacenamiento
  fiscal existen y no están vacíos.
- Almacenamiento fiscal privado usado realmente por los servicios:
  `C:\xampp\htdocs\ikontrol2\ikon2.0\writable\fiscal`.
- Base activa: `ikontrol_new`.
- Prefijo: `ikontrol_`.

### Configuración segura aplicada

```dotenv
fiscal.enabled = true
fiscal.environment = local
fiscal.allowRealPac = false
fiscal.pacAdapter = fake
fiscal.stampingSendingStaleMinutes = 5

TIMBRADORXPRESS_ENVIRONMENT = sandbox
TIMBRADORXPRESS_PRODUCTION_ENABLED = false
```

Las API keys locales no se imprimieron, borraron, modificaron ni consumieron.
La factory resolvió `FakePacAdapter`; no construyó
`TimbradorXpressRestAdapter` ni un cliente HTTP real.

### Migraciones

Se ejecutó:

```text
php spark migrate
```

Las migraciones `090700` y `090800` quedaron aplicadas juntas en el lote 14.
El esquema resultante contiene:

- tabla `ikontrol_fiscal_document_binary_artifacts`;
- índice único por documento y tipo de artefacto;
- índices para `stamp_attempt_id` y `uuid`;
- columnas `pac_pdf_artifact_id`, `pdf_status` y `pdf_template` en
  `ikontrol_fiscal_document_stamps`.

La tabla binary artifacts quedó con cero filas. La migración `090700` no
define claves foráneas explícitas; conserva relaciones lógicas e índices. Esta
limitación debe revisarse antes de endurecer integridad referencial en una
migración futura, sin reescribir la migración ya aplicada.

### Estado histórico preservado

Antes y después de migrar:

| Registro | Estado | Relación | Resultado |
|---|---|---|---|
| Documento 9 | `stamping` | sin intento, sin timbre | fila idéntica por SHA-256 estructural |
| Documento 10 | `stamping` | intento 11, sin timbre | fila idéntica por SHA-256 estructural |
| Intento 11 | `sending` | documento 10 | fila idéntica por SHA-256 estructural |

Los conteos principales permanecieron en 11 documentos, 1 intento y 0 timbres.
No se ejecutó conciliación, reenvío o modificación manual.

### Pruebas HTTP de humo

- Resultado documento 9: HTTP 200, estado `unknown`, conciliación requerida,
  reenvío bloqueado y PDF no disponible.
- Resultado documento 10: HTTP 200, intento 11 visible, estado `unknown`,
  conciliación requerida, reenvío bloqueado y PDF no disponible.
- Documento 11, existente y sin intento: HTTP 200, resultado controlado, sin
  UUID, sin PDF y sin error 500.

Las pruebas usaron una sesión administrativa en el arnés HTTP de CodeIgniter y
no ejecutaron acciones de timbrado o conciliación sobre estos documentos.

### Pruebas con FakePacAdapter

Todas las operaciones de escritura de prueba utilizaron bases MySQL temporales:

- `success`: intento durable con ID positivo antes del adaptador, una llamada,
  XML de fixture, UUID de fixture y timbre persistido;
- segundo envío del mismo documento: devuelve el intento existente, sin otra
  llamada;
- `rejected`: intento rechazado, código y mensaje controlados, sin UUID ni
  timbre;
- `timeout_unknown`: estado desconocido, conciliación requerida y segundo
  envío bloqueado;
- éxito sin PDF: conserva estado timbrado, XML y UUID; no crea binary artifact
  y la vista sigue siendo válida;
- fallo posterior de persistencia: conciliación requerida y sin reenvío.

Resultados:

- integración fiscal/PAC fake: 41/41;
- autoridad de configuración A1: 8/8;
- migración aislada: 16/16;
- proyección, vista y HTTP autenticado: 10/10.

La prueba inicial añadida para el caso sin PDF reutilizaba accidentalmente un
UUID de fixture ya persistido y activó correctamente la restricción de
duplicados. Se corrigió el fixture para usar un UUID distinto; no fue un fallo
del servicio ni afectó la base principal.

### Red y seguridad

- Adaptador efectivo: `FakePacAdapter`.
- Clientes HTTP reales construidos: 0.
- Llamadas externas: 0.
- Consultas SAT: 0.
- Llamadas a TimbradorXpress: 0.
- Producción permaneció deshabilitada.
- No se mostraron ni registraron API keys, XML, PDF Base64, certificados,
  llaves o contraseñas.

### Riesgos restantes

- Los documentos 9 y 10 continúan requiriendo conciliación manual futura; no
  deben reenviarse.
- El intento 11 continúa en `sending` y debe preservarse hasta definir la
  conciliación.
- `090700` no incorpora claves foráneas físicas.
- El vault dedicado de contraseña CSD corresponde a A2 y todavía no existe.
- Debe mantenerse `allowRealPac=false` y `pacAdapter=fake` hasta una
  autorización posterior explícita.

No se realizó commit ni push durante A1.1.

## Seguimiento A2

El vault dedicado para contraseña CSD, separado de la contingencia PAC, se
implementa y documenta en `docs/INCREMENTO_A2_VAULT_CSD.md`. Esta referencia no
modifica los hallazgos ni resultados históricos de A1/A1.1.
