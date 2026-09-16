<?php $dateErrors=array_values(array_filter($review['errors'],static fn($error)=>stripos((string)$error,'fecha de emisión')!==false)); ?>
<div class="modal-body">
 <h4>Revisión fiscal — Nota de Crédito</h4>
 <dl class="row">
  <dt class="col-4">Tipo de comprobante</dt><dd class="col-8">E — Egreso</dd>
  <dt class="col-4">TipoRelacion</dt><dd class="col-8">01 — Nota de crédito de los documentos relacionados</dd>
  <dt class="col-4">Fecha de emisión</dt><dd class="col-8"><?php echo esc($note->issue_date); ?> <?php if($dateErrors): ?><span class="badge bg-danger">Bloqueante</span><div class="text-danger"><?php echo esc(implode(' ',$dateErrors)); ?></div><?php else: ?><span class="badge bg-success">Correcto</span><?php endif; ?></dd>
  <dt class="col-4">UUID relacionado</dt><dd class="col-8"><code><?php echo esc($note->source_uuid); ?></code></dd>
  <dt class="col-4">Receptor</dt><dd class="col-8"><?php echo esc($note->receiver_name.' · '.$note->receiver_rfc); ?></dd>
  <dt class="col-4">Conceptos</dt><dd class="col-8"><?php echo count($items); ?></dd>
  <dt class="col-4">Total</dt><dd class="col-8"><?php echo to_currency($note->total); ?></dd>
 </dl>
 <?php if(!empty($review['square'])): $square=$review['square']; ?>
 <h5>Cuadre fiscal</h5>
 <dl class="row">
  <dt class="col-6">Subtotal</dt><dd class="col-6 text-end"><?php echo to_currency($square['subtotal']); ?></dd>
  <dt class="col-6">Descuentos</dt><dd class="col-6 text-end"><?php echo to_currency($square['discount']); ?></dd>
  <dt class="col-6">Traslados</dt><dd class="col-6 text-end"><?php echo to_currency($square['transferred']); ?></dd>
  <dt class="col-6">Retenciones</dt><dd class="col-6 text-end"><?php echo to_currency($square['withheld']); ?></dd>
  <dt class="col-6">Total calculado</dt><dd class="col-6 text-end"><?php echo to_currency($square['calculated']); ?></dd>
  <dt class="col-6">Total CFDI</dt><dd class="col-6 text-end"><?php echo to_currency($square['total']); ?></dd>
  <dt class="col-6">Diferencia</dt><dd class="col-6 text-end"><?php echo to_currency($square['difference']); ?> <span class="badge bg-<?php echo $square['valid']?'success':'danger'; ?>"><?php echo $square['valid']?'Correcto':'Bloqueante'; ?></span></dd>
 </dl>
 <?php endif; ?>
 <?php if($review['ready']): ?><div class="alert alert-success">Documento listo para timbrar.</div><?php else: ?><div class="alert alert-danger">Documento NO listo para timbrar.<ul><?php foreach($review['errors']as$e): ?><li><?php echo esc($e); ?></li><?php endforeach; ?></ul></div><?php endif; ?>
</div>
<div class="modal-footer"><button class="btn btn-default" data-bs-dismiss="modal">Cerrar</button></div>
