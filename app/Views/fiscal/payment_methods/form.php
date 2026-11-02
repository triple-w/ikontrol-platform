<?php echo form_open(get_uri('fiscal/payment-methods/save'),['id'=>'fiscal-payment-method-form','class'=>'general-form']); ?>
<div class="modal-body"><input type="hidden" name="payment_method_id" value="<?php echo(int)$method->id; ?>"><h5><?php echo esc($method->title); ?></h5>
<?php if($duplicate): ?><div class="alert alert-danger">Existen mappings duplicados. No se modificará ninguno automáticamente; requiere revisión dirigida.</div><?php endif; ?>
<div class="form-group"><label>Forma de pago SAT</label><?php echo form_dropdown('sat_payment_form_code',$forms,$mapping&&$mapping->is_active?$mapping->sat_payment_form_code:'',"class='form-control select2' ".($duplicate?'disabled':'')); ?></div>
<div class="alert alert-info">Dejar vacío desactiva el mapping existente. No modifica pagos ni CFDI históricos.</div></div>
<div class="modal-footer"><button type="button" class="btn btn-default" data-bs-dismiss="modal">Cerrar</button><button type="submit" class="btn btn-primary" <?php echo$duplicate?'disabled':''; ?>>Guardar mapping</button></div><?php echo form_close(); ?>
<script>$(document).ready(function(){$('#fiscal-payment-method-form .select2').select2();$('#fiscal-payment-method-form').appForm({onSuccess:function(){location.reload();}});});</script>
