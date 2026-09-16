# Incremento C2.3.2 — Estabilización de facturación

## Respaldo

- Ruta: `writable/backups/c2_3_2_operational_flow_20260812_152300`.
- Base respaldada: `ikontrol_new` (`ikontrol_new.sql`, 9,287,132 bytes).
- Código respaldado: controladores, servicios, modelos y vistas fiscales; configuración, rutas y pruebas.
- Inventario: `SHA256SUMS.txt` con SHA-256 por archivo.

## Bug `is_active`

`InvoiceModule::index()` y `StampBalance::index()` consultaban `fiscal_profiles.is_active`, columna que nunca forma parte del esquema vigente. El perfil usa `status`, `is_default`, `valid_from`, `valid_to` y `environment`. La consulta fallaba antes de renderizar la pantalla.

Se creó `FiscalIssuerResolver`, usado por ambos controladores. El resolver exige tipo `issuer`, excluye `inactive`, filtra empresa, ambiente y vigencia, prefiere perfiles `ready` y después `is_default`, pero no presupone que los datos migrados tengan un default.

La auditoría completa de `app/` encontró sólo esas dos consultas erróneas contra `fiscal_profiles.is_active`. Los demás usos de `is_active` corresponden a tablas donde sí existe (series, catálogos SAT, impuestos de partidas, métodos de pago y plantillas PDF).

## Schema contra código

Se compararon las columnas reales de `fiscal_profiles`, `fiscal_documents`, `fiscal_drafts`, `fiscal_series`, `fiscal_stamp_attempts`, `fiscal_document_artifacts`, `fiscal_document_binary_artifacts` y `fiscal_issuer_certificates` con sus consultas fiscales. No se detectaron otras referencias obsoletas en esas tablas.

Al ejecutar la carga real del módulo apareció una tabla legítimamente faltante: `fiscal_stamp_accounts`. Existía la migración versionada `2026-08-04-170000_CreateFiscalStampCommercialControl`, pero no había sido aplicada. Se ejecutó el runner oficial; también aplicó las dos migraciones posteriores que estaban pendientes. No se creó ni alteró ninguna tabla manualmente.

## Prueba operativa

- Base activa de desarrollo: `ikontrol_new`.
- Emisor resuelto: perfil 2, empresa 1, estado `ready`, ambiente `development`.
- Serie: `TEST`, ambiente `development`.
- Venta creada mediante los servicios normales: 58.
- Pago: `not_paid`; cierre comercial: `closed`.
- Borrador: 4, estado `ready`, `snapshot_version=2`.
- Prefactura: construida correctamente desde el snapshot.
- Documento preparado: 24, serie TEST.

El primer intento de ejecución se detuvo antes del transporte por permisos locales al escribir el pre-XML. Fuera del sandbox se persistió la preparación, pero el guard operativo detuvo el timbrado porque `fiscal.stampingEnabled` no está habilitado. No se efectuó una llamada PAC para el candidato 4 y no se consumió timbre.

La habilitación persistente de timbrado queda pendiente de autorización explícita por su efecto global. No se reintentará automáticamente.

## PAC, XML y PDF

La configuración comprobada es integration/development, adaptador TimbradorXpress sandbox, CSD vigente y coincidente con el RFC, serie TEST, y PDF exclusivamente por `FiscalPacPdfGenerationService` → WSTools33 → template 1. No existe fallback local en el flujo real.

Para el candidato 4 todavía no hay UUID, XML timbrado ni PDF PAC porque el guard detuvo el transporte. Por ello quedan pendientes la validación CFDI 4.0/TFD 1.1, descargas reales y navegación sobre el documento timbrado.

## Pruebas

`tests/IncrementC232/run.php` contiene 20 regresiones solicitadas. Resultado actual: 20 aprobadas, 0 fallidas. También aprobaron previamente IncrementC231 (24/24) e IncrementC23 (47/47). La suite completa histórica queda pendiente de la autorización y cierre del flujo externo.

## Pendientes

- Autorizar y habilitar explícitamente `fiscal.stampingEnabled` para development.
- Asignar saldo comercial de prueba al emisor si el control local lo requiere.
- Ejecutar una sola llamada PAC para borrador 4, sin reintento automático.
- Validar y documentar XML, UUID, PDF WSTools33 código 210, descargas y navegación real.
- Cancelaciones y complementos permanecen fuera de alcance.
