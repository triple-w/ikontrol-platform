# Plan técnico de migración fiscal: FactuCare 2 → iKontrol2.0

> **Fecha de diseño:** 21 de julio de 2026. **Alcance:** diseño; no contiene migraciones ni implementación.  
> **Convención de evidencia:** **Hecho** = comprobado en los documentos o código citado; **inferencia** = conclusión razonable que debe validarse; **recomendación** = diseño propuesto. Los nombres de tablas y clases futuras son provisionales.

## 1. Resumen ejecutivo

**Estado actual.** iKontrol2.0 es RISE CRM sobre CodeIgniter 4.6.1, monolítico y de una base por instalación. Ya resuelve clientes, artículos, cotizaciones (`estimates`), documentos administrativos de venta (`invoices`), pagos, usuarios, roles y archivos. Su esquema inicial está en `install1/database.sql`; `app/Database/Migrations` está vacío. Sus impuestos, VAT/GST/TDS, `taxable`, `tax_id` y `app/Libraries/E_invoice.php` son genéricos: no modelan CFDI mexicano. Importes críticos usan `double`.

FactuCare 2 es Laravel 11 y contiene experiencia práctica en CFDI 4.0, Pagos 2.0, CSD, MultiPAC, XML, PDF, folios y cancelación. Sin embargo, concentra reglas en `FacturasController`, `ComplementosController`, vistas/JavaScript y un trait PAC; calcula partes con `float`, carece de idempotencia durable, mezcla estado fiscal y administrativo y expone riesgos serios (CSD bajo webroot, secretos claros/hardcodeados y validación TLS insegura). No hay DDL fiscal completo en la entrega.

**Viabilidad:** alta si se migra conocimiento y algoritmos caracterizados, no módulos enteros. RISE seguirá siendo dueño de la operación; se agregará un dominio fiscal paralelo, enlazado por IDs explícitos y snapshots inmutables. La primera versión soportará una instalación/una base/una configuración propia, sin multitenancy compartido.

**Riesgos principales:** centavos por `double`, doble timbrado ante timeout/concurrencia, UUID fiscal no persistido, folios duplicados, modelo de pagos insuficiente, secretos expuestos, cancelación SAT simplificada, catálogos sin versión y reglas mezcladas con UI. Se mitigan con decimal exacto, máquina de estados, intento PAC persistido antes de llamar, claves únicas, bloqueo transaccional, reconciliación, storage privado y pruebas doradas/mocks.

**Estrategia recomendada:** mexicanización visual primero; luego precisión administrativa compatible; catálogos/perfiles; modelo fiscal y snapshots; adaptador PAC simulado; CFDI ingreso; artefactos; cancelaciones/egresos; Pagos 2.0; globales; endurecimiento. Las llamadas sandbox quedan al final de cada vertical y las reales sólo después de pruebas puras, de contrato y seguridad.

**No hacer:** no reemplazar RISE por FactuCare; no copiar clientes/productos/ventas/UI de FC2; no copiar controladores completos; no reinterpretar VAT, GST, TDS, `tax_id`, `taxable` ni `E_invoice.php`; no usar `float`/`double` fiscal; no aceptar totales del navegador; no guardar CSD/PEM en webroot; no marcar cancelado sólo por enviar solicitud; no llamar PAC antes de persistir una intención idempotente.

## 2. Principios arquitectónicos

| Principio | Problema que resuelve / aplicación en iKontrol2.0 | Riesgo de ignorarlo |
|---|---|---|
| 1. Separación administrativa/fiscal | `invoices`, pagos y catálogo permanecen operativos; `fiscal_*` representa SAT y se enlaza, no se fusiona. | Regresiones de RISE y semántica fiscal falsa. |
| 2. Fiscal opcional hasta facturar | Cliente/artículo/venta se guardan incompletos; un `FiscalReadinessService` valida al preparar CFDI. | Bloquear cotizaciones/ventas que legalmente no requieren CFDI. |
| 3. Snapshot inmutable | Emisor, receptor, conceptos, impuestos y parámetros se copian al documento preparado; tras timbre sólo eventos/estado. | Cambiar historial al editar cliente/producto. |
| 4. Servidor autoritativo | DTO de entrada aporta selección; servicios recalculan bases, impuestos y total. | Manipulación y divergencia JS/PHP/PAC. |
| 5. Precisión decimal | Cadenas decimales, `DECIMAL` e idealmente BCMath; nunca `float` fiscal. | Rechazo SAT y diferencias de centavos. |
| 6. Redondeo centralizado | Una política versionada define escalas, modo y puntos de redondeo. | Cada pantalla/XML obtiene totales distintos. |
| 7. Idempotencia | Clave por intención + hash de payload + respuesta normalizada. | Dos CFDI por doble clic, retry o timeout. |
| 8. Transacciones DB | Reserva de folio, snapshot, intento y transición se confirman atómicamente; la llamada externa queda fuera, rodeada por estados durables. | Filas parciales o folios perdidos sin trazabilidad. |
| 9. Folios seguros | Contador bloqueado (`SELECT … FOR UPDATE`), unique emisor/serie/tipo/folio y asignación antes del envío. | Duplicidad y carrera entre usuarios. |
| 10. Certificados protegidos | Storage privado, contraseña cifrada con clave fuera de BD, descifrado sólo en memoria y RBAC. | Suplantación fiscal. |
| 11. PAC desacoplado | `PacInterface`, DTOs y errores propios; proveedor/configuración intercambiables. | Dominio sujeto a SOAP y a un proveedor. |
| 12. Auditoría/trazabilidad | Eventos append-only con actor, transición, correlation ID y hashes; requests/responses redactados. | No poder reconciliar ni explicar un timbrado. |
| 13. Archivos privados | XML/PDF/acuse fuera de webroot, metadatos en BD y descarga autorizada. | Exposición de PII y sellos. |
| 14. Pruebas antes de PAC real | Calculadores, XML, estados y contrato usan fixtures/mocks; sandbox sólo valida integración mínima. | Costos, timbres consumidos y pruebas irrepetibles. |
| 15. Instalación independiente | Config, DB, CSD, series, secretos, storage y logs por despliegue; sin `tenant_id`. | Cruce de datos/certificados entre empresas. |

## 3. Límites de responsabilidad

**Administrativo (RISE):** empresa comercial, cliente, contacto, producto/servicio, cotización, pedido, venta/documento de cobro, pago recibido, saldo, futuro inventario, usuario y permisos. Puede operar sin RFC/clave SAT.

**Fiscal:** emisor, receptor fiscal, concepto fiscal, CFDI y versión, impuestos trasladados/retenidos, serie/folio fiscal, CSD, PAC, XML/UUID/PDF, cancelación/acuse, relaciones, egreso, Pagos 2.0 e Información Global.

**Integración:** usar tablas puente y referencias, nunca columnas polimórficas ambiguas. `fiscal_document_sources(fiscal_document_id, source_type, source_id, allocated_subtotal, allocated_tax, allocated_total)` enlaza una o varias `invoices`; `fiscal_document_items.source_invoice_item_id` y opcional `source_item_id` conservan procedencia; `fiscal_profiles.client_id` vincula receptor; `fiscal_profiles.company_id` emisor; `fiscal_payments.source_invoice_payment_id` vincula pago. Mantener PK internas, UUID sólo para identidad SAT y snapshots aunque se borre lógicamente la fuente. FKs se recomiendan donde el legado lo permita; relaciones externas incluyen índice y validación de existencia.

## 4. Mapa de correspondencias

| iKontrol2.0 / RISE | FactuCare 2 | Entidad fiscal futura | Acción | Observaciones |
|---|---|---|---|---|
| `company` | `users_perfil`, `users_info_factura` | `fiscal_profiles` emisor | Crear relación/snapshot | Mantener empresa administrativa; no copiar duplicidad FC2. |
| `clients` | `clientes` | `fiscal_profiles` receptor | Extender por relación | VAT/GST no son RFC; permitir varios perfiles con uno predeterminado. |
| `items` | `productos` | configuración fiscal de artículo + snapshot en `fiscal_document_items` | Extender/crear snapshot | `unit_type`, `taxable`, `rate` no equivalen a SAT. |
| `estimates` | sin valor fiscal autónomo | ninguna directa | Mantener sólo administrativo | Puede originar `invoice`, nunca CFDI directo inicialmente. |
| `estimate_items` | conceptos de UI | ninguna directa | Mantener sólo administrativo | Sólo referencia para conversión. |
| `invoices` | `facturas` | `fiscal_documents` + `fiscal_document_sources` | Crear relación | “Invoice” es venta/documento de cobro, no timbre. |
| `invoice_items` | `factura_detalles` | `fiscal_document_items` | Crear snapshot | No reutilizar totales `double` como autoridad fiscal. |
| `invoice_payments` | `complementos_pagos` | `fiscal_payments`, aplicaciones y complemento | Mantener y evolucionar | Primera fase no rompe FK única; nuevo modelo soporta N:M. |
| `payment_methods` | forma/método en payload | mapeo a `sat_payment_forms` | Crear relación | Medio administrativo ≠ FormaPago SAT; MétodoPago PUE/PPD es otro catálogo. |
| `taxes` | `facturas_impuestos` | reglas/config + `fiscal_document_taxes` | Reemplazar conceptualmente | Mantener impuesto genérico para RISE; no convertir sus IDs. |
| `settings` | `.env`/config/credenciales | config fiscal no secreta + secretos externos | Extender con cautela | No guardar password CSD/API key en texto serializado. |
| `users` | `users` | actores de `fiscal_events` | Reutilizar | Autenticación/actor, nunca emisor fiscal implícito. |
| `roles.permissions` | ownership simple | claves de permiso fiscal | Extender | Compatibilidad serializada ahora; normalización futura. |
| `files`, `general_files` | XML/PDF en BD; CSD en `public/uploads` | `fiscal_files` + storage privado | Crear/aislar | No usar webroot ni serialización genérica para CSD. |
| `client_wallet` | sin equivalente fiscal seguro | ninguno | No utilizar fiscalmente | Es saldo administrativo, no pago ni complemento por sí solo. |
| `E_invoice.php`/templates | constructor CFDI FC2 | servicios CFDI nuevos | No utilizar como CFDI | E-invoice genérico usa VAT/GST/TDS. |

## 5. Terminología mexicana

| Concepto actual | Uso actual | Nombre visible mexicano | Nombre técnico recomendado | ¿Renombrar físicamente? |
|---|---|---|---|---|
| Invoice | Cobro/venta RISE | Venta / documento de cobro | `invoices` | No inicialmente. |
| Estimate | Oferta | Cotización | `estimates` | No. |
| VAT | ID/tasa genérica | Ocultar o “IVA” sólo en UI configurada | `vat_number` legado; fiscal separado | No; jamás mapear automáticamente. |
| GST | ID/tasa genérica | Ocultar en MX | legado | No. |
| TDS | Retención genérica e-invoice | Retenciones (si aplica) | impuestos fiscales detallados | No. |
| Tax | Impuesto genérico encabezado | Impuesto administrativo | `taxes` | No; crear modelo SAT. |
| Taxable | Booleano de línea/artículo | Gravable (administrativo) | legado | No equivale a `ObjetoImp`. |
| ZIP | CP comercial | Código postal | `zip` comercial / `postal_code` fiscal | No. |
| State | Estado libre | Estado | `state` comercial | No. |
| Company | Empresa administrativa | Empresa | `company` | No. |
| Client wallet | Saldo monedero | Saldo a favor | `client_wallet` | No; no es complemento. |
| Credit note | Documento administrativo negativo | Nota de crédito administrativa | `invoices.type=credit_note` | No; CFDI egreso separado. |
| E-invoice | XML genérico | Factura electrónica genérica | `E_invoice` legado | No; aislar de CFDI. |

Una `invoice` será **venta/documento de cobro** mientras no exista `fiscal_document` timbrado; sólo éste será **factura fiscal**. `invoices.type=credit_note` es nota administrativa; una nota fiscal requiere CFDI tipo E relacionado. Priorizar etiquetas/traducciones y ayuda contextual; los renombrados físicos multiplicarían riesgo sin aportar cumplimiento.

## 6. Modelo fiscal futuro

Reglas comunes: PK `BIGINT UNSIGNED`; timestamps UTC; `created_by`; índices en FKs/estado/fecha; charset `utf8mb4`; sin soft-delete en documentos timbrados, eventos, intentos, cancelaciones y artefactos. “Snapshot/inmutable” significa que sólo se escribe hasta `prepared`; después, nuevas versiones/eventos.

| Entidad provisional | Finalidad, relación y campos esenciales | Inmutabilidad, índices/restricciones, sensibilidad/borrado/riesgo |
|---|---|---|
| `fiscal_profiles` | Emisor (`company_id`) o receptor (`client_id`): tipo, RFC, razón social, régimen, CP, residencia, tax ID extranjero, uso predeterminado, vigencia, default. | Unique condicionado por dueño+RFC+vigencia/default; normalizar RFC mayúsculas. Editable/versionable; soft-delete sólo si nunca referenciado. PII. |
| `fiscal_certificates` | CSD de perfil: serial, vigencias, RFC, rutas opacas CER/KEY, password cifrada, fingerprint, estado. | Unique serial/fingerprint; jamás bytes/clave en logs. No borrar si usado; revocar/archivar. Crítico. |
| `fiscal_series` | Serie por emisor/tipo/ambiente, siguiente folio, activa. | Unique perfil+tipo+serie+ambiente; lock fila; `next_folio>0`. No borrar con documentos. |
| `fiscal_documents` | Aggregate CFDI: versión, tipo I/E/P/T/N, status, emisor/receptor snapshot JSON y columnas buscables, serie/folio, UUID, fecha, moneda, TC, forma/método, exportación, lugar, subtotal/descuento/impuestos/total, idempotency key, payload hash, certificado. | Unique UUID (nullable), perfil+serie+folio+tipo, idempotency key; índices estado/fecha/RFC. Snapshot/total inmutable desde preparado; no borrar. PII. |
| `fiscal_document_sources` | N:M con ventas: tipo/id y montos asignados. | Unique documento+tipo+id; suma controlada. No borrar tras timbre. Riesgo de sobrefacturación. |
| `fiscal_document_items` | Conceptos ordenados: source IDs, clave/identificación/unidad, descripción, cantidad, unitario, importe, descuento, ObjetoImp, predial. | Unique documento+line_no; snapshot inmutable; no borrar tras preparado. |
| `fiscal_document_taxes` | Impuesto por concepto o resumen: scope/item, traslado/retención, impuesto, factor, tasa/cuota, base, importe. | Índice documento/item; check de scope/tipo; escalas exactas. Inmutable. |
| `fiscal_related_documents` | TipoRelación y UUID relacionado/sustituto. | Unique documento+tipo+UUID; validar UUID, existencia/propiedad cuando local. Inmutable. |
| `fiscal_files` | XML sellado, PDF, acuse: storage key, tipo, SHA-256, MIME, tamaño, cifrado. | Unique documento+tipo+versión/hash; storage privado; no soft-delete fiscal, retención legal. PII. |
| `fiscal_events` | Auditoría append-only: aggregate, from/to, action, actor, correlation, metadata redactada. | Índice aggregate+fecha/correlation; sin update/delete. Puede contener PII mínima. |
| `fiscal_pac_requests` | Intento durable: operación, idempotency, payload hash, request redactado/referencia cifrada, estado, número intento, timeout, timestamps. | Unique operación+idempotency; índice documento/estado; append/estado controlado, no borrar. Secretos prohibidos. |
| `fiscal_pac_responses` | Respuesta normalizada/cruda protegida, código, mensaje, UUID, hashes, HTTP/SOAP metadata. | Unique request+response hash; no borrar; redacción obligatoria. |
| `fiscal_cancellations` | Motivo, UUID sustituto, estado SAT/PAC, solicitud/acuse, fechas. | Una activa por documento; unique idempotency; append/versionada, no borrar. |
| `fiscal_payment_complements` | Extensión 1:1 de CFDI P: fecha, moneda, TC, totales. | Unique fiscal_document_id; inmutable al preparar. |
| `fiscal_payments` | Cada Pago del complemento: fuente administrativa opcional, fecha, forma, moneda, monto, TC, cuenta/banco opcionales. | Índice complemento/fuente; importes >0; PII bancaria cifrada/minimizada. |
| `fiscal_payment_documents` | DoctoRelacionado: payment, UUID, moneda, equivalencia, parcialidad, saldo anterior/pagado/insoluto. | Unique pago+UUID+parcialidad; checks saldos; inmutable. |
| `fiscal_payment_taxes` | Impuestos DR/P por pago/documento: base, impuesto, factor, tasa/cuota, importe. | Índices payment/document; exactitud y reconciliación de totales. |
| `sat_product_service_keys`, `sat_unit_keys` | Catálogos ClaveProdServ/ClaveUnidad, descripción, vigencia. | Unique clave+vigencia/version; no borrar, desactivar. |
| `sat_tax_regimes`, `sat_cfdi_uses` | Régimen y UsoCFDI con aplicabilidad física/moral. | Unique clave+vigencia; relaciones validadas a fecha. |
| `sat_payment_forms`, `sat_payment_methods` | FormaPago y PUE/PPD (catálogos distintos). | Unique clave+vigencia; no mapear sólo por texto. |
| `sat_currencies` | Moneda y decimales/variación. | Unique código+vigencia. |
| `sat_relationship_types`, `sat_cancel_reasons` | Tipos relación y motivos 01–04/reglas de sustitución. | Unique clave+vigencia; reglas versionadas. |
| `sat_postal_codes` | Validar CP/lugar/zonas cuando el catálogo vigente sea necesario. | Índice CP+vigencia; gran volumen, carga reproducible. Puede diferirse si un validador oficial confiable cubre la regla. |

Tablas de configuración de artículo (`item_fiscal_settings`) y de aplicación de pagos (`payment_applications`) son además necesarias; se detallan abajo. No se asume que estos nombres sean definitivos.

## 7. Estrategia para clientes

`clients` conserva nombre comercial, domicilio/contactos, moneda y gestión. Crear `fiscal_profiles` con uno o varios perfiles receptores: **recomendación:** permitir varios por cliente (sucursales/razones fiscales), con uno predeterminado y vigencias; no duplicar cliente administrativo. Al crear cliente, todo lo fiscal puede faltar.

Al preparar CFDI se exige según tipo/caso: RFC válido (incluidos genéricos), razón social exactamente fiscal, régimen compatible, CP fiscal de 5 dígitos, UsoCFDI compatible, residencia y registro tributario extranjero cuando corresponda. Validar formato/check rules localmente y combinaciones de catálogo vigentes; no afirmar validez SAT sólo por regex.

UI: insignias “Sin perfil fiscal”, “Incompleto”, “Listo” y “Requiere revisión”; panel con lista exacta y enlaces al perfil. La ausencia nunca impide cotizar, vender o cobrar. Al pulsar “Preparar CFDI”, el usuario elige perfil, corrige o usa público general cuando legalmente corresponda. `FiscalSnapshotService` copia todos los datos al documento; editar el cliente después no altera CFDI preparado/timbrado.

## 8. Estrategia para productos y servicios

Mantener `items` y añadir `item_fiscal_settings` (1:N versionable si se anticipan cambios): `item_id`, tipo producto/servicio, ClaveProdServ, ClaveUnidad, unidad comercial, ObjetoImp, descripción fiscal, NoIdentificacion, IVA/IEPS/retenciones como reglas estructuradas, predial/especialidad opcional, vigencias/default.

Al crear artículo sólo siguen obligatorios los campos RISE; lo fiscal es opcional. Para facturar se exige clave y unidad vigentes, descripción/cantidad/unitario válidos, ObjetoImp y configuración coherente de impuestos; predial u otros complementos sólo en supuestos aplicables. La revisión lista cada `invoice_item` y su faltante. El servidor resuelve configuración vigente, permite override autorizado y copia clave, unidad, descripción, cantidades, precios, descuentos, objeto e impuestos calculados a `fiscal_document_items/taxes`. `unit_type`, `taxable` y tasas de encabezado sirven como señal administrativa, nunca como conversión SAT automática.

## 9. Estrategia para ventas y facturas

Cotización → pedido opcional → `invoice` administrativa. El CFDI es otro aggregate. Una venta puede no facturarse. El modelo N:M recomendado permite: varias facturas fiscales por venta (parcial), una factura que agrupe varias ventas del mismo receptor/moneda/condiciones, y ajustes; inicialmente la UI debe habilitar **una venta completa → un CFDI** para reducir riesgo, dejando el esquema listo para N:M.

La asignación fiscal de cada fuente controla `administrative_amount - already_stamped_active_amount`; una restricción/servicio bajo lock impide excederlo. La facturación parcial exige seleccionar partidas/cantidades o montos y reglas explícitas de descuento/impuesto; no se libera hasta aprobar esa política. Cancelar CFDI no cancela la venta ni revierte pagos automáticamente: sólo cambia estado fiscal y libera/ajusta cobertura conforme a política auditada. Sustitución crea nuevo documento relacionado y marca el anterior `substituted` sólo tras cancelación confirmada.

Nota de crédito: `invoices.type=credit_note` sigue administrativa; CFDI E separado, relacionado con UUID(s), con efecto en saldo sólo mediante operación administrativa explícita e idempotente. Para impedir doble timbre: intent key, hash, unique de cobertura activa, lock de fuentes, estado `sending`, UUID unique y reconciliación antes de reintentar.

## 10. Estrategia para pagos y complementos

Conservar `invoice_payments` intacta en fase inicial. Añadir gradualmente:

- `administrative_payments`: cabecera de pago (cliente, fecha, moneda, TC, referencia, total, estado); inicialmente puede ser una vista/adaptador sobre `invoice_payments`.
- `payment_applications`: N:M pago↔`invoices`, monto aplicado y moneda. Los registros legacy se retrorepresentan 1:1 sin migración destructiva.
- mapeo `payment_methods`→`sat_payment_forms`; no confundir forma de pago con PUE/PPD.
- `fiscal_payment_*` como snapshot de un complemento, no como ledger administrativo.

Un pago puede aplicarse a varias ventas; una venta recibe varios pagos. El motor calcula por UUID fiscal: número de parcialidad (última válida + 1 bajo lock), saldo anterior, importe pagado y saldo insoluto, moneda DR/P, TipoCambioP y EquivalenciaDR. No confiar en el saldo actual de RISE ni fijar equivalencia a 1 salvo monedas iguales. Congelar las aplicaciones incluidas; impedir que otro complemento activo las reutilice. Cancelación de complemento no borra pagos: conserva el pago administrativo y habilita sustitución fiscal auditada. Los impuestos DR se extraen del XML origen validado/snapshot y se agregan a impuestos P con reglas Pagos 2.0.

## 11. Impuestos y precisión monetaria

Política propuesta (confirmar límites con Anexo 20/XSD vigente): cantidad `DECIMAL(18,6)`, valor unitario `DECIMAL(20,6)`, importe/descuento/base/impuesto `DECIMAL(20,6)`, tasa/cuota `DECIMAL(18,6)`, total persistido `DECIMAL(20,2)`, tipo de cambio/equivalencia `DECIMAL(20,10)`. Códigos PHP reciben/devuelven **strings decimales**. BCMath puede multiplicar, dividir, comparar y redondear mediante un wrapper; si no está disponible, usar enteros escalados con overflow probado, nunca `float`/`double`.

Secuencia única y versionada: normalizar entrada → importe de concepto a escala permitida → descuento/base → impuesto por concepto según tasa/cuota → redondear conforme al CFDI aplicable → sumar importes ya redondeados → obtener subtotales/total → serializar XML. Presentar dos decimales donde corresponda, sin confundir presentación con precisión de cálculo. El resumen nunca recalcula desde valores visuales.

Una diferencia de centavo no se “corrige” editando total: se rastrea por concepto/base/tasa. En prorrateos, usar mayor residuo con orden estable y registrar ajuste; el total de asignaciones debe coincidir exactamente. Si el XML esperado y el cálculo difieren, bloquear y mostrar diagnóstico.

**Evidencia de riesgo:** RISE almacena `items.rate`, cantidades/totales de partidas, `invoice_payments.amount`, `taxes.percentage` y totales de factura como `double`; FC2 combina `float`, `round(...,2)` y, en Pagos, helpers enteros más fiables. Reutilizar el conocimiento de estos últimos tras extraerlo/probarlo, no sus casts ni redondeos tempranos.

## 12. Arquitectura de servicios CI4

```text
app/
├── Domain/Fiscal/{DTO,Entities,Exceptions,Money,Validation,ValueObjects}
├── Services/Fiscal/
│   ├── FiscalReadinessService.php
│   ├── FiscalSnapshotService.php
│   ├── FiscalCalculationService.php
│   ├── CfdiBuilderService.php
│   ├── InvoiceFiscalService.php
│   ├── PaymentComplementService.php
│   ├── CancellationService.php
│   ├── CertificateService.php
│   ├── ReconciliationService.php
│   └── Pac/{PacInterface.php,MultiPacAdapter.php}
├── Models/Fiscal/*_model.php
├── Controllers/Fiscal/{Documents,Certificates,Series,Cancellations,PaymentComplements}.php
├── Views/fiscal/{documents,profiles,certificates,series,review}/
└── Config/{Fiscal.php,Routes.php}
```

Seguir namespace `App\...`, modelos tipo RISE cuando aporte compatibilidad y controladores delgados sobre `Security_Controller`; agregar rutas fiscales **explícitas** antes de depender del descubrimiento dinámico actual.

| Servicio | Entrada/salida | Dependencias/transacción | Errores y pruebas |
|---|---|---|---|
| `FiscalReadinessService` | IDs fuente/perfil → lista de errores/warnings | repos administrativos, catálogos; sólo lectura | errores por ruta/campo; unitarias de combinaciones. |
| `FiscalSnapshotService` | selección validada → aggregate snapshot | DB/repos/calculador; transacción al preparar | conflicto/obsoleto; integración e inmutabilidad. |
| `FiscalCalculationService` | DTO decimal → totales/impuestos DTO | DecimalPolicy pura | excepción de escala/regla; tablas doradas y propiedades. |
| `CfdiBuilderService` | snapshot → XML canónico/hash | builder, catálogos, CSD metadata; sin DB idealmente | validación/XSD; golden XML. |
| `InvoiceFiscalService` | comando crear/preparar/timbrar → documento | anteriores, series, PAC, eventos; transacciones antes/después de red | estados, conflicto/idempotencia; integración/concurrencia. |
| `PaymentComplementService` | pagos/aplicaciones → CFDI P | XML origen, calculador, PAC; locks de parcialidad | saldo/equivalencia; casos Pagos 2.0. |
| `CancellationService` | documento, motivo, sustituto → solicitud/estado | PAC, eventos, reconciliación | pendiente/rechazo; contrato/polling. |
| `CertificateService` | CER/KEY/password → metadata/handle | OpenSSL, vault/storage; transacción metadatos | mismatch/vencido/password; criptográficas sin exponer clave. |
| `ReconciliationService` | intentos inciertos → resultado | PAC status/download, locks | ambigüedad; timeout/late response. |

## 13. Adaptador del PAC

```php
interface PacInterface {
    public function stamp(StampRequest $request): StampResult;
    public function cancel(CancelRequest $request): CancelResult;
    public function getStatus(StatusRequest $request): StatusResult;
    public function downloadXml(ArtifactRequest $request): ArtifactResult;
    public function downloadPdf(ArtifactRequest $request): ArtifactResult;
    public function getCancellationReceipt(ArtifactRequest $request): ArtifactResult;
}
```

DTOs usan strings decimales, XML bytes/string, UUID value object, credential handle y correlation/idempotency key; no modelos CI ni `Request`. Resultados normalizan `success|pending|rejected|unknown`, código PAC/SAT, mensaje seguro, UUID, XML/PDF/acuse, timestamp, retryable y raw-response reference. Errores tipados: validación, autenticación, certificado, transporte/timeout, rate limit, rechazo fiscal, respuesta inválida y estado incierto.

Timeouts separados de conexión/operación, máximo de reintentos sólo para transporte seguro, backoff+jitter y nunca retry automático de `stamp` sin reconciliar. Log estructurado con correlation ID, operación, duración/código/hash; redactar API keys, KEY, password, XML completo y PII. Config CI4 por instalación/ambiente con allowlist de endpoints, TLS estricto y credenciales fuera del repo/BD clara. Sandbox y producción requieren credenciales/CSD/series físicamente separados y una barrera que impida activar producción accidentalmente.

FC2 alimenta el adaptador con el contrato observado de `MultiPac::callTimbrarCFDI`, `callCancelarPEM`, shapes tolerados, `PacMultiPacTrait` y catálogo `timbradorxpress_errors.php`; no se copian credenciales, constructor Laravel, `trace=true`, WSDL inseguro ni PDF HTTP. `download*` puede ser “no soportado” si el proveedor no ofrece operación: XML local es autoridad y PDF local es preferido.

## 14. Reutilización de FactuCare 2

| Archivo/clase FC2 | Función | Clasificación | Destino propuesto | Cambios requeridos |
|---|---|---|---|---|
| `app/Extensions/MultiPac/MultiPac.php` | Cliente SOAP PAC/PDF | Usar sólo como referencia | `Services/Fiscal/Pac/MultiPacAdapter.php` | CI4 config, TLS, timeouts, DTO, redacción; eliminar secretos y legacy 3.3. |
| `app/Http/Controllers/Traits/PacMultiPacTrait.php` | Puente PAC/CSD | Adaptar conceptualmente | adaptador + `CertificateService` | Separar auth/DB/controller y no persistir PEM claro. |
| `FacturasController::generarXmlCfdi40DesdePayload` | Constructor payload/XML CFDI 4.0 | Adaptar | `CfdiBuilderService` | DTO puro, decimal, catálogos, validación y sin defaults silenciosos. |
| `FacturasController::timbrar` | Orquestación | Usar sólo como referencia | `InvoiceFiscalService` | Estado durable/idempotencia/locks/reconciliación. |
| `FacturasController::cancelar` | Cancelación | Adaptar reglas | `CancellationService` | estados pendiente/aceptación, polling, motivo 01 y sustituto. |
| `ComplementosController::generarXmlPagos20DesdePayload` | Pagos 2.0 | Adaptar | `PaymentComplementService`/builder | corregir multimoneda, separar cálculo y persistir snapshots. |
| helpers `decimalToScaledInt`, `formatScaledInt`, `roundDivide`, `taxAmountFromBase` | Decimal entero | Reutilizar casi directo tras extracción | `Domain/Fiscal/Money` | límites, negativos, overflow, escalas y suite exhaustiva. |
| `extractPago20SourceTaxesFromFacturaXml` | Leer impuestos del XML origen | Adaptar | parser puro | sin DB/helpers, XML seguro (XXE off), namespaces/versiones. |
| agregación `addTras/addRet`, `calculatePagos20Totals` | Impuestos Pagos | Adaptar | calculador fiscal | golden tests, tasas no enteras, moneda/equivalencia. |
| `ConfiguracionController` | Subida/validación CSD | No reutilizar implementación | `CertificateService` | conservar sólo requisitos/formato; storage privado, TLS/local validation, cifrado. |
| `config/timbradorxpress_errors.php` | Catálogo errores | Adaptar | `Config/FiscalPacErrors.php` | confirmar vigencia, alinear operaciones y separar retryable. |
| `resources/views/facturas/create.blade.php` | Campos/UX/payload | Usar sólo como referencia | vistas CI4 review | frontend no calcula autoridad; evitar Blade/JS heredado. |
| `resources/views/documentos/complementos/create.blade.php` | UX Pagos | Usar sólo como referencia | vistas CI4 complemento | idem, más N:M/parcialidades. |
| `resources/views/*/pdf.blade.php` | PDF | Adaptar contenido | vistas CI4/TCPDF | generar desde snapshot/XML, QR/cadena y pruebas visuales. |
| modelos `Factura*`, `Cliente`, `Producto`, `Folio` | Persistencia legacy | No reutilizar | modelos fiscales CI4 nuevos | DDL desconocido, ownership y snapshots insuficientes. |
| catálogos `clave_prod_serv`, `clave_unidad` y APIs | SAT | Usar sólo como referencia | loaders/tablas SAT versionadas | obtener fuente oficial, vigencias y checksum. |
| `FoliosController`, `Api/SeriesController` | Series/folios | Adaptar regla, no clase | `FiscalSeriesService` | reserva previa, unique y lock. |
| controladores Laravel completos | UI+DB+cálculo+PAC | No reutilizar | ninguno | descomponer; copiar perpetuaría acoplamiento. |
| `MultiPac::generarFacturaWhitData` | CFDI 3.3 legacy | No reutilizar | ninguno | obsoleto/dependencias rotas. |
| helpers/modelos genéricos FC2 | Conveniencia Laravel | No reutilizar | equivalentes CI4 puntuales | no importar framework administrativo. |

## 15. Snapshots fiscales

Al pasar a `prepared` congelar:

- **Emisor:** RFC, razón social, régimen, CP/lugar, serie, folio, número/fingerprint/vigencia del certificado (no password/KEY).
- **Receptor:** RFC, razón social, régimen, CP, UsoCFDI, residencia y registro extranjero si aplica.
- **Conceptos:** ClaveProdServ, ClaveUnidad, unidad, NoIdentificacion, descripción, cantidad, unitario, importe, descuento, ObjetoImp, predial y cada base/tipoFactor/tasa-cuota/importe/traslado-retención.
- **Documento:** versión/tipo, moneda, TC, forma, método, exportación, lugar, fecha, subtotal/descuento/impuestos/total y relaciones.

El XML sellado, hash de payload y snapshot deben concordar. Tras timbre, cliente/producto pueden cambiar por corrección o evolución comercial; depender de datos vivos haría que PDF, reimpresión o auditoría contradigan el XML original. La regeneración de representación usa XML/snapshot, nunca joins actuales.

## 16. Estados y máquinas de estado

**Venta administrativa:** estados RISE existentes (`draft`, `not_paid`, derivados pagado/parcial/vencido, `cancelled`, `credited`) permanecen independientes.

**Documento fiscal:** `draft → validated → prepared → sending → stamped`; `validated/prepared → draft` sólo antes de envío y auditado; `sending → recoverable_error|definitive_error|stamped`; `recoverable_error → sending` sólo tras reconciliar; `stamped → cancellation_requested → cancellation_pending → cancelled`; `cancellation_requested → cancelled|cancellation_pending|stamped(rechazada)`; `cancelled → substituted` cuando existe sustituto timbrado/relación confirmada. Nunca volver de `stamped` a editable.

**Solicitud PAC:** `created → dispatching → acknowledged|timeout_unknown|transport_error|rejected`; `timeout_unknown → reconciled_success|reconciled_absent|manual_review`. **Cancelación:** `draft → requested → pending_acceptance → accepted|rejected|expired/unknown`; sólo `accepted` causa `cancelled` fiscal. Estados administrativos, PAC y cancelación no se reutilizan entre sí.

## 17. Idempotencia y concurrencia

1. El cliente crea `idempotency_key` estable por intención; servidor obtiene `payload_hash` canónico.
2. En transacción: lock de serie y fuentes, validar cobertura, reservar folio, crear snapshot/documento/intento `created`; uniques: UUID, emisor+serie+folio+tipo, operación+idempotency, documento+payload activo y aplicaciones fiscales.
3. Commit; cambiar intento/documento a `dispatching/sending` con compare-and-swap (`WHERE status=prepared`). Sólo un proceso gana.
4. Llamar PAC. En éxito, transacción persiste response/XML/hash/UUID/evento y `stamped`; respuesta duplicada con mismo UUID/hash es no-op, distinta es incidente.
5. En timeout, `timeout_unknown`; bloquear timbrado nuevo y ejecutar `getStatus`/descarga/reconciliación. Nunca asumir fracaso.
6. Si UUID llegó pero commit falló, conservar respuesta cifrada temporal/durable del intento, consultar PAC y reconciliar por emisor/serie/folio/hash. Alertar operación.

Doble clic/repetición HTTP recibe el recurso existente. Dos usuarios quedan serializados por locks/unique. Dos series pueden avanzar en paralelo; una serie no. Un folio reservado fallido no se recicla automáticamente. Reintentos llevan correlation ID/número de intento y límites. Cancelaciones usan el mismo patrón.

## 18. Seguridad

CSD en `writable/fiscal-private/{certificate-id}/` o, preferible, storage privado externo; nunca `files/`, `public/` ni nombre predecible. Permisos mínimos del usuario PHP, deny web, cifrado en reposo y backups cifrados. Password CSD y PAC se cifran con una clave maestra fuera de BD (secret manager/variable protegida); no persistir KEY desencriptada, sólo material temporal en memoria/archivo temporal protegido y destruido.

Validar correspondencia CER/KEY/RFC, vigencia y fingerprint localmente con OpenSSL; rotar antes de vencer, conservar metadata del usado, revocar acceso del anterior sin borrar evidencia. Separar secretos sandbox/prod. Descargas requieren permiso, ownership/alcance, respuesta `Content-Disposition`, no URL directa. Auditoría append-only de cargas, cambios, descargas, timbres/cancelaciones; ocultar RFC parcial cuando proceda y jamás loggear contraseñas, API keys, KEY/PEM, XML íntegro o cuentas completas. Definir retención legal, borrado seguro de temporales y restauración probada.

## 19. Permisos

Claves propuestas: `fiscal_settings_view/edit`, `fiscal_certificates_manage`, `fiscal_invoice_create/stamp`, `fiscal_cancel`, `fiscal_credit_note_create`, `fiscal_payment_complement_create`, `fiscal_xml_download`, `fiscal_pdf_download`, `fiscal_pac_logs_view`, `fiscal_retry`, `fiscal_series_manage`, `fiscal_catalogs_manage`.

Compatibilidad inmediata: añadir estas claves al formulario/controlador de roles y al array serializado de `roles.permissions`; `Permission_manager`/`Security_Controller` las resuelve con defaults denegados, admin explícito y pruebas de rol antiguo (clave ausente = sin acceso). Separar crear/preparar de timbrar/cancelar y ocultar raw PAC a soporte autorizado. Futuro limpio: tablas `permissions`, `role_permissions`, `user_permissions` con migración dual-read, backfill, comparación y finalmente escritura normalizada; no hacerlo en la primera vertical fiscal.

## 20. UI y validación contextual

Cliente, artículo o empresa incompletos se guardan normalmente. Mostrar semáforo fiscal en ficha/lista, sin convertirlo en validación administrativa. La acción “Revisión fiscal” abre un asistente:

1. origen/cobertura y perfil receptor;
2. emisor/CSD/serie;
3. partidas con estado por fila y enlace “Corregir artículo”;
4. impuestos/totales calculados en servidor;
5. parámetros CFDI/relaciones;
6. vista previa del snapshot y confirmación.

El resumen agrupa errores bloqueantes y advertencias, usa mensajes exactos (“Partida 3: falta ClaveUnidad”, “Receptor: Régimen 601 incompatible con UsoCFDI …”), preserva datos al corregir y revalida todo en servidor al volver. Empresa incompleta bloquea sólo preparar/timbrar. Venta no facturable explica cobertura previa, moneda incompatible o estado. Confirmación muestra ambiente, RFC, serie/folio tentativo, total e idempotency key; el botón se deshabilita visualmente, pero la protección real es servidor/DB.

## 21. Fases de implementación

| Fase | Objetivo / módulos y entidades | Dependencias, riesgos y pruebas | Aceptación / exclusiones |
|---|---|---|---|
| 0. Seguridad y caracterización | Inventariar secretos; revocación FC2; fixtures sanitizados; caracterizar RISE y algoritmos FC2. | Acceso PAC/docs; pruebas de redondeo/XML/respuestas. | Baseline reproducible y secretos fuera de muestras. Sin cambio funcional/PAC. |
| 1. Mexicanización visual | Traducciones/views de Invoice→Venta, Estimate→Cotización, CP; ayuda sobre fiscalidad. | Regresión UI/rutas. | Operación idéntica, terminología aprobada. Sin renombrar DB. |
| 2. Precisión monetaria administrativa | Introducir DecimalPolicy y nuevas escrituras `DECIMAL` mediante plan compatible. | Perfilado datos/diferencias. Golden totals y regresión. | Totales nuevos exactos y comparados. Sin CFDI. |
| 3. Catálogos SAT | loaders/versionado/modelos `sat_*`. | Fuente oficial/checksums. | Consulta por clave/fecha reproducible. Sin hardcode UI/PAC. |
| 4. Emisor | `fiscal_profiles`, certificados, series; vistas/config. | Storage/cifrado/OpenSSL/RBAC. | CSD validable y no accesible por web. Sin timbrar. |
| 5. Clientes | perfiles receptores y readiness. | Catálogos/UI. | Cliente incompleto opera; revisión exacta. Sin obligar RFC. |
| 6. Productos | `item_fiscal_settings`, impuestos configurables. | Catálogos/decimal. | Artículo incompleto vende; revisión por partida. Sin convertir `taxable`. |
| 7. Modelo/snapshots | documentos, sources, items, taxes, relations, files/events. | Fases 2–6. | Snapshot inmutable y cobertura con tests. Sin PAC. |
| 8. Adaptador PAC | interfaz, fake, MultiPAC adapter, intentos/respuestas/reconciliación. | Contrato/secretos. | Suite contractual fake; timeouts seguros. Sin prod. |
| 9. CFDI ingreso | builder 4.0, estados, revisión/timbrado sandbox mínimo. | 7–8/XSD. | Casos dorados + sandbox controlado, sin egreso/pagos. |
| 10. XML/PDF/descarga | `fiscal_files`, PDF local desde XML, autorización. | XML timbrado/QR. | hashes/descarga/PDF reproducible. Sin storage público/PDF como autoridad. |
| 11. Cancelaciones | motivos, sustituto, estados/polling/acuse. | PAC status. | pendiente/rechazo/aceptado probados. Sin cancelar venta. |
| 12. Relacionados/egresos | relaciones y CFDI E/notas. | ingreso/cancelación. | UUID/tipo/total y efecto explícito. Sin reutilizar credit note como timbre. |
| 13. Pagos/complementos | pagos N:M gradual; `fiscal_payment_*`, Pagos 2.0. | decimal, XML origen, PAC. | parcialidades/multimoneda/impuestos dorados. Sin romper pagos legacy. |
| 14. Público general/Global | receptor genérico, periodicidad/mes/año y control de cobertura. | sources N:M. | ninguna venta duplicada, casos SAT válidos. Sin inferencia automática peligrosa. |
| 15. Endurecimiento/despliegue | auditoría, métricas, backups, runbooks, carga/concurrencia, feature flags. | todas. | checklist seguridad/rollback/reconciliación por cliente. Sin habilitación masiva. |

Cada fase incluye unitarias, integración DB, autorización y regresión del módulo tocado. Ninguna incorpora trabajo de la fase posterior “por conveniencia”.

## 22. Orden de migraciones futuras

Orden: (1) metadata/versiones y catálogos SAT; (2) perfiles; (3) certificados; (4) series; (5) relación/perfiles de clientes; (6) configuración fiscal de artículos; (7) documentos y fuentes; (8) partidas; (9) impuestos; (10) relacionados; (11) archivos; (12) eventos; (13) requests/responses PAC; (14) cancelaciones; (15) complementos/pagos/documentos/impuestos. Crear índices/uniques y FKs en la misma unidad lógica o en paso online separado según volumen.

Como la instalación nace de SQL, introducir CI4 migrations con una **baseline**: tabla de migraciones y migración marcador que verifica fingerprint/columnas del esquema instalado, sin recrearlo. Instalaciones nuevas ejecutan `install1/database.sql` actualizado hasta una versión base y luego migraciones; existentes registran baseline sólo tras preflight/backup. Cada migración es incremental, idempotente en despliegue (no en `up()` arbitrariamente repetido), con dry-run/preflight, backup, rollback probado cuando sea seguro y versión de aplicación mínima. No editar retrospectivamente migraciones liberadas; actualizar el SQL de instalación en releases controlados.

## 23. Estrategia de pruebas

- **Unitarias sin PAC:** ValueObjects, RFC/UUID/decimales, redondeo, impuestos, cobertura, parcialidad, multimoneda, estados, permisos, canonical hash.
- **Integración local sin PAC:** repositorios/transacciones/unique/locks, snapshot, storage privado, XML→parser→PDF, rollback y concurrencia con DB real.
- **Contrato PAC con mock:** todos los DTO/shapes, SoapFault, códigos, timeout antes/después, respuesta tardía/duplicada/incompleta, cancelación pendiente y descarga.
- **Fixtures/golden:** perfiles/CSD de prueba, catálogos versionados, payload JSON y XML canónicos sanitizados, XML sellados sólo de sandbox; comparación semántica/canónica, XSD y reglas de negocio, no timestamps volátiles.
- **Matriz fiscal:** I PUE/PPD; IVA 16/8/0/exento/no objeto, IEPS/retenciones, descuentos y residuo de centavo; E/relaciones; Pagos 2.0 una/muchas facturas/pagos, parcialidades, monedas/TC/EquivalenciaDR/impuestos; público general/Información Global.
- **Resiliencia/seguridad:** doble clic, dos workers/usuarios/folios, UUID sin commit, XML sin PDF, retry/reconciliación, descargas cruzadas, CSD inaccesible, secretos ausentes de logs/backups de prueba.
- **Regresión administrativa:** alta/edición de cliente/artículo, cotización→venta, pagos/saldo, PDF administrativo, roles viejos, reportes.

Sandbox PAC sólo verifica un conjunto pequeño: un ingreso representativo, errores de certificado/payload, status/descarga si existen, una cancelación y un complemento complejo. Producción no se usa para pruebas. Antes de cada sandbox, exigir que el mismo caso pase cálculo, XML/XSD, golden y contrato mock.

## 24. Entornos

| Entorno | Config/certificados/datos | Logs/restricciones/promoción |
|---|---|---|
| Desarrollo | Fake PAC por defecto, CSD sintético/fixture, DB local sanitizada. | Debug redactado; red externa bloqueada. |
| Pruebas locales/CI | Config efímera, mock contractual, secretos falsos, DB limpia. | Sin PAC/red; artefactos de test temporales. |
| Sandbox PAC | Credenciales/CSD de pruebas y series `TEST`; datos ficticios permitidos. | Acceso restringido, cuota y allowlist; nunca secretos prod. |
| Staging | Réplica de versión/config, datos anonimizados, sandbox PAC. | Logs/alertas como prod; prueba migración/restauración. |
| Producción | Credenciales/CSD/series propios por cliente, feature flags apagados inicialmente. | TLS, mínimos privilegios, auditoría/retención; sin debug ni timbres de prueba. |

Promoción: mismo commit/artefacto inmutable → CI → staging → backup/preflight → canary por instalación → habilitar flag. Config/secretos no viajan con código. Catálogos llevan versión/checksum. Rollback de código no revierte ni borra CFDI; debe entender esquemas forward-compatible.

## 25. Despliegue por cliente

Un repositorio base y ramas cortas/releases etiquetados; variantes por configuración, temas, módulos/feature flags o plugins con interfaces, no copias permanentes. Cada instalación tiene subdominio, directorio de release, `.env`/secret store, DB, storage fiscal, CSD, PAC, series, logs y backups independientes. Manifiesto de instalación registra versión de código/esquema/catálogo y features, sin secretos.

Pipeline: backup verificable → maintenance selectivo → preflight PHP/extensiones/espacio/CSD → migrations → smoke administrativo → fiscal fake/readiness → habilitación gradual. Personalizaciones se implementan como extensión versionada con pruebas y compatibilidad declarada. Mantener matriz “cliente→versión→variantes”; prohibir editar core en servidor. Backups cifrados incluyen DB+artefactos+metadata de claves y restauración periódica; claves maestras se respaldan por canal separado. Observabilidad y retención separadas evitan cruce. La base/producto sigue llamándose iKontrol2.0; no se crea multitenancy ni `tenant_id`.

## 26. Riesgos y decisiones pendientes

| Decisión/riesgo | Evidencia | Opciones | Recomendación | Impacto |
|---|---|---|---|---|
| CI4 vs Laravel FC2 | Frameworks distintos | portar clases/copiar app/reimplementar | Reimplementar puertos CI4; extraer algoritmos puros | Alto. |
| SQL vs migrations | `install1/database.sql`; migrations vacías | seguir SQL/baseline | Baseline verificada + incrementales | Alto despliegue. |
| `double` vs decimal | esquema RISE y floats FC2 | tolerar/dual/convertir | Decimal fiscal desde día 1; transición admin fase 2 | Crítico. |
| Modelo pagos | FK única `invoice_payments.invoice_id` | reemplazo/N:M paralelo | N:M paralelo y adaptador legacy | Alto. |
| Facturación parcial | RISE no asigna cobertura | prohibir/partidas/montos | esquema N:M; UI diferida y política aprobada | Alto. |
| Facturas por venta | no existe regla | 1:1/1:N/N:M | N:M en modelo, 1:1 inicial | Alto. |
| Perfiles por cliente | un domicilio legacy | uno/varios | varios versionados, uno default | Medio. |
| Emisores por instalación | `company` puede tener filas; despliegue por empresa | uno/varios | uno activo inicial; esquema soporta varios sólo si negocio aprueba | Alto/CSD/folios. |
| PDF local vs PAC | TCPDF RISE; WSTools FC2 | PAC/local/ambos | local desde XML, PAC opcional comparativo | Medio. |
| Folios | FC2 lock tardío, sin unique comprobado | PAC/DB | reserva DB previa+unique, no reciclar | Crítico. |
| Archivos | RISE paths/serialización; FC2 BD/webroot | BD/disco/objeto | metadata DB + storage privado | Alto. |
| Certificados | FC2 webroot/password clara | filesystem/vault | privado cifrado/vault; PEM sólo memoria | Crítico. |
| Renombrados físicos | nombres RISE extendidos | ahora/nunca | visual primero; físicos sólo proyecto separado | Medio. |
| Rutas dinámicas | autoexpone métodos públicos | dinámicas/explícitas | rutas fiscales explícitas y controladores delgados | Alto seguridad. |
| Permisos serializados | `roles.permissions` | extender/normalizar | extender compatible; normalizar después | Medio. |
| E-invoice genérico | VAT/GST/TDS y plantillas | adaptar/aislar | aislar; no es CFDI | Alto cumplimiento. |
| PAC reutilizable | SOAP/shape útil, secretos/acoplamiento | copiar/rewrite | adaptador nuevo con contrato caracterizado | Crítico. |
| Catálogos | FC2 sin procedencia/versionado suficiente | copiar/fuente oficial | importar fuente oficial, checksum/vigencia | Alto. |
| Cancelación | FC2 éxito inmediato | simple/máquina SAT | pendiente/polling/acuse | Alto. |

Requieren aprobación humana antes de fases 7/9/13: alcance 1:1 inicial y política de parciales/agregación; uno o varios emisores; PAC/contrato vigente; PDF legal; fuente/actualización de catálogos; política de efecto administrativo de cancelaciones/egresos; retención de archivos; estrategia de cifrado/secret manager.

## 27. Lista exacta de archivos futuros

### Archivos nuevos

| Ruta propuesta | Propósito | Fase/dependencias |
|---|---|---|
| `app/Config/Fiscal.php`, `app/Config/FiscalRoutes.php` | config tipada y rutas explícitas | 3–4; env/seguridad. |
| `app/Domain/Fiscal/Money/{Decimal.php,RoundingPolicy.php}` | decimal exacto | 2; BCMath o entero escalado. |
| `app/Domain/Fiscal/ValueObjects/{Rfc.php,Uuid.php,FiscalStatus.php}.php` | invariantes | 3–7. |
| `app/Domain/Fiscal/DTO/{StampRequest.php,StampResult.php,CancelRequest.php,CancelResult.php,StatusRequest.php,StatusResult.php,ArtifactRequest.php,ArtifactResult.php}.php` | contrato PAC | 8. |
| `app/Domain/Fiscal/Exceptions/*` | errores tipados | 7–8. |
| `app/Services/Fiscal/{FiscalReadinessService,FiscalSnapshotService,FiscalCalculationService,CfdiBuilderService,InvoiceFiscalService,PaymentComplementService,CancellationService,CertificateService,ReconciliationService,FiscalSeriesService}.php` | casos de uso | 4–13 según nombre. |
| `app/Services/Fiscal/Pac/{PacInterface,FakePac,MultiPacAdapter}.php` | puerto/adaptadores | 8; contrato/secretos. |
| `app/Models/Fiscal/{Fiscal_profiles_model,Fiscal_certificates_model,Fiscal_series_model,Fiscal_documents_model,Fiscal_document_items_model,Fiscal_document_taxes_model,Fiscal_events_model,Fiscal_pac_requests_model,Fiscal_cancellations_model}.php` | persistencia CI4 | 4–11; migraciones. Modelos restantes siguen tablas de §6. |
| `app/Controllers/Fiscal/{Profiles,Certificates,Series,Documents,Cancellations,Payment_complements}.php` | endpoints delgados | 4–13; permisos. |
| `app/Views/fiscal/{profiles,certificates,series,review,documents,payment_complements}/*.php` | formularios/revisión | fases respectivas. |
| `app/Database/Migrations/<timestamp>_*.php` | esquema orden §22 | futuras fases; **no crear ahora**. |
| `app/Database/Seeds/SatCatalogSeeder.php` o importador versionado | carga reproducible | 3; fuente oficial. |
| `tests/unit/Fiscal/*Test.php`, `tests/integration/Fiscal/*Test.php`, `tests/fixtures/fiscal/*` | suite y goldens sanitizados | desde 0. |

### Archivos existentes a extender

| Ruta | Propósito | Fase/dependencias |
|---|---|---|
| `app/Language/spanish/default_lang.php` | mexicanización/permisos | 1/19. |
| `app/Views/clients/modal_form.php`, `app/Controllers/Clients.php` | pestaña/enlace readiness, no campos SAT mezclados | 5. |
| `app/Views/items/modal_form.php`, `app/Controllers/Items.php` | panel fiscal relacionado | 6. |
| `app/Views/invoices/*`, `app/Controllers/Invoices.php` | etiqueta Venta y acción Revisión fiscal | 1/7/9. |
| `app/Controllers/Invoice_payments.php`, `app/Models/Invoice_payments_model.php` | puente gradual a pago N:M | 13. |
| `app/Views/roles/*`, `app/Controllers/Roles.php`, `app/Libraries/Permission_manager.php` | permisos fiscales | 4/19. |
| `app/Config/Routes.php` | incluir rutas fiscales explícitas | 4/8. |
| `install1/database.sql` | reflejar baseline sólo en release futura | tras migraciones aprobadas. |

### Archivos existentes a aislar

| Ruta | Propósito | Fase/dependencias |
|---|---|---|
| `app/Libraries/E_invoice.php`, `app/Controllers/E_invoice_templates.php`, `app/Models/E_invoice_templates_model.php`, `app/Views/e_invoice_templates/*` | mantener e-invoice genérico fuera de CFDI y ocultarlo/configurarlo en MX | 1/9. |
| helpers de cálculo/PDF administrativo y `app/Models/Invoices_model.php` | envolver como fuentes administrativas; no importar al dominio fiscal | 2/7. |
| rutas dinámicas de `app/Config/Routes.php` | evitar que nuevos métodos fiscales queden expuestos accidentalmente | 4. |

### Archivos que no deben tocarse inicialmente

`system/**`, `app/ThirdParty/**`, conectores de pago Stripe/PayPal/Paytm, proyectos/tareas/tickets/CRM, `install1/database.sql` durante el primer incremento, y módulos FC2 administrativos/controladores/vistas completos. Su propósito no es fiscal; tocarlos antes aumenta regresión. Dependencia futura sólo si una prueba o integración explícita lo justifica.

## 28. Primer incremento implementable

**Incremento recomendado:** “readiness fiscal local de clientes, sin PAC y sin CSD”. Es pequeño, reversible y demuestra separación opcional.

- **Tablas (mediante migración futura, no ahora):** catálogos mínimos versionados `sat_tax_regimes`, `sat_cfdi_uses`; `fiscal_profiles` receptor con `client_id`, RFC, razón, régimen, CP, uso default, residencia/tax ID extranjero, `is_default`, vigencias/timestamps. No certificados/documentos/PAC.
- **Archivos:** `app/Domain/Fiscal/ValueObjects/Rfc.php`; `app/Services/Fiscal/FiscalReadinessService.php`; modelos de las tres tablas; `app/Controllers/Fiscal/Client_profiles.php`; `app/Views/fiscal/profiles/{modal_form,readiness}.php`; `app/Config/FiscalRoutes.php`; traducciones; extensiones mínimas de ficha cliente y permisos.
- **Vistas:** perfil fiscal como sección separada, badge Sin perfil/Incompleto/Listo y modal de validación; ningún campo fiscal obligatorio en alta normal.
- **Validaciones:** trim/mayúsculas, formato/longitud RFC, razón social no vacía al evaluar, CP 5 dígitos, claves vigentes y régimen/Uso compatible; extranjero condicional. Advertir que formato correcto no confirma situación SAT.
- **Pruebas:** cliente sin perfil se crea/cotiza; perfiles múltiples/default único; matriz persona física/moral/extranjero; catálogo vencido; autorización; claves inexistentes; soft-delete no rompe cliente; sin request de red.
- **Aceptación:** operación administrativa no cambia; usuario autorizado crea/edita perfil; readiness devuelve mensajes exactos y deterministas; datos no se guardan en VAT/GST; cero dependencias/llamadas PAC; suite existente y nueva verde.

## 29. Conclusión

La migración es viable como incorporación de un dominio fiscal mexicano paralelo a RISE. Se conserva todo el núcleo administrativo y se extiende mediante perfiles/configuraciones y relaciones; CFDI, snapshots, impuestos, PAC, artefactos, cancelaciones y complementos se crean con modelo propio. De FC2 son valiosos el contrato MultiPAC observado, estructura CFDI/Pagos, parser y algoritmos enteros de Pagos, catálogo de errores y experiencia de campos; se adaptan detrás de DTOs/pruebas. No se reutilizan controladores/modelos/UI completos, CFDI 3.3, almacenamiento de CSD, secretos, floats ni flujo de estados.

Los riesgos dominantes son precisión, concurrencia/idempotencia, reconciliación tras timeout, seguridad del CSD y evolución del pago N:M. El primer paso tras aprobar el plan es el perfil fiscal/readiness de cliente sin PAC. Antes de timbrado se necesita aprobación humana sobre alcance de facturación parcial/agregada, emisores, proveedor/contrato PAC, política PDF/catálogos/cancelaciones, retención y custodia de secretos.

## Fuentes revisadas

### Documentos obligatorios (leídos completos)

- `docs/CONTEXTO_IKONTROL20_RISE.md`.
- `docs/CONTEXTO_FISCAL_FACTUCARE2.md`.

### Evidencia iKontrol2.0 consultada

- `system/CodeIgniter.php`; `app/Config/{Database,Routes,Migrations}.php`; `install1/database.sql`.
- `app/Controllers/{Clients,Items,Estimates,Invoices,Invoice_payments,Payment_methods,Taxes,Settings,Roles,Company}.php`.
- `app/Models/{Clients,Items,Estimates,Estimate_items,Invoices,Invoice_items,Invoice_payments,Payment_methods,Taxes,Settings,Roles,Company}_model.php`.
- `app/Libraries/{Client,E_invoice,Permission_manager,Pdf,App_folders}.php` y helpers de moneda/archivos.
- Vistas de `clients`, `items`, `estimates`, `invoices`, `invoice_payments`, `roles`, `settings` y `e_invoice_templates`.

### Evidencia FactuCare 2 consultada/documentada

- `D:\GitHub\factucare2-0\app\Http\Controllers\{FacturasController,ComplementosController,ConfiguracionController,FoliosController,ClientesController,ProductosController}.php`.
- `...\app\Http\Controllers\Traits\PacMultiPacTrait.php`; `...\app\Extensions\MultiPac\MultiPac.php`.
- Modelos `Factura`, `FacturaDetalle`, `FacturaImpuesto`, `Cliente`, `Producto`, `Folio`; rutas/config/migraciones disponibles.
- `...\config\timbradorxpress_errors.php`.
- Vistas `resources/views/facturas/{create,pdf}.blade.php` y `resources/views/documentos/complementos/{create,pdf}.blade.php`.

No se tomó como hecho lo no comprobable en la entrega: DDL fiscal completo de FC2, vigencia efectiva del WSDL/credenciales, fuente/versión actual de sus catálogos o comportamiento real del PAC. Deben validarse antes de implementación.
