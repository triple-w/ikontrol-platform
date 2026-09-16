<?php echo form_open(get_uri('credit_notes/create'), ['id'=>'credit-note-create-form','class'=>'general-form']); echo csrf_field(); ?>
<div class="modal-body">
 <div class="form-group">
  <label>¿Cómo desea crear la Nota de Crédito?</label>
  <div><label class="me-3"><input type="radio" name="creation_mode" value="invoice" checked> Crear desde factura</label><label><input type="radio" name="creation_mode" value="manual"> Crear manualmente</label></div>
  <small id="credit-note-mode-help" class="text-muted">Se copiarán los conceptos fiscales disponibles de la factura.</small>
 </div>
 <div class="form-group">
  <label for="credit-note-client">Cliente</label>
  <select id="credit-note-client" name="client_id" class="form-control select2" required><option value="">Seleccione un cliente...</option><?php foreach($clients as $client): ?><option value="<?php echo (int)$client->id; ?>" <?php echo (int)$selected_client===(int)$client->id?'selected':''; ?>><?php echo esc($client->company_name.($client->vat_number?' · '.$client->vat_number:'')); ?></option><?php endforeach; ?></select>
 </div>
 <div class="form-group">
  <label for="credit-note-document">Factura relacionada</label>
  <select id="credit-note-document" name="source_document_id" class="form-control select2" required <?php echo $selected_client?'':'disabled'; ?>><option value="">Seleccione una factura...</option><?php foreach($documents as $document): ?><option value="<?php echo (int)$document->id; ?>" <?php echo (int)$selected===(int)$document->id?'selected':''; ?>><?php echo esc(trim($document->series.' '.$document->folio).' · '.$document->uuid.' · Disponible '.to_currency($document->credit_available)); ?></option><?php endforeach; ?></select>
  <small id="credit-note-doc-message" class="text-muted">Primero seleccione un cliente. La relación fiscal 1:1 se conserva en ambas modalidades.</small>
 </div>
 <div class="alert alert-info" id="manual-note" style="display:none">El generador se abrirá vacío. La factura seleccionada sólo establece el receptor, el límite acreditable y la relación SAT 01.</div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-default" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit">Continuar</button></div>
<?php echo form_close(); ?>
<script>
$(function(){
 var client=$('#credit-note-client'),documentSelect=$('#credit-note-document'),message=$('#credit-note-doc-message');
 $('.select2').select2();
 $('input[name="creation_mode"]').on('change',function(){var manual=this.value==='manual';$('#manual-note').toggle(manual);$('#credit-note-mode-help').text(manual?'No se copiarán conceptos; se abrirá un borrador vacío.':'Se copiarán los conceptos fiscales disponibles de la factura.');});
 client.on('change',function(){var id=$(this).val();documentSelect.empty().append(new Option('Seleccione una factura...','')).prop('disabled',!id).trigger('change');message.text(id?'Cargando facturas acreditables...':'Primero seleccione un cliente.');if(!id)return;$.ajax({url:'<?php echo get_uri('credit_notes/clients'); ?>/'+id+'/documents',type:'POST',data:{'<?php echo csrf_token(); ?>':'<?php echo csrf_hash(); ?>'},dataType:'json'}).done(function(r){$.each(r.results||[],function(_,item){documentSelect.append(new Option(item.text,item.id));});documentSelect.prop('disabled',false).trigger('change');message.text(r.message||'Seleccione una factura timbrada vigente.');}).fail(function(x){message.text((x.responseJSON||{}).message||'No fue posible cargar las facturas.');});});
 $('#credit-note-create-form').appForm({onSuccess:function(r){window.location.href=r.redirect_to;}});
});
</script>