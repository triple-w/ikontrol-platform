<div class="card"><div class="card-header"><h4>Saldo de timbres · <?= esc($environment) ?></h4></div><div class="card-body">
<?php $commercial=$projection['commercial'];$pac=$projection['pac']; ?>
<div class="alert <?= $commercial['status']==='active'?'alert-success':'alert-warning' ?>"><strong>Wallet comercial local:</strong> <?= esc($commercial['reason']) ?></div>
<div class="row mb-4"><div class="col-md-3"><strong>Disponibles:</strong> <?= (int)$commercial['available'] ?></div><div class="col-md-3"><strong>Reservados:</strong> <?= (int)$commercial['reserved'] ?></div><div class="col-md-3"><strong>Consumidos:</strong> <?= (int)$commercial['consumed'] ?></div><div class="col-md-3"><strong>Utilizables:</strong> <?= (int)$commercial['usable'] ?></div></div>
<div class="alert alert-secondary"><strong>Créditos reportados por el PAC:</strong> <?= $pac['known']?(int)$pac['available_credits']:'No consultados para este emisor/ambiente' ?><br><small><?= esc($pac['reason']) ?></small></div>
<div class="table-responsive"><table class="table"><thead><tr><th>Fecha</th><th>Tipo</th><th>Cantidad</th><th>Documento</th></tr></thead><tbody>
<?php foreach($projection['movements'] as $movement): ?><tr><td><?= esc($movement['created_at']) ?></td><td><?= esc($movement['movement_type']) ?></td><td><?= (int)$movement['quantity'] ?></td><td><?= $movement['fiscal_document_id']?(int)$movement['fiscal_document_id']:'-' ?></td></tr><?php endforeach; ?>
</tbody></table></div><p class="text-muted">Vista de sólo lectura. Los créditos PAC nunca ajustan automáticamente la wallet comercial.</p>
</div></div>
