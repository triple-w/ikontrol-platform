# Incremento B — Orquestador único y flujo normal “Generar factura”

## Objetivo y alcance

Este incremento convierte el proceso técnico existente en un flujo normal de tres momentos:

```text
Revisar datos fiscales → Generar factura → Resultado
```

No se agregó lógica fiscal paralela. El orquestador coordina los servicios existentes de readiness, precios, snapshots, XML, firma, verificación y timbrado. En esta etapa se utiliza exclusivamente `FakePacAdapter`; `fiscal.allowRealPac` permanece deshabilitado y no se realizó ninguna llamada externa.

El respaldo previo fue confirmado por el usuario en:

`C:\Users\iKontrol\Backups\ikontrol-B-20260724-211328`

## Auditoría del flujo anterior

| Paso | Controlador | Servicio autoridad | Estado resultante | Acción visible anterior |
|---|---|---|---|---|
| Revisión | `Fiscal\InvoiceReview::show` | `SaleFiscalReadinessService` y `SaleTaxPricingSimulationService` | preparación simulada/confirmada | Revisar |
| Snapshot | `InvoiceReview::create_draft` | `FiscalDraftCreationService` | `ready` | Crear borrador |
| Cierre | `InvoiceReview::draft_action` | `FiscalDraftCreationService::changeStatus` | `locked` | Cerrar |
| Pre-XML | `InvoiceReview::generate_prexml` | `CfdiPreXmlArtifactService` | artefacto `pre_xml` | Generar Pre-XML |
| Firma | `InvoiceReview::sign_xml` | `CfdiSigningService` | `ready_to_stamp` | Firmar |
| Verificación | flujo de timbrado | `SignedXmlVerifier` | válido o error | Verificar |
| Timbrado | controlador técnico fiscal | `FiscalStampingService` | `stamped`, error o unknown | Timbrar |
| Resultado | visor del borrador | `FiscalDocumentStatusPresenter` | proyección visible | Consultar |

Los pasos técnicos siguen disponibles en sus rutas originales. En la interfaz normal se agrupan bajo **Herramientas fiscales avanzadas**, visible sólo al administrador técnico.

## Arquitectura final

### Resultado tipado

`App\Domain\Fiscal\FiscalInvoiceGenerationResult` expone únicamente:

- éxito, etapa y estado;
- IDs del documento e intento;
- códigos y mensajes sanitizados;
- UUID;
- indicadores de reintento, conciliación y corrección;
- acción recomendada y URL del resultado;
- disponibilidad de XML y PDF.

No contiene XML, PDF Base64, contraseñas, ciphertext, API keys, certificados, llaves ni stack traces.

### Orquestador

`App\Services\Fiscal\FiscalInvoiceGenerationService`:

1. valida autorización e ID de venta;
2. adquiere un lock MySQL nombrado por venta;
3. detecta el documento activo y reanuda según su estado;
4. valida configuración del adaptador sin leer `.env` directamente;
5. valida readiness, preparación de precios y CSD;
6. crea y cierra un snapshot cuando no existe;
7. genera el Pre-XML y valida su semántica;
8. firma automáticamente mediante el vault CSD;
9. ejecuta la verificación independiente, incluido XSD completo;
10. delega el intento durable y el adaptador a `FiscalStampingService`;
11. devuelve un resultado tipado.

El Pre-XML sin firma no se trata como CFDI final: los atributos `Certificado`, `NoCertificado` y `Sello` se incorporan al firmar. El XSD final se valida después de esa firma.

No existe una transacción larga alrededor del PAC. Cada servicio conserva sus transacciones locales y `FiscalStampingService` confirma el intento durable antes de invocar el adaptador.

## Ruta y controlador

```text
POST fiscal/invoices/(:num)/generate
App\Controllers\Fiscal\InvoiceReview::generate/$1
Filtro: csrf
Permiso: fiscal_stamp_sandbox
```

El ID de la ruta debe coincidir con el `invoice_id` del formulario. La respuesta JSON incluye un token CSRF renovado y el resultado tipado.

## Reanudación e idempotencia

| Estado real | Comportamiento |
|---|---|
| Sin documento activo | crea snapshot nuevo y continúa |
| `ready` | cierra el snapshot |
| `locked`, sin Pre-XML | genera Pre-XML |
| Pre-XML sin firma | firma automáticamente |
| `ready_to_stamp`, sin intento | crea intento durable y timbra |
| intento pendiente/sending | no crea otro intento |
| `stamp_status_unknown` | no reenvía; solicita conciliación |
| `stamped` | devuelve el resultado persistido |
| error corregible | conserva historial y exige confirmar una nueva versión |

La primera defensa concurrente es `GET_LOCK('ikontrol:fiscal:invoice:{invoice_id}', 0)`. La idempotencia durable del Incremento A1 sigue siendo la segunda defensa por documento, hash, proveedor, ambiente y operación.

## Interfaz

La revisión normal muestra emisor, receptor, serie, precios, impuestos, totales, pagos, moneda, estado del CSD y adaptador activo.

Acciones normales:

- **Generar factura**;
- **Guardar borrador**;
- **Cancelar**.

Durante la generación se deshabilitan los botones y se muestra “Procesando factura”, sin porcentajes ficticios. Un error corregible vuelve a habilitar controles; un resultado unknown permanece bloqueado y no se reenvía.

Las herramientas avanzadas conservan los visores y acciones técnicas existentes: snapshot, Pre-XML, XSD, cadena, XML firmado, firma, intento, resultado, conciliación y descargas, según permisos existentes.

## Errores visibles

`FiscalInvoiceGenerationErrorPresenter` clasifica errores por etapa y devuelve mensajes seguros para readiness, precios, snapshot, XML/XSD, CSD, firma, verificación, configuración, transporte y persistencia. No expone secretos ni excepciones crudas.

Los rechazos conocidos muestran código/mensaje del PAC falso y permiten corregir datos. Los timeouts desconocidos requieren conciliación y nunca son reintentados automáticamente.

## Pruebas aisladas

`tests/IncrementB/run.php` clona la base local en una base temporal con nombre aleatorio, crea exclusivamente ventas y documentos nuevos y elimina la base al terminar. Los artefactos se escriben en un directorio temporal fuera de `writable/fiscal`, eliminado al finalizar.

Casos cubiertos:

- success de una sola acción;
- snapshot completo y firma automática;
- intento durable único;
- UUID y XML timbrado persistidos;
- éxito sin PDF;
- repetición idempotente;
- rechazo conocido sin UUID;
- timeout unknown sin reenvío;
- CSD no listo antes de crear documento o intento;
- lock concurrente;
- cero proyectos;
- adaptador fake y cero red.

Los documentos 9, 10 y 12 y el intento 11 sólo se consultaron para verificar su invariancia; no se usaron como documentos de prueba del orquestador.

## Riesgos y preparación para sandbox

- El lock nombrado depende de MySQL/MariaDB y de la conexión; la idempotencia durable sigue siendo obligatoria.
- Una ejecución interrumpida entre etapas locales debe reanudarse desde el estado persistido.
- Los errores corregibles requieren revisión humana y confirmación antes de crear otra versión.
- El adaptador real continúa bloqueado. Antes de sandbox deben repetirse regresiones, validar el documento de prueba y habilitar explícitamente la configuración de servidor autorizada.

No se modificaron migraciones aplicadas, secretos CSD, API keys ni artefactos históricos.

## Regla operativa posterior (C1)

Desde C1, iKontrol sólo considera operativamente **Timbrada** una factura que conserva XML, UUID y PDF Base64 válido. Un timbre válido sin PDF se proyecta como `stamped_pdf_pending`, nunca se retimbra y requiere una operación de recuperación oficialmente documentada. Véase `docs/INCREMENTO_C1_CENTRO_FACTURAS_PDF_CANCELACION.md`.
