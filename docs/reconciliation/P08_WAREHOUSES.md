# P08 — Almacenes, transferencias y etiquetas

**Estado:** validado con fixtures propios; sin cambios funcionales ni migraciones nuevas.

## Estado canónico

Base ya contiene el contrato completo de logística. `WarehouseMovementService` crea borradores y sólo modifica existencias mediante movimientos confirmados; usa transacciones y bloqueos, agrega deltas trazables y rechaza salidas insuficientes. Un producto de almacén es una entidad logística separada, sin vínculo obligatorio a un producto comercial.

`WarehouseTransferService` conserva el ciclo borrador → `in_transit` → recepción parcial o total. El despacho resta sólo origen; cada recepción crea su entrada en destino; una diferencia cierra el remanente en tránsito sin inflar existencias. Los reintentos de despacho/recepción no duplican stock.

`WarehouseLabelService` y `Warehouse_labels` producen preview/PDF desde el mismo modelo de etiqueta, validan barcode y UTF-8, y permiten logo por producto. La generación no crea movimientos ni documentos comerciales.

## Schema y reconciliación

P02 documenta `CreateWarehouseLogisticsFoundation`, `CreateWarehouseTransfers` y `AddWarehouseProductLabelLogo` con efecto estructural completo en Base. La suite P08 crea un esquema MySQL temporal, ejecuta esas tres migraciones individualmente y verifica las tablas de transferencias y `warehouse_products.label_logo`. No se aplicó nada a Base real ni se creó migración nueva.

La comparación directa con Navika se limitó a `WarehouseMovementService`, `WarehouseTransferService` y `Warehouse_labels`: son equivalentes para el contrato revisado. No se incorporaron archivos, branding, datos ni configuración de Navika.

## Validación

`C:\xampp\php\php.exe tests\WarehouseCanonical\run.php`: **11 aserciones aprobadas**. Cubre entrada, salida, ajuste, rechazo de stock negativo, despacho, tránsito, recepción parcial/completa, reintento, diferencia, preview/PDF UTF-8 y ausencia de movimientos al generar etiquetas.

El runner crea y elimina el esquema `ikontrol_test_p08_*`; no clona ni lee filas Base/Navika y no invoca PAC. Los runners heredados `DoldWarehouses*` no se usaron porque dependen del clonador histórico de datos.

## Pendientes

- Desplegar los efectos de migración sólo mediante el proceso individual autorizado por P02; no usar `spark migrate` global.
- Probar permisos/rutas HTTP con un entorno de aplicación controlado al preparar P13.
