<div class="modal-body">
    <?php
    $visibleClasses = [
        'stamped' => 'success',
        'processing' => 'info',
        'ready' => 'info',
        'unknown' => 'warning',
        'correctable_error' => 'danger',
        'cancelled' => 'secondary',
        'draft' => 'secondary',
    ];
    $class = $visibleClasses[$status_view->visibleStatus] ?? 'secondary';
    ?>
    <div class="alert alert-<?php echo $class; ?>">
        <h4><?php echo esc(ucfirst(str_replace('_', ' ', $status_view->visibleStatus))); ?></h4>
        <div><?php echo esc($status_view->visibleMessage); ?></div>
    </div>

    <dl class="row">
        <dt class="col-sm-4">Estado interno</dt>
        <dd class="col-sm-8"><?php echo esc($document->status); ?></dd>

        <dt class="col-sm-4">Último intento</dt>
        <dd class="col-sm-8"><?php echo $attempt ? '#' . (int) $attempt->id : 'No disponible'; ?></dd>

        <dt class="col-sm-4">Fecha</dt>
        <dd class="col-sm-8"><?php echo esc($attempt->started_at ?? $document->stamp_updated_at ?? $document->updated_at ?? 'No disponible'); ?></dd>

        <dt class="col-sm-4">Proveedor / ambiente</dt>
        <dd class="col-sm-8"><?php echo $attempt ? esc(($attempt->provider ?? '—') . ' / ' . ($attempt->environment ?? '—')) : 'No disponible'; ?></dd>

        <dt class="col-sm-4">HTTP / código PAC</dt>
        <dd class="col-sm-8"><?php echo $attempt ? esc(($attempt->http_status ?? '—') . ' / ' . ($attempt->provider_code ?? '—')) : 'No disponible'; ?></dd>

        <dt class="col-sm-4">Mensaje PAC</dt>
        <dd class="col-sm-8"><?php echo esc($attempt->provider_message ?? 'No disponible'); ?></dd>

        <dt class="col-sm-4">UUID</dt>
        <dd class="col-sm-8"><?php echo esc($stamp->uuid ?? 'No disponible'); ?></dd>

        <dt class="col-sm-4">XML timbrado</dt>
        <dd class="col-sm-8"><?php echo $status_view->xmlAvailable ? 'Sí' : 'No'; ?></dd>

        <dt class="col-sm-4">PDF</dt>
        <dd class="col-sm-8"><?php echo $status_view->pdfAvailable ? 'Sí' : 'No'; ?></dd>

        <dt class="col-sm-4">Requiere conciliación</dt>
        <dd class="col-sm-8"><?php echo $status_view->requiresReconciliation ? 'Sí' : 'No'; ?></dd>

        <dt class="col-sm-4">Acción recomendada</dt>
        <dd class="col-sm-8"><?php echo esc($status_view->recommendedAction ?? 'Ninguna'); ?></dd>
    </dl>

    <?php if ($stamp && $status_view->xmlAvailable) { ?>
        <div class="btn-group">
            <a class="btn btn-default" target="_blank" href="<?php echo get_uri('fiscal/stamping/xml/view/' . $document->id); ?>">
                <?php echo app_lang('view_stamped_xml'); ?>
            </a>
            <a class="btn btn-default" href="<?php echo get_uri('fiscal/stamping/xml/download/' . $document->id); ?>">
                <?php echo app_lang('download_stamped_xml'); ?>
            </a>
            <?php if ($status_view->pdfAvailable) { ?>
                <a class="btn btn-default" target="_blank" href="<?php echo get_uri('fiscal/documents/' . $document->id . '/pdf/preview'); ?>">
                    <?php echo app_lang('view_pdf'); ?>
                </a>
                <a class="btn btn-default" href="<?php echo get_uri('fiscal/documents/' . $document->id . '/pdf/download'); ?>">
                    <?php echo app_lang('download_pdf'); ?>
                </a>
            <?php } ?>
        </div>
    <?php } ?>
</div>
