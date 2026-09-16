<div class="page-content clearfix">
<div class="container-fluid">
    <div class="page-title clearfix">
        <h1>Productos de almacén</h1>
        <?php if ($can_manage) echo modal_anchor(get_uri('warehouses/products/form'), '<i data-feather="plus" class="icon-16"></i> Nuevo producto', ['class' => 'btn btn-primary float-end', 'title' => 'Nuevo producto de almacén']); ?>
    </div>
    <div class="card">
        <div class="card-body border-bottom">
            <div class="input-group">
                <span class="input-group-text"><i data-feather="search" class="icon-16"></i></span>
                <input id="warehouse-product-lookup" class="form-control" placeholder="Buscar por código, barcode o nombre">
                <button type="button" class="btn btn-default" id="lookup-button">Consulta rápida</button>
            </div>
            <div id="lookup-result" class="mt10"></div>
        </div>
        <div class="table-responsive"><table id="warehouse-products-table" class="display" width="100%"></table></div>
    </div>
</div>
</div>
<script>
$(function () {
    var table = $('#warehouse-products-table').appTable({
        source: '<?php echo get_uri('warehouses/products/list'); ?>',
        columns: [{title:'Código'}, {title:'Barcode'}, {title:'Producto'}, {title:'Unidad'}, {title:'Categoría'}, {title:'Total', class:'text-end'}, {title:'Estado'}, {title:'Acciones', class:'text-center option w100'}]
    });
    $(document).on('click', '.product-toggle', function () {
        $.post('<?php echo get_uri('warehouses/products/toggle'); ?>', {id:$(this).data('id'), '<?php echo csrf_token(); ?>':'<?php echo csrf_hash(); ?>'}, function (response) {
            appAlert.success(response.message); table.appTable({reload:true});
        }, 'json');
    });
    $('#lookup-button').on('click', function () {
        $.get('<?php echo get_uri('warehouses/products/lookup'); ?>', {q:$('#warehouse-product-lookup').val()}, function (response) {
            var result = $('<div>');
            response.products.forEach(function (product) {
                result.append($('<div>', {'class':'alert alert-light mb5'}).append(
                    $('<strong>').text(product.internal_code + ' · ' + product.name),
                    $('<div>', {'class':'text-muted'}).text('Barcode: ' + (product.barcode || '-') + ' · Total: ' + product.total)
                ));
            });
            $('#lookup-result').empty().append(result.children().length ? result.children() : $('<div>', {'class':'text-muted', text:'Sin resultados.'}));
        }, 'json');
    });
});
</script>
