<?php echo form_open(get_uri('suppliers/manual-cost/save'), ['id'=>'manual-supplier-cost-form','class'=>'general-form']); ?>
<div class="modal-body">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="supplier_id" value="<?php echo (int)$supplier->id; ?>">
    <input type="hidden" name="idempotency_key" value="<?php echo esc($idempotency_key); ?>">
    <p class="text-muted mb15">Proveedor: <strong><?php echo esc($supplier->name); ?></strong>. Esta captura no crea documentos comerciales.</p>
    <div class="form-group"><label>Producto</label><?php echo form_dropdown('product_id',[''=>'Seleccione producto']+$items,'',['class'=>'form-control select2','required'=>'required']); ?></div>
    <div class="form-group"><label>Costo unitario (MXN)</label><input class="form-control" name="unit_cost" inputmode="decimal" required></div>
    <div class="form-group"><label>Fecha <span class="text-muted">(opcional)</span></label><input class="form-control" type="date" name="quoted_at"></div>
    <div class="form-group"><label>Notas <span class="text-muted">(opcional)</span></label><textarea class="form-control" name="notes"></textarea></div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-default" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit">Guardar costo</button></div>
<?php echo form_close(); ?>
<script>$(function(){$('#manual-supplier-cost-form').appForm({onSuccess:function(){location.reload();}});});</script>
