<?php
$configurePdfAllowed = !empty($configure_pdf_allowed);
?>
<form class="fiscal-pdf-regeneration-csrf"><?php echo csrf_field(); ?></form>
<div class="modal fade fiscal-pdf-regeneration-modal" tabindex="-1" aria-hidden="true">
 <div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title">Regenerar PDF</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
   <p>Se volverá a generar la representación PDF utilizando el XML timbrado y la plantilla configurada.</p>
   <p><strong>El UUID y el XML no cambiarán.</strong></p>
   <dl><dt>Documento</dt><dd class="fiscal-pdf-regeneration-document"></dd><dt>UUID</dt><dd><code class="fiscal-pdf-regeneration-uuid"></code></dd><dt>Plantilla</dt><dd class="fiscal-pdf-regeneration-template"></dd></dl>
   <div class="fiscal-pdf-regeneration-progress text-info"></div>
   <div class="fiscal-pdf-regeneration-error alert alert-danger d-none"></div>
   <?php if($configurePdfAllowed): ?><a class="btn btn-default fiscal-pdf-configure d-none" href="<?php echo get_uri('fiscal/pdf-templates'); ?>">Configurar plantilla</a><?php endif; ?>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-default" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-primary fiscal-pdf-regeneration-submit">Regenerar PDF</button></div>
 </div></div>
</div>
<script>
$(function(){
 var pending=null,modalElement=document.querySelector('.fiscal-pdf-regeneration-modal'),modal=modalElement?new bootstrap.Modal(modalElement):null;
 $(document).on('click','.fiscal-regenerate-pdf',function(){
  pending=$(this);if(!modal||pending.data('busy'))return false;
  $(modalElement).find('.fiscal-pdf-regeneration-document').text(pending.data('document-label')||('#'+pending.data('document-id')));
  $(modalElement).find('.fiscal-pdf-regeneration-uuid').text(pending.data('uuid')||'-');
  $(modalElement).find('.fiscal-pdf-regeneration-template').text(pending.data('template')||'-');
  $(modalElement).find('.fiscal-pdf-regeneration-progress').text('');
  $(modalElement).find('.fiscal-pdf-regeneration-error,.fiscal-pdf-configure').addClass('d-none');
  $(modalElement).find('.fiscal-pdf-regeneration-submit').prop('disabled',false);
  modal.show();return false;
 });
 $(modalElement).on('hide.bs.modal',function(){if($.contains(this,document.activeElement))document.activeElement.blur();});
 $(modalElement).on('click','.fiscal-pdf-regeneration-submit',function(){
  if(!pending||pending.data('busy'))return;
  var submit=$(this),form=$('.fiscal-pdf-regeneration-csrf'),token=form.find('input'),data={};
  data[token.attr('name')]=token.val();pending.data('busy',true);submit.prop('disabled',true);
  $(modalElement).find('.fiscal-pdf-regeneration-progress').text('Regenerando PDF…');
  $.ajax({url:'<?php echo get_uri('fiscal/documents'); ?>/'+pending.data('document-id')+'/pdf/regenerate',type:'POST',data:data,dataType:'json'})
   .done(function(result){if(result.csrf)token.attr('name',result.csrf.name).val(result.csrf.hash);appAlert.success(result.message||'PDF regenerado correctamente.');modal.hide();window.location.reload();})
   .fail(function(xhr){var result=xhr.responseJSON||{},message=result.message||'No fue posible regenerar el PDF.';if(result.csrf)token.attr('name',result.csrf.name).val(result.csrf.hash);$(modalElement).find('.fiscal-pdf-regeneration-progress').text('');$(modalElement).find('.fiscal-pdf-regeneration-error').removeClass('d-none').text(message);if(result.configure_url)$(modalElement).find('.fiscal-pdf-configure').removeClass('d-none').attr('href',result.configure_url);appAlert.error(message);})
   .always(function(){pending.data('busy',false);submit.prop('disabled',false);});
 });
});
</script>
