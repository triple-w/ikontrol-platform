# Incremento C2.3.4 — Normalización development

## Base y respaldo

La base canónica es `ikontrol20_dold_preview`. El respaldo previo está en
`writable/backups/c2_3_4_dev_live_flow_20260813_142248`, con dump, configuración,
servicios, vistas, rutas, pruebas y `SHA256SUMS.txt`.

Se aplicaron las migraciones pendientes de control comercial de timbres y las
migraciones C2.3.4. Los perfiles y series históricos quedaron normalizados a
`development`. No se copiaron datos desde `ikontrol_new`.

## Flujo web

La acción visible de `app/Views/invoices/invoice_actions.php` ahora usa
`fiscal/drafts/create/{sale}`. La ruta legacy permanece registrada sólo para
compatibilidad histórica.

## Créditos y saldo local

`FiscalPacCreditService` implementa `consultarCreditosDisponibles(apikey)` en
development. Sólo persiste código, mensaje sanitizado, saldo, HTTP, hash y fecha.
`FiscalStampAccountService::synchronizeDevelopment()` concilia
`available + reserved` contra PAC mediante un movimiento inmutable con motivo
`pac_development_sync`, actor, ambiente y referencia a la consulta.

La única consulta real autorizada devolvió 49 créditos, código 200. La consulta
durable 1 originó un ajuste auditado de +49: disponible 49, reservado 0.

## Documento 25 y regla de parada

`php spark fiscal:integration:reconcile-info 25` devuelve
`DOCUMENT_NOT_FOUND_IN_ACTIVE_DATABASE`. La base canónica contiene cero documentos
y cero intentos fiscales; el documento 25/intento 22 pertenece a la ejecución
histórica en otra base. No se inventó UUID ni se copiaron registros.

Resultado: **C — sigue indeterminado**. Esto impide crear un nuevo candidato,
timbrar o generar PDF hasta resolver explícitamente la procedencia histórica.

## Evidencia forense

Los futuros intentos registran Content-Type, longitud y SHA-256 del body, fase,
clase/mensaje sanitizado y estructura exterior. Respuestas no interpretables se
conservan cifradas mediante la bóveda de contingencia. Nunca se persisten API key,
keyPEM o passwords.

Se distinguen `outer_response_invalid`, `provider_rejected`,
`stamp_data_invalid`, `stamped_xml_invalid` y
`stamped_xml_semantic_invalid`. No se relajaron UUID, CFDI 4.0, TFD 1.1 ni las
comparaciones fiscales; no se amplió el formato de `data` por conjetura.

## Pendiente seguro

Localizar el intento histórico en su base original o en el panel development con
serie, folio, RFC, total y hora. Mientras no se determine A o B, no se permite un
nuevo timbrado. Cancelaciones siguen fuera de alcance. No commit; no push.
