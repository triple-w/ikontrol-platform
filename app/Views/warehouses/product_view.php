<?php
$transit = (new \App\Services\WarehouseTransferService(db_connect('default')))->getInTransitStock((int) $product->id);
$controlled = bcadd((string) $total, $transit, 6);
$permissions = isset($login_user) && is_array($login_user->permissions) ? $login_user->permissions : (isset($login_user) ? (@unserialize((string) $login_user->permissions) ?: []) : []);
$canManage = isset($login_user) && ($login_user->is_admin || get_array_value($permissions, 'warehouse_products_manage'));
?>
<div class="page-content clearfix">
<div class="container-fluid">
    <div class="page-title clearfix">
        <h1><?php echo esc($product->name); ?> <span class="badge bg-<?php echo $product->status === 'active' ? 'success' : 'secondary'; ?> font-12"><?php echo $product->status === 'active' ? 'Activo' : 'Inactivo'; ?></span></h1>
        <div class="float-end">
            <?php echo modal_anchor(get_uri('warehouses/products/'.$product->id.'/labels/form'), '<i data-feather="printer" class="icon-16"></i> Imprimir etiquetas', ['class' => 'btn btn-primary', 'title' => 'Imprimir etiquetas']); ?>
            <a href="<?php echo get_uri('warehouses/products'); ?>" class="btn btn-default"><i data-feather="arrow-left" class="icon-16"></i> Volver</a>
        </div>
    </div>
    <div class="card">
        <div class="card-header p0"><ul class="nav nav-tabs" role="tablist">
            <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#wp-info">Información</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#wp-stock">Existencias</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#wp-kardex">Kardex</a></li>
        </ul></div>
        <div class="tab-content">
            <div id="wp-info" class="tab-pane active">
                <div class="card-body"><div class="row">
                    <div class="col-md-3 mb15"><div class="text-off">Código interno</div><strong><?php echo esc($product->internal_code); ?></strong></div>
                    <div class="col-md-3 mb15"><div class="text-off">Código de barras</div><strong><?php echo esc($product->barcode ?: '-'); ?></strong></div>
                    <div class="col-md-3 mb15"><div class="text-off">Unidad de control</div><strong><?php echo esc($product->control_unit); ?></strong></div>
                    <div class="col-md-3 mb15"><div class="text-off">Categoría</div><strong><?php echo esc($product->category ?: '-'); ?></strong></div>
                </div></div>
                <div class="card-body border-top"><div class="row align-items-center">
                    <div class="col-md-4">
                        <h5 class="mb15"><i data-feather="image" class="icon-16"></i> Logo para etiquetas</h5>
                        <?php if ($product->label_logo): ?>
                            <img src="<?php echo get_uri('warehouses/products/'.$product->id.'/label-logo'); ?>" alt="Logo de <?php echo esc($product->name); ?>" class="img-thumbnail mb10" style="max-width:220px;max-height:100px;object-fit:contain">
                        <?php else: ?><div class="text-muted mb15">Este producto no tiene un logo específico.</div><?php endif; ?>
                    </div>
                    <?php if ($canManage): ?><div class="col-md-8">
                        <?php echo form_open_multipart(get_uri('warehouses/products/'.$product->id.'/label-logo'), ['id' => 'product-label-logo-form', 'class' => 'general-form']); echo csrf_field(); ?>
                        <div class="form-group"><label>PNG o JPG/JPEG · máximo 2 MB</label><input type="file" name="label_logo" class="form-control" accept="image/png,image/jpeg" required></div>
                        <button class="btn btn-default" type="submit"><i data-feather="upload" class="icon-16"></i> <?php echo $product->label_logo ? 'Cambiar logo' : 'Subir logo'; ?></button>
                        <?php echo form_close(); ?>
                        <?php if ($product->label_logo): ?><button id="remove-product-label-logo" type="button" class="btn btn-link text-danger p0 mt10"><i data-feather="trash-2" class="icon-16"></i> Quitar logo</button><?php endif; ?>
                    </div><?php endif; ?>
                </div></div>
            </div>
            <div id="wp-stock" class="tab-pane">
                <div class="card-body"><div class="row">
                    <div class="col-md-6"><div class="text-off">Total en almacenes</div><h3 class="m0"><?php echo esc(to_decimal_format($total)); ?></h3></div>
                    <div class="col-md-6"><div class="text-off">Total controlado</div><h3 class="m0"><?php echo esc(to_decimal_format($controlled)); ?></h3></div>
                </div></div>
                <div class="table-responsive"><table class="table table-hover mb0"><thead><tr><th>Ubicación</th><th>Código</th><th class="text-end">Cantidad</th></tr></thead><tbody>
                <?php foreach ($stocks as $row): ?><tr><td><a href="<?php echo get_uri('warehouses/view/'.$row['warehouse_id']); ?>"><?php echo esc($row['name']); ?></a></td><td><?php echo esc($row['code']); ?></td><td class="text-end"><?php echo esc(to_decimal_format($row['stock'])); ?></td></tr><?php endforeach; ?>
                <tr class="table-info"><td><a href="<?php echo get_uri('warehouses/transfers/in-transit'); ?>"><strong>En tránsito</strong></a></td><td>TR</td><td class="text-end"><strong><?php echo esc(to_decimal_format($transit)); ?></strong></td></tr>
                </tbody></table></div>
            </div>
            <div id="wp-kardex" class="tab-pane"><div class="table-responsive"><table class="table table-hover mb0"><thead><tr><th>Fecha</th><th>Movimiento</th><th>Almacén</th><th>Referencia</th><th class="text-end">Entrada</th><th class="text-end">Salida</th><th class="text-end">Saldo</th><th>Usuario</th></tr></thead><tbody>
            <?php foreach ($kardex as $row): $entry = bccomp($row['quantity_delta'], '0', 6) > 0 ? $row['quantity_delta'] : '0'; $exit = bccomp($row['quantity_delta'], '0', 6) < 0 ? substr($row['quantity_delta'], 1) : '0'; ?>
                <tr><td><?php echo esc($row['movement_date']); ?></td><td><a href="<?php echo get_uri('warehouses/movements/view/'.$row['id']); ?>"><?php echo esc($row['folio']); ?></a></td><td><?php echo esc($row['warehouse_name']); ?></td><td><?php if (($row['reference_type'] ?? '') === 'WAREHOUSE_TRANSFER'): ?><a href="<?php echo get_uri('warehouses/transfers/view/'.(int) $row['reference_id']); ?>"><?php echo esc($row['reference_text']); ?></a><?php else: echo esc($row['reference_text'] ?: '-'); endif; ?></td><td class="text-end"><?php echo esc(to_decimal_format($entry)); ?></td><td class="text-end"><?php echo esc(to_decimal_format($exit)); ?></td><td class="text-end"><strong><?php echo esc(to_decimal_format($row['balance'])); ?></strong></td><td><?php echo esc(trim($row['first_name'].' '.$row['last_name']) ?: '-'); ?></td></tr>
            <?php endforeach; ?></tbody></table></div></div>
        </div>
    </div>
</div>
</div>
<script>
$(function () {
    $('#product-label-logo-form').appForm({onSuccess: function () { location.reload(); }});
    $('#remove-product-label-logo').on('click', function () {
        if (!confirm('¿Quitar el logo para etiquetas de este producto?')) return;
        $.post('<?php echo get_uri('warehouses/products/'.$product->id.'/label-logo/remove'); ?>', {'<?php echo csrf_token(); ?>': '<?php echo csrf_hash(); ?>'})
            .done(function () { location.reload(); })
            .fail(function (xhr) { appAlert.error((xhr.responseJSON && xhr.responseJSON.message) || 'No fue posible quitar el logo.'); });
    });
});
</script>
