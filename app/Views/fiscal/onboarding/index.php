<div class="card">
    <div class="page-title clearfix">
        <h4>Fiscal onboarding</h4>
        <span class="badge bg-<?php echo $status['ready'] ? 'success' : 'warning'; ?> float-end">
            <?php echo esc($status['state']); ?>
        </span>
    </div>
    <div class="card-body">
        <?php if ($status['blockers']) { ?>
            <div class="alert alert-warning">
                <strong>Stamping: BLOCKED</strong>
                <ul class="mb-0">
                    <?php foreach ($status['blockers'] as $blocker) { ?>
                        <li><?php echo esc($blocker); ?></li>
                    <?php } ?>
                </ul>
            </div>
        <?php } ?>
        <?php if ($status['state'] === 'NOT_INSTALLED') { ?>
            <p>Faltan tablas fiscales: <?php echo esc(implode(', ', $status['details']['missing_tables'] ?? [])); ?></p>
        <?php } else { ?>
            <table class="table table-striped">
                <tbody>
                    <tr><th>Emisor</th><td><?php echo esc($status['details']['issuer']['status']); ?></td></tr>
                    <tr><th>CSD</th><td><?php echo esc($status['details']['csd']['status']); ?></td></tr>
                    <tr><th>Series</th><td><?php echo esc($status['details']['series']['status']); ?></td></tr>
                    <tr><th>PAC</th><td><?php echo esc($status['details']['pac']['status']); ?></td></tr>
                    <tr><th>Catálogo SAT productos</th><td><?php echo esc($status['details']['catalogs']['product-service']['status']); ?></td></tr>
                    <tr><th>Productos incompletos</th><td><?php echo (int) $status['details']['products']['incomplete']; ?></td></tr>
                    <tr><th>Clientes incompletos</th><td><?php echo (int) $status['details']['clients']['incomplete']; ?></td></tr>
                    <tr><th>Métodos de pago</th><td><?php echo (int) $status['details']['payment_methods']['mapped']; ?> / <?php echo (int) $status['details']['payment_methods']['available']; ?> mapeados</td></tr>
                    <tr><th>Timbrado</th><td><?php echo esc($status['details']['stamping']['status']); ?></td></tr>
                </tbody>
            </table>
        <?php } ?>
    </div>
</div>
