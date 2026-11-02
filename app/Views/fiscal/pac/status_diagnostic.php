<div class="card"><div class="card-header"><h4>Diagnóstico PAC</h4></div><div class="card-body">
<dl class="row">
<dt class="col-md-4">Contexto</dt><dd class="col-md-8"><?= esc($runtime['context_id']) ?></dd>
<dt class="col-md-4">Ambiente lógico</dt><dd class="col-md-8"><?= esc($runtime['fiscal_environment']) ?></dd>
<dt class="col-md-4">Transporte PAC</dt><dd class="col-md-8"><?= esc($runtime['transport_environment']) ?></dd>
<dt class="col-md-4">Adaptador</dt><dd class="col-md-8"><?= esc($runtime['pac_provider']) ?></dd>
<dt class="col-md-4">Endpoint permitido</dt><dd class="col-md-8"><?= $runtime['endpoint_allowed']?'Sí':'No' ?></dd>
<dt class="col-md-4">Host del proveedor</dt><dd class="col-md-8"><?= esc($runtime['provider_host'] ?: 'No aplica') ?></dd>
<dt class="col-md-4">Operación de timbrado</dt><dd class="col-md-8"><?= esc($runtime['stamping_operation']) ?></dd>
<dt class="col-md-4">Producción deshabilitada</dt><dd class="col-md-8"><?= $runtime['production_disabled']?'Sí':'No' ?></dd>
<dt class="col-md-4">Política de costo PAC</dt><dd class="col-md-8"><?= esc($runtime['provider_cost_policy']) ?></dd>
<dt class="col-md-4">Credencial del ambiente</dt><dd class="col-md-8"><?= $runtime['credential_present']?'Presente':'Ausente' ?></dd>
<dt class="col-md-4">Emisor</dt><dd class="col-md-8"><?= !empty($issuerReadiness['is_ready'])?'Listo':'Pendiente' ?></dd>
<dt class="col-md-4">CSD</dt><dd class="col-md-8"><?= !empty($csd['ready'])?'Listo':esc((string)($csd['label']??'Pendiente')) ?></dd>
<dt class="col-md-4">Fiscal habilitado</dt><dd class="col-md-8"><?= $runtime['fiscal_enabled']?'Sí':'No' ?></dd>
<dt class="col-md-4">Timbrado habilitado</dt><dd class="col-md-8"><?= $runtime['stamping_enabled']?'Sí':'No' ?></dd>
<dt class="col-md-4">PAC operativo</dt><dd class="col-md-8"><?= $runtime['pac_operational']?'Sí':'No' ?></dd>
</dl>
<?php foreach($runtime['blocking_reasons'] as $reason): ?><div class="alert alert-warning"><?= esc($reason) ?></div><?php endforeach; ?>
<div class="alert alert-info">Esta vista no muestra tokens, contraseñas, certificados ni llaves privadas.</div>
<div class="alert alert-warning">Este diagnóstico no ejecuta ninguna operación PAC. NOT_VERIFIED impide afirmar costo o consumo contractual.</div>
</div></div>
