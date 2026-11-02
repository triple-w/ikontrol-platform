<?php echo form_open(get_uri('fiscal/invoices/sat-status'),['id'=>'sat-status-form','class'=>'general-form']); echo csrf_field(); ?>
<div class="modal-body">
    <input type="hidden" name="document_id" value="<?php echo (int)$document->id; ?>">
    <input type="hidden" name="query_key" value="<?php echo esc($query_key); ?>">
    <h5>Consultar estado SAT</h5>
    <dl>
        <dt>Factura</dt><dd><?php echo esc(trim($document->series.'-'.$document->folio)); ?></dd>
        <dt>UUID</dt><dd><code><?php echo esc($stamp->uuid); ?></code></dd>
        <dt>Ambiente del CFDI</dt><dd><?php echo $environment==='production'?'Producción':'Pruebas'; ?></dd>
        <dt>Última consulta</dt><dd><?php echo esc($latest->completed_at??$latest->started_at??'Sin consultas'); ?></dd>
        <dt>Último resultado</dt><dd><?php echo esc($latest->normalized_status??'Sin resultado'); ?></dd>
    </dl>
    <?php if($delegates_to_cancellation): ?>
        <div class="alert alert-warning">Existe una solicitud de cancelación pendiente. Esta acción consultará ese flujo y conservará su política comercial actual de 1 timbre por consulta enviada.</div>
    <?php else: ?>
        <div class="alert alert-warning">El costo de <code>consultarEstadoSAT</code> no está confirmado contractualmente. iKontrol no descontará un timbre comercial por esta consulta ordinaria; el proveedor podría contabilizarla según su contrato.</div>
    <?php endif; ?>
    <?php if(!$eligible): ?><div class="alert alert-danger"><?php echo esc($blocked_reason); ?></div><?php endif; ?>
    <p>Confirme para realizar una sola consulta. No se enviará una solicitud de cancelación y no habrá reintentos automáticos.</p>
</div>
<div class="modal-footer"><button type="button" class="btn btn-default" data-bs-dismiss="modal">Volver</button><button type="submit" class="btn btn-primary" <?php echo $eligible?'':'disabled'; ?>>Consultar estado SAT</button></div>
<?php echo form_close(); ?>
<script>$(document).ready(function(){var submitted=false;$('#sat-status-form').appForm({onSubmit:function(){if(submitted)return false;submitted=true;$('#sat-status-form button[type=submit]').prop('disabled',true).text('Consultando…');return true;},closeModalOnSuccess:true,onSuccess:function(r){appAlert.success(r.message);var table=$('#fiscal-invoices-table');if(table.length)table.appTable({reload:true});else location.reload();}});});</script>
