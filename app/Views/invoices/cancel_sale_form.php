<?php echo form_open(get_uri('invoices/cancel_sale'),['id'=>'cancel-sale-form','class'=>'general-form']); echo csrf_field(); ?>
<div class="modal-body">
    <input type="hidden" name="id" value="<?php echo (int)$invoice->id; ?>">
    <h5>Cancelar venta <?php echo esc($invoice->display_id??('#'.$invoice->id)); ?></h5>
    <dl><dt>Cliente</dt><dd><?php echo esc($invoice->company_name??$invoice->contact_name??'-'); ?></dd><dt>Total</dt><dd><?php echo to_currency($invoice->invoice_total??0,$invoice->currency_symbol??''); ?></dd></dl>
    <?php if(!$decision['allowed']): ?><div class="alert alert-danger"><?php echo esc($decision['message']); ?></div><?php endif; ?>
    <div class="form-group"><label for="sale-cancellation-reason">Motivo administrativo</label><textarea id="sale-cancellation-reason" name="cancellation_reason" class="form-control" maxlength="500" required></textarea><small class="text-muted">La venta y sus relaciones se conservarÃ¡n como historial. Esta operaciÃ³n no cancela un CFDI.</small></div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-default" data-bs-dismiss="modal">Volver</button><button type="submit" class="btn btn-danger" <?php echo $decision['allowed']?'':'disabled'; ?>>Cancelar venta</button></div>
<?php echo form_close(); ?>
<script>$(document).ready(function(){$('#cancel-sale-form').appForm({closeModalOnSuccess:true,onSuccess:function(r){appAlert.success(r.message);location.reload();}});});</script>
