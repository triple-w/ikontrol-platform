<?php $prefix = $prefix ?? 'supplier'; $supplier = $model_info ?? (object) []; ?>
<?php echo csrf_field(); ?>
<input type="hidden" name="id" value="<?php echo (int) ($supplier->id ?? 0); ?>">
<input type="hidden" name="status" value="<?php echo esc($supplier->status ?? 'active'); ?>">
<input type="hidden" name="allow_similar_name" value="0" data-supplier-allow-similar>
<div class="alert alert-warning hide" data-supplier-duplicate><div data-supplier-duplicate-message></div><div class="mt10"><strong data-supplier-existing-name></strong><br><span data-supplier-existing-detail></span></div><div class="mt10"><button type="button" class="btn btn-default btn-sm" data-supplier-use-existing>Usar proveedor existente</button> <button type="button" class="btn btn-warning btn-sm hide" data-supplier-create-anyway>Crear de todos modos</button></div></div>
<div class="form-group"><label for="<?php echo $prefix; ?>-name">Nombre comercial</label><input id="<?php echo $prefix; ?>-name" class="form-control" name="name" value="<?php echo esc($supplier->name ?? ''); ?>" required></div>
<div class="form-group"><label for="<?php echo $prefix; ?>-rfc">RFC <span class="text-muted">(opcional)</span></label><input id="<?php echo $prefix; ?>-rfc" class="form-control text-uppercase" name="rfc" maxlength="13" value="<?php echo esc($supplier->rfc ?? ''); ?>"></div>
<?php foreach (['contact_name' => 'Contacto', 'phone' => 'Teléfono', 'email' => 'Correo'] as $field => $label): ?><div class="form-group"><label for="<?php echo $prefix . '-' . $field; ?>"><?php echo $label; ?></label><input id="<?php echo $prefix . '-' . $field; ?>" class="form-control" name="<?php echo $field; ?>" value="<?php echo esc($supplier->$field ?? ''); ?>"></div><?php endforeach; ?>
<div class="form-group"><label for="<?php echo $prefix; ?>-notes">Notas</label><textarea id="<?php echo $prefix; ?>-notes" class="form-control" name="notes"><?php echo esc($supplier->notes ?? ''); ?></textarea></div>
