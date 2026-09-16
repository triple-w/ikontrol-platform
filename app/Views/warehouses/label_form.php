<?php
$formUrl = get_uri('warehouses/products/'.$product->id.'/labels/pdf');
$previewUrl = get_uri('warehouses/products/'.$product->id.'/labels/preview');
$checks = [];
if (!empty($product->label_logo)) {
    $checks['logo'] = 'Logo';
}
$checks['name'] = 'Nombre';
if ($product->model || $product->variant) {
    $checks['model_variant'] = 'Modelo / variante';
}
if ($product->size) {
    $checks['size'] = 'Talla';
}
if ($product->color) {
    $checks['color'] = 'Color';
}
if ($product->barcode) {
    $checks['barcode'] = 'Código de barras';
    $checks['barcode_text'] = 'Número del código de barras';
}
$checks['internal_code'] = 'Código interno';
?>

<?php echo form_open($formUrl, ['id' => 'warehouse-label-form']); ?>
<?php echo csrf_field(); ?>
<div class="modal-header">
    <h5 class="modal-title">
        <i data-feather="printer" class="icon-16"></i>
        Imprimir etiquetas — <?php echo esc($product->name); ?>
    </h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
</div>

<div class="modal-body clearfix general-form">
    <div class="container-fluid">
        <div class="row">
            <div class="col-md-6">
                <div class="form-group">
                    <label for="label-size">Tamaño</label>
                    <select name="size_preset" id="label-size" class="form-control">
                        <option value="50x30">50 × 30 mm</option>
                        <option value="60x40">60 × 40 mm</option>
                        <option value="100x50">100 × 50 mm</option>
                        <option value="custom">Personalizado</option>
                    </select>
                </div>
            </div>
            <div class="col-md-6">
                <div class="form-group">
                    <label for="label-quantity">Cantidad</label>
                    <input id="label-quantity" type="number" name="quantity" min="1" max="1000" value="1" class="form-control" required>
                    <small class="text-muted">Máximo 1000 etiquetas por PDF.</small>
                </div>
            </div>
        </div>

        <div id="custom-size" class="row hide">
            <div class="col-md-6">
                <div class="form-group">
                    <label for="label-width">Ancho (mm)</label>
                    <input id="label-width" name="width_mm" type="number" min="25" max="150" step="0.1" value="50" class="form-control">
                </div>
            </div>
            <div class="col-md-6">
                <div class="form-group">
                    <label for="label-height">Alto (mm)</label>
                    <input id="label-height" name="height_mm" type="number" min="15" max="100" step="0.1" value="30" class="form-control">
                </div>
            </div>
        </div>

        <div class="card bg-light border-0 mt15 mb15">
            <div class="card-body">
                <h6 class="mb15">Contenido de la etiqueta</h6>
                <div class="row">
                    <?php foreach ($checks as $key => $label): ?>
                        <div class="col-md-6 mb10">
                            <div class="form-check">
                                <input class="form-check-input label-field" type="checkbox"
                                       id="label-field-<?php echo esc($key); ?>"
                                       name="fields[<?php echo esc($key); ?>]" value="1" checked>
                                <label class="form-check-label" for="label-field-<?php echo esc($key); ?>">
                                    <?php echo esc($label); ?>
                                </label>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <?php if (!$product->barcode): ?>
            <div class="alert alert-warning">
                <i data-feather="alert-triangle" class="icon-16"></i>
                Este producto no tiene código de barras configurado. La etiqueta se generará con los campos disponibles; no se creará uno automáticamente.
            </div>
        <?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb10">
            <h6 class="m0">Vista previa</h6>
            <button type="button" class="btn btn-default btn-sm" id="refresh-label-preview">
                <i data-feather="refresh-cw" class="icon-16"></i> Actualizar
            </button>
        </div>
        <div class="warehouse-label-preview-panel">
            <div id="warehouse-label-preview" aria-live="polite">
                <div class="text-muted p20 text-center">Generando vista previa…</div>
            </div>
        </div>
    </div>
</div>

<div class="modal-footer">
    <button type="button" class="btn btn-default" data-bs-dismiss="modal">
        <i data-feather="x" class="icon-16"></i> Cerrar
    </button>
    <button type="submit" class="btn btn-primary">
        <i data-feather="file-text" class="icon-16"></i> Generar PDF
    </button>
</div>
<?php echo form_close(); ?>

<style>
    .warehouse-label-preview-panel { min-height: 230px; padding: 15px; border: 1px solid #e2e5e8; border-radius: 4px; background: #f7f9fa; }
    .warehouse-label-preview { width: min(100%, 520px); position: relative; container-type: inline-size; border: 1px solid #8aa4bd; border-radius: 3px; background: #fff; margin: 0 auto; box-sizing: border-box; overflow: hidden; color: #111; font-family: "DejaVu Sans", Arial, sans-serif; }
    .wl-block { position: absolute; box-sizing: border-box; display: flex; align-items: center; justify-content: center; min-height: 0; overflow: hidden; text-align: center; }
    .wl-logo-block { width: 100%; }
    .wl-logo { display: block; max-width: 100%; max-height: 100%; object-fit: contain; }
    .wl-text { line-height: 1.05; white-space: normal; overflow: hidden; }
    .wl-barcode-block { display: block; }
    .wl-barcode { width: 100%; height: 100%; overflow: hidden; }
    .wl-barcode svg { display: block; width: 100%; height: 100%; margin: 0 auto; }
    .wl-warning { font-size: 8pt; color: #946200; }
</style>

<script>
$(function () {
    var form = $('#warehouse-label-form');
    var previewBox = $('#warehouse-label-preview');
    var request = null;

    function showPreviewError(message) {
        previewBox.html($('<div>', {
            'class': 'alert alert-danger m15',
            text: message || 'No fue posible generar la vista previa.'
        }));
    }

    function refreshPreview() {
        if (request) {
            request.abort();
        }
        previewBox.html('<div class="text-muted p20 text-center">Generando vista previa…</div>');
        request = $.ajax({
            url: '<?php echo $previewUrl; ?>',
            type: 'POST',
            dataType: 'json',
            data: form.serialize()
        }).done(function (response) {
            if (!response || response.success !== true || !response.html) {
                showPreviewError(response && response.message);
                return;
            }
            previewBox.html(response.html);
            feather.replace();
        }).fail(function (xhr, status) {
            if (status === 'abort') {
                return;
            }
            var response = xhr.responseJSON || {};
            showPreviewError(response.message);
        }).always(function () {
            request = null;
        });
    }

    $('#label-size').on('change', function () {
        $('#custom-size').toggleClass('hide', this.value !== 'custom');
        refreshPreview();
    });
    form.on('change', '.label-field, input[name=width_mm], input[name=height_mm]', refreshPreview);
    $('#refresh-label-preview').on('click', refreshPreview);
    form.on('submit', function (event) {
        event.preventDefault();
        var pdfWindow = window.open('', '_blank');
        if (pdfWindow) {
            pdfWindow.document.write('<p style="font-family:Arial,sans-serif;padding:20px">Generando PDF…</p>');
        }
        fetch(form.attr('action'), {
            method: 'POST',
            body: new FormData(form[0]),
            credentials: 'same-origin',
            redirect: 'follow'
        }).then(async function (response) {
            var bytes = new Uint8Array(await response.arrayBuffer());
            var signature = bytes.length >= 5 ? String.fromCharCode.apply(null, bytes.slice(0, 5)) : '';
            var contentType = (response.headers.get('Content-Type') || '').toLowerCase();
            if (!response.ok || contentType.indexOf('application/pdf') !== 0 || signature !== '%PDF-') {
                var message = 'El servidor no devolvió un PDF válido.';
                try {
                    var text = new TextDecoder('utf-8').decode(bytes);
                    var error = JSON.parse(text);
                    message = error.message || message;
                } catch (ignore) {}
                throw new Error(message);
            }
            var url = URL.createObjectURL(new Blob([bytes], {type: 'application/pdf'}));
            if (pdfWindow) {
                pdfWindow.location.replace(url);
            } else {
                window.open(url, '_blank');
            }
            setTimeout(function () { URL.revokeObjectURL(url); }, 60000);
        }).catch(function (error) {
            if (pdfWindow) pdfWindow.close();
            appAlert.error(error.message || 'No fue posible generar el PDF de etiquetas.');
        });
    });
    refreshPreview();
});
</script>
