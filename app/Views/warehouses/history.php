<div class="page-content clearfix">
<div class="container-fluid">
    <div class="page-title clearfix"><h1>Historial / Kardex logístico</h1></div>
    <div class="card mb15">
        <div class="card-body">
            <form method="get" class="row align-items-end">
                <div class="col-md-3 form-group"><label>Almacén</label><select name="warehouse_id" class="form-control"><option value="">Todos</option><?php foreach ($warehouses as $warehouse): ?><option value="<?php echo $warehouse->id; ?>"><?php echo esc($warehouse->name); ?></option><?php endforeach; ?></select></div>
                <div class="col-md-3 form-group"><label>Producto</label><select name="product_id" class="form-control"><option value="">Todos</option><?php foreach ($products as $product): ?><option value="<?php echo $product->id; ?>"><?php echo esc($product->internal_code.' - '.$product->name); ?></option><?php endforeach; ?></select></div>
                <div class="col-md-2 form-group"><label>Tipo</label><select name="movement_type" class="form-control"><option value="">Todos</option><option>ENTRY</option><option>EXIT</option><option>ADJUSTMENT</option></select></div>
                <div class="col-md-2 form-group"><label>Folio</label><input class="form-control" name="folio"></div>
                <div class="col-md-2 form-group"><button class="btn btn-primary w-100"><i data-feather="filter" class="icon-16"></i> Filtrar</button></div>
            </form>
        </div>
    </div>
    <div class="card"><div class="table-responsive"><table class="table table-hover mb0">
        <thead><tr><th>Fecha</th><th>Folio</th><th>Tipo</th><th>Almacén</th><th>Producto</th><th>Referencia</th><th class="text-end">Delta</th><th>Usuario</th></tr></thead>
        <tbody><?php foreach ($rows as $row): ?><tr>
            <td><?php echo esc($row->movement_date); ?></td>
            <td><a href="<?php echo get_uri('warehouses/movements/view/'.$row->id); ?>"><?php echo esc($row->folio); ?></a></td>
            <td><?php echo esc($row->movement_type); ?></td><td><?php echo esc($row->warehouse_name); ?></td>
            <td><?php echo esc($row->internal_code.' - '.$row->product_name); ?></td><td><?php echo esc($row->reference_text ?: '-'); ?></td>
            <td class="text-end"><?php echo esc(to_decimal_format($row->quantity_delta)); ?></td><td><?php echo esc(trim($row->user_name) ?: '-'); ?></td>
        </tr><?php endforeach; ?></tbody>
    </table></div></div>
</div>
</div>
