<div class="card"><div class="card-header"><h4>Ambiente fiscal</h4></div><div class="card-body">
<div class="alert alert-warning"><strong>Modo de previsualización.</strong> Esta pantalla no modifica variables del servidor, credenciales ni documentos históricos.</div>
<dl class="row">
<dt class="col-md-4">Contexto actual</dt><dd class="col-md-8"><?= esc($preview['current']['context_id']) ?></dd>
<dt class="col-md-4">Ambiente actual</dt><dd class="col-md-8"><?= esc($preview['current']['fiscal_environment']) ?></dd>
<dt class="col-md-4">Destino solicitado</dt><dd class="col-md-8"><?= esc($preview['target_environment']) ?></dd>
<dt class="col-md-4">Transporte destino</dt><dd class="col-md-8"><?= esc($preview['target_transport']) ?></dd>
<dt class="col-md-4">Credencial destino</dt><dd class="col-md-8"><?= esc($preview['credential_check']) ?></dd>
<dt class="col-md-4">Operaciones pendientes</dt><dd class="col-md-8"><?= (int)$preview['operations']['total'] ?></dd>
</dl>
<?php foreach($preview['blocking_reasons'] as $reason): ?><div class="alert alert-secondary"><?= esc($reason) ?></div><?php endforeach; ?>
<?= form_open(get_uri('fiscal/environment/preview'), ['id'=>'fiscal-environment-preview','class'=>'general-form']) ?>
<div class="form-group"><label>Ambiente destino</label><select name="environment" class="form-control"><option value="development">development</option><option value="production">production</option></select></div>
<div class="form-group"><label>Motivo</label><textarea name="reason" class="form-control" minlength="12" maxlength="500" required></textarea></div>
<div class="form-group"><label>Confirmación</label><input name="confirmation" class="form-control" required><small>Use CAMBIAR AMBIENTE; para production use ACTIVAR PRODUCCION.</small></div>
<button class="btn btn-primary" type="submit">Registrar previsualización</button>
<?= form_close() ?>
<?php if($latest): ?><hr><small>Última solicitud: #<?= (int)$latest->id ?> · <?= esc($latest->status) ?> · <?= esc($latest->requested_at) ?></small><?php endif; ?>
</div></div>
<script>$(document).ready(function(){$('#fiscal-environment-preview').appForm({onSuccess:function(){location.reload();}});});</script>
