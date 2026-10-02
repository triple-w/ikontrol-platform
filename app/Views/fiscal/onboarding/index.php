<div class="card">
    <div class="page-title clearfix">
        <h4>Configuración fiscal</h4>
        <?php $modeColor = ($mode['mode'] ?? '') === 'PRODUCTION' ? 'success' : (($mode['mode'] ?? '') === 'DISABLED' ? 'secondary' : 'warning'); ?>
        <span class="badge bg-<?php echo $modeColor; ?> float-end"><?php echo esc($mode['mode'] ?? $status['state']); ?></span>
    </div>
    <div class="card-body">
        <p class="text-muted">Completa los requisitos en orden. El acceso a configuración permanece disponible durante onboarding; el timbrado continúa bloqueado hasta que readiness sea correcto.</p>

        <?php if ($status['state'] === 'NOT_INSTALLED') { ?>
            <div class="alert alert-danger">
                <strong>Infraestructura fiscal pendiente.</strong><br>
                Faltan tablas fiscales: <?php echo esc(implode(', ', $status['details']['missing_tables'] ?? [])); ?>
            </div>
        <?php } else { ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead><tr><th>Requisito</th><th>Estado</th><th>Detalle</th><th class="text-end">Acción</th></tr></thead>
                    <tbody>
                    <?php foreach ($checklist as $check) { ?>
                        <tr>
                            <td><strong><?php echo esc($check['label']); ?></strong></td>
                            <td><span class="badge bg-<?php echo $check['status'] === 'OK' ? 'success' : 'warning'; ?>"><?php echo esc($check['status']); ?></span></td>
                            <td><?php echo esc($check['detail']); ?></td>
                            <td class="text-end">
                                <?php if ($check['action']) { ?>
                                    <a class="btn btn-default btn-sm" href="<?php echo get_uri($check['action']['path']); ?>"><?php echo esc($check['action']['label']); ?></a>
                                <?php } ?>
                            </td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
        <?php } ?>

        <?php if (! empty($status['blockers'])) { ?>
            <div class="alert alert-warning mt15">
                <strong>Timbrado bloqueado</strong>
                <ul class="mb0 mt10"><?php foreach ($status['blockers'] as $blocker) { ?><li><?php echo esc($blocker); ?></li><?php } ?></ul>
            </div>
        <?php } ?>
    </div>
</div>
