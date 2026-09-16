# Incremento 07: constructor y validación local del Pre-XML CFDI 4.0

## Verificación del Incremento 6

Las migraciones `060000`–`060700` se aplicaron en el lote 8 mediante `php spark migrate`. Las pruebas aisladas de borradores pasaron 52/52. Se confirmó creación, folio transaccional, snapshots, inmutabilidad, estado `locked` y ausencia de XML/PAC/CSD antes de iniciar este incremento.

## Fuentes técnicas

| Fuente | Evidencia y uso |
|---|---|
| Anexo 20 / CFDI 4.0 | Estructura descrita por el XSD oficial del SAT y documentación técnica ya auditada en el proyecto. |
| XSD CFDI 4.0 | `http://www.sat.gob.mx/sitio_internet/cfd/4/cfdv40.xsd`, descargado 2026-07-22. SHA-256 `2489b5b535f5cbc6a6c2db6132620de1833f85372512d01deea62880e700e276`. |
| Catálogos XSD | `catCFDI.xsd`, SHA-256 `6c58936cb77576f839a4d7915953ceaf252b9eb9319f9458fe5bb67ae2bb0bb1`. |
| Tipos CFDI | `tdCFDI.xsd`, SHA-256 `b3b81fe4017b95d5477f23a32f47e8b0571683cfddfff1330508c75e02b504cd`. |
| Catálogos locales | Regímenes, usos, impuestos, factores, productos, unidades, formas, métodos y monedas de los Incrementos 2–6. |
| FactuCare 2 | `D:\GitHub\factucare2-0\app\Http\Controllers\FacturasController.php::generarXmlCfdi40DesdePayload`. Sólo referencia de nombres, namespaces y orden. |
| XML/XSD/XSLT FC2 | No se localizaron archivos XSD, XSLT, cadena original ni XML de ejemplo versionados. |
| PHP XML | `DOMDocument`; libxml 2.10.3. |

Los XSD oficiales no fueron modificados. El cargador externo sólo permite los tres recursos locales conocidos y bloquea red y entidades arbitrarias.

## Mapeo snapshot → CFDI

| Atributo CFDI | Origen | Obligatorio | Transformación |
|---|---|---:|---|
| Version | constante builder | Sí | `4.0` |
| Serie/Folio | `fiscal_documents.series/folio` | Sí | texto |
| Fecha | `fiscal_documents.issue_date` | Sí | `Y-m-dTH:i:s`; nunca usa el reloj al reconstruir |
| FormaPago | `payment_form_code` | Sí para ingreso | directa |
| Sello | no existe | Pendiente | omitido |
| NoCertificado | no existe | Pendiente | omitido |
| Certificado | no existe | Pendiente | omitido |
| SubTotal/Descuento/Total | encabezado snapshot | Sí/condicional/Sí | decimal exacto |
| Moneda/TipoCambio | encabezado snapshot | Sí/condicional | TipoCambio se omite en MXN |
| TipoDeComprobante | `document_type=income` | Sí | `I` |
| Exportacion | `export_code` | Sí | directa |
| MetodoPago | `payment_method_code` | Sí | directa |
| LugarExpedicion | `expedition_postal_code` | Sí | directa |
| Emisor Rfc/Nombre/RegimenFiscal | `fiscal_document_issuers` | Sí | directo |
| Receptor Rfc/Nombre/Domicilio/Regimen/Uso | `fiscal_document_receivers` | Sí | directo |
| ResidenciaFiscal/NumRegIdTrib | receptor snapshot | Condicional | sólo receptor extranjero |
| Concepto ClaveProdServ | `fiscal_document_items.product_service_code` | Sí | directo |
| NoIdentificacion | `identification_number` | No | se omite vacío |
| Cantidad/ValorUnitario/Importe | concepto snapshot | Sí | decimal, sin notación científica |
| ClaveUnidad/Unidad/Descripcion/ObjetoImp | concepto snapshot | Sí/No/Sí/Sí | directo; Unidad se omite vacía |
| Impuestos por concepto | `fiscal_document_item_taxes` | Condicional | Tasa/Cuota/Exento; traslados separados de retenciones |
| Impuestos globales | `fiscal_document_tax_totals` | Condicional | agrupación ya congelada y conciliada |

El mapper rechaza cualquier documento que no esté `locked`, no sea `income` o tenga snapshots incompletos. No consulta perfiles, clientes, productos, impuestos ni ventas vivos.

## Dominio y builder

El modelo independiente reside en `app/Domain/Fiscal/Cfdi40`. `CfdiDraftMapper` transforma snapshots a DTOs. `CfdiXmlBuilder` usa exclusivamente `DOMDocument`.

- Builder: `1.0.0`.
- Esquema: `CFDI 4.0`.
- Namespace CFDI: `http://www.sat.gob.mx/cfd/4`.
- Namespace XSI: `http://www.w3.org/2001/XMLSchema-instance`.
- `schemaLocation`: XSD oficial.
- Sin namespaces de Timbre, Pagos, Comercio Exterior, Carta Porte o Nómina.

Orden:

1. Emisor.
2. Receptor.
3. Conceptos.
4. Impuestos globales.

Los atributos opcionales vacíos se omiten. DOM escapa comillas, ampersand, menor/mayor y UTF-8. Los controles inválidos de XML 1.0 se rechazan.

## Impuestos y aritmética

Se reutiliza `FiscalDecimalCalculator`; no hay conversiones `float`.

- `ObjetoImp=01` no crea nodo de impuestos.
- Tasa cero conserva `TasaOCuota="0.000000"` e `Importe="0.00"`.
- Exento omite `TasaOCuota` e `Importe`.
- Retenciones y traslados permanecen separados.
- El resumen global debe coincidir exactamente con conceptos y encabezado.

Se valida sin tolerancia amplia:

`SubTotal - Descuento + Traslados - Retenciones = Total`

## Validación

### pre_sign_validation

`CfdiSemanticValidator` valida documento, emisor, receptor, conceptos, impuestos, moneda, pago y totales.

### XSD local

`CfdiXsdValidator` usa los tres XSD oficiales locales, `LIBXML_NONET` y una allowlist de recursos. Devuelve errores con línea y columna.

El Pre-XML correcto resulta `schema_pending_signature` porque el XSD exige:

- Sello.
- NoCertificado.
- Certificado.

### full_cfdi_validation

Siempre devuelve `is_valid=false` en este incremento. No puede declararse CFDI completo hasta disponer de CSD, certificado, cadena original y sello.

## Artefactos y seguridad

`fiscal_document_artifacts` registra exclusivamente `pre_xml`:

- ruta privada relativa;
- SHA-256;
- tamaño;
- versión del builder;
- versión/hash del esquema;
- estado y resultado de validación;
- usuario y fecha.

Los archivos se guardan en `writable/fiscal/prexml`, ignorado por Git:

- nombre aleatorio de 48 caracteres hexadecimales;
- escritura temporal + rename atómico;
- no contiene RFC ni folio;
- no es público;
- lectura y descarga sólo mediante controlador y permiso;
- integridad comprobada con `hash_equals`;
- traversal rechazado;
- el XML completo nunca se registra en auditoría.

Generar nuevamente el mismo documento con builder `1.0.0` devuelve el mismo artefacto y hash. Una futura versión podrá crear otro artefacto y marcar el anterior `superseded`.

## Permisos

- `fiscal_xml_preview_generate`
- `fiscal_xml_preview_view`
- `fiscal_xml_preview_download`
- `fiscal_xml_preview_validate`

Los roles existentes no reciben permisos automáticamente. El administrador global conserva acceso conforme a RISE.

## Comparación FactuCare 2

| Elemento | FC2 | iKontrol2.0 | Decisión |
|---|---|---|---|
| Fuente | Payload + tablas vivas | snapshot `locked` | no depender de datos vivos |
| XML | DOMDocument | DOMDocument | conservar herramienta |
| Decimales | usa `float` en factura | decimal string centralizado | no copiar cálculo FC2 |
| Claves fallback | inventa `01010101`/`ACT` en algunos caminos | bloquea snapshot incompleto | no inventar |
| Fecha | puede usar reloj actual | `issue_date` congelado | reproducible |
| CSD | inserta certificado y sello vacío | atributos omitidos | pendiente real |
| PAC | SOAP dentro del flujo | inexistente | fuera de alcance |
| Storage | XML timbrado en BD | archivo privado con hash | artefacto técnico |
| Idempotencia | insuficiente | builder+hash+unique | no duplicar |

## Golden files y pruebas

`tests/Increment07/fixtures/mxn_pue_iva16.xml` contiene el caso dorado sanitizado. Las pruebas nunca lo sobrescriben. Su actualización exige:

```powershell
php tests\Increment07\update_golden.php --confirm
```

Se verifican además MXN/PUE, IVA 16, USD/tipo de cambio, tasa cero, Exento, retención, caracteres especiales, opcionales omitidos, determinismo, hash, XSD, XXE, traversal, permisos, integridad e idempotencia.

Las pruebas de integración usan una base aleatoria y un directorio temporal; ambos se eliminan al finalizar.

### Resultado ejecutado

La validación final ejecutada el 22 de julio de 2026 obtuvo 450 comprobaciones correctas y cero fallos:

- Incremento 0: 58.
- Incremento 2: 11 estáticas, 48 de integración, 21 HTTP E2E, 16 de equivalencia, 4 de listados y 7 de impuestos.
- Incremento 3: 40 estáticas, 47 de integración y 30 HTTP.
- Incremento 4: 18 estáticas y 18 de integración.
- Incremento 5: 13 estáticas, 15 de integración y 8 casos límite.
- Incremento 6: 25 estáticas y 27 de integración.
- Incremento 7: 32 estáticas y 12 de integración.

También pasaron el análisis sintáctico de todos los PHP modificados/nuevos, `php spark routes`, `php spark migrate:status` y `git diff --check`. Al finalizar no quedaron bases aisladas de prueba ni artefactos Pre-XML en el almacenamiento normal de desarrollo.

## Revisión manual

1. Ejecute `php spark migrate`.
2. Conceda los cuatro permisos Pre-XML.
3. Abra una preparación fiscal `locked`.
4. Pulse **Generar Pre-XML**.
5. Confirme validación semántica correcta.
6. Confirme `Validación completa pendiente de certificado y sello`.
7. Abra el visor.
8. Revise Comprobante, Emisor, Receptor, Conceptos e Impuestos.
9. Descargue el archivo y confirme UTF-8.
10. Genere nuevamente y confirme el mismo SHA-256.
11. Confirme que no existe Timbrar, Sellar, PAC ni UUID.
12. Confirme que venta, pagos y folio no cambiaron.

## Límite del incremento

El resultado es un **Pre-XML CFDI 4.0**, no un CFDI válido. No hay PAC, CSD, certificado, sello, TimbreFiscalDigital, UUID, cancelación SAT, correo fiscal, complemento de pago ni nota de crédito.
