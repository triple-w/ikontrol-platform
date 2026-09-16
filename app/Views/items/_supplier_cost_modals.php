<?php if (!empty($can_view_supplier_costs)): $prefix=$field_prefix; ?>
<?php if(!empty($can_manage_suppliers)&&!empty($can_edit_supplier_costs)): ?>
<div class="modal fade quick-supplier-modal" data-prefix="<?php echo esc($prefix,'attr'); ?>" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-sm"><div class="modal-content">
<?php echo form_open(get_uri('suppliers/save'),['class'=>'general-form quick-supplier-form']); ?>
<div class="modal-header"><h5 class="modal-title">Nuevo proveedor</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body"><?php echo view('suppliers/form_fields',['model_info'=>(object)['id'=>0,'status'=>'active'],'prefix'=>$prefix.'-quick-supplier']); ?></div>
<div class="modal-footer"><button type="button" class="btn btn-default" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Guardar</button></div>
<?php echo form_close(); ?></div></div></div>
<?php endif; ?>
<div class="modal fade supplier-comparison-modal" data-prefix="<?php echo esc($prefix,'attr'); ?>" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-xl"><div class="modal-content supplier-comparison-content"></div></div></div>
<?php endif; ?>
