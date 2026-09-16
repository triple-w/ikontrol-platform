<div class="card"><div class="card-header"><h4><?= app_lang('fiscal_stamp_balance') ?: 'Saldo de timbres' ?></h4></div><div class="card-body">
 <div class="row mb-4"><div class="col-md-6"><strong>Timbres disponibles:</strong> <?= (int)$balance['available'] ?></div><div class="col-md-6"><strong>Timbres reservados:</strong> <?= (int)$balance['reserved'] ?></div></div>
 <?php if(!empty($pacCredits)){?><div class="alert alert-secondary"><strong>Créditos PAC disponibles: <?= (int)$pacCredits->available_credits ?></strong><br><small>Cuenta técnica del proveedor · sólo monitoreo; no forma parte del wallet del cliente.</small></div><?php }?>
 <div class="table-responsive"><table class="table"><thead><tr><th>Fecha</th><th>Tipo</th><th>Cantidad</th><th>Documento</th></tr></thead><tbody>
 <?php foreach($movements as$m): ?><tr><td><?= esc($m['created_at']) ?></td><td><?= esc($m['movement_type']) ?></td><td><?= (int)$m['quantity'] ?></td><td><?= $m['fiscal_document_id'] ? (int)$m['fiscal_document_id'] : '-' ?></td></tr><?php endforeach; ?>
 </tbody></table></div><p class="text-muted">Vista de solo lectura. Los ajustes comerciales se realizan exclusivamente mediante una identidad de plataforma autorizada.</p>
</div></div>
