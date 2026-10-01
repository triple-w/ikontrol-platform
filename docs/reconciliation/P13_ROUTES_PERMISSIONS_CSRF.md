# P13 — Rutas, permisos y CSRF

Fecha de cierre técnico: 2026-10-01. Estado: implementado y probado de forma aislada; no desplegado; sin cambio de schema ni PAC real.

## Superficie HTTP resultante

Los controladores raíz reconciliados (`Proposals`, `Proposal_templates`, `Offer`, `Suppliers`, Warehouses, complementos y notas de crédito) quedaron fuera del registro dinámico GET/POST. Sus rutas son explícitas. El auto-routing del framework continúa deshabilitado.

Las consultas y descargas directas usan GET. Las mutaciones usan POST con filtro CSRF: selección y edición de propuestas, aceptación/conversión, costos manuales, movimientos/transferencias/recepciones, externos de complementos, snapshot, timbrado, cancelación, conciliación y generación/regeneración PDF. Los POST de modal, tabla o búsqueda que conserva RISE por compatibilidad también llevan CSRF, aunque no escriban dominio.

El flujo público de Proposal conserva su `public_key`: preview y PDF son GET; aceptar, rechazar, abrir el modal y materializar la aceptación son POST con CSRF. Ya no existe una segunda forma de acceso mediante el catch-all.

`FiscalRoutes.php` declara CSRF en todos sus POST. Las descargas de XML/PDF y acuses continúan como GET protegidos por autorización; generar o regenerar un artefacto continúa como POST.

## Autorización

- Proposal conserva su alcance `proposal`; aceptar/convertir como personal exige además `proposal.accept_and_convert`. La aceptación pública o del contacto conserva la validación de propuesta, cliente y llave pública.
- Suppliers conserva `suppliers_view`, `suppliers_manage`, `supplier_costs_view` y `supplier_costs_edit`.
- Warehouses conserva capacidades separadas para consulta, catálogo/productos, movimientos, ajustes, despacho, recepción y logística.
- Payment Complements separa `fiscal.drafts.view`, `fiscal.drafts.create`, `fiscal.drafts.edit` y `fiscal.drafts.discard`. Timbrar conserva `fiscal_stamp_sandbox` como nombre legacy de capacidad; P09 sigue decidiendo el ambiente real.
- Estado, retry por nuevo submit y conciliación conservan `fiscal_stamp_status`, `fiscal_stamp_sandbox` y `fiscal_stamp_reconcile`. La conciliación resuelve primero el intento y valida acceso al documento.
- Cancelar, consultar cancelación y descargar acuse conservan permisos distintos. XML, PDF, generación y regeneración exigen sus capacidades existentes; lectura no concede timbrado.

`Roles_model` continúa siendo almacenamiento pasivo del mapa serializado. `Roles` ya registra estas capacidades, por lo que no se creó un permiso por botón ni una migración. `Security_Controller::can_view_invoices()` sigue resolviendo el alcance de documentos con invoice. Para complementos externos con `invoice_id = NULL`, el acceso se prueba contra el vínculo fiscal del complemento y sus capacidades de vista.

## Compatibilidad y riesgos conservados

El catch-all permanece para controladores fuera de P06–P12; no se auditó esa superficie. Los endpoints de modal/listado que históricamente usan POST conservan verbo y URL, ahora con CSRF. No se retiraron rutas públicas ni descargas legacy válidas.

Los roles no administrativos existentes deben tener asignadas las capacidades ya registradas que ahora se hacen cumplir, especialmente `proposal.accept_and_convert` y `fiscal.drafts.*`. Administradores conservan acceso. No se cambió ningún contrato de dominio, snapshot, wallet, cálculo, schema ni transporte.

## Validación

- `php tests/P13HttpSurface/run.php`: 24/24. Carga la colección real de CodeIgniter, verifica GET de lectura, rechazo de GET mutante, POST explícito, filtros CSRF, token ausente/válido, exclusión del catch-all y contratos de permisos. No usa BD ni PAC.
- `php tests/ProposalCanonical/run.php`: 13/13.
- `php tests/ProposalFiscalConversion/run.php`: 9/9; schema MySQL sintético eliminado.
- `php -d extension=sqlite3 tests/SupplierWorkflowCanonical/run.php`: 21/21.
- `php tests/WarehouseCanonical/run.php`: 11/11; schema MySQL sintético eliminado.
- `php tests/PaymentComplementsCanonical/run.php`: 28/28; schema MySQL sintético eliminado.
- `php tests/FiscalSeriesPdfCanonical/run.php`: 32/32; schema MySQL sintético eliminado.
- `php tests/FiscalStampingLifecycle/run.php`: 26/26.
- `php tests/FiscalPdfRegeneration/run.php`: 15/15.

Todas las pruebas fiscales utilizaron fake/MOCK o inspección local. No hubo request PAC real ni migración global.

## Pendiente P14

P14 puede abordar exclusivamente limpieza EOL, documentación, `.gitignore` y `.env.example`. Debe conservar la separación de rutas explícitas y no reabrir permisos o contratos funcionales de P13.
