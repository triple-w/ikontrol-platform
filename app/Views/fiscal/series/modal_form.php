<?php echo form_open(get_uri('fiscal/series/save'), ['id' => 'series-form', 'class' => 'general-form']); ?>
<div class="modal-body">
    <input type="hidden" name="id" value="<?php echo (int) $model_info->id; ?>">
    <?php if (! empty($issuer_required)) { ?>
        <div class="alert alert-warning">
            <strong>Primero completa un emisor fiscal.</strong><br>
            Una serie sólo puede pertenecer a un emisor activo, completo y correspondiente al ambiente fiscal actual.
            <div class="mt10"><a class="btn btn-default btn-sm" href="<?php echo get_uri('fiscal/issuers'); ?>">Configurar emisor</a></div>
        </div>
    <?php } ?>
    <div class="form-group row"><label class="col-md-3"><?php echo app_lang('fiscal_issuer'); ?></label><div class="col-md-9"><?php echo form_dropdown('issuer_profile_id', $issuers, $model_info->issuer_profile_id, "class='select2 form-control'"); ?></div></div>
    <div class="form-group row"><label class="col-md-3"><?php echo app_lang('document_type'); ?></label><div class="col-md-9"><?php echo form_dropdown('document_type', ['ingreso' => app_lang('fiscal_document_type_ingreso'), 'egreso' => app_lang('fiscal_document_type_egreso'), 'pago' => app_lang('fiscal_document_type_pago')], $model_info->document_type ?: 'ingreso', "class='select2 form-control'"); ?></div></div>
    <?php foreach ([['series', app_lang('fiscal_series_single')], ['initial_folio', app_lang('initial_folio')]] as $field) { ?><div class="form-group row"><label class="col-md-3"><?php echo $field[1]; ?></label><div class="col-md-9"><?php echo form_input(['name' => $field[0], 'value' => $model_info->{$field[0]} ?: ($field[0] === 'initial_folio' ? 1 : ''), 'class' => 'form-control']); ?></div></div><?php } ?>
    <?php foreach ([['is_default', app_lang('default')], ['is_active', app_lang('active')]] as $field) { ?><div class="form-group row"><label class="col-md-3"><?php echo $field[1]; ?></label><div class="col-md-9"><?php echo form_checkbox($field[0], '1', $model_info->id ? (bool) $model_info->{$field[0]} : true, "class='form-check-input'"); ?></div></div><?php } ?>
    <div id="series-business-validation"></div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-default" data-bs-dismiss="modal"><?php echo app_lang('close'); ?></button><button class="btn btn-primary" type="submit" <?php echo ! empty($issuer_required) ? 'disabled' : ''; ?>><?php echo app_lang('save'); ?></button></div>
<?php echo form_close(); ?>
<script>$(document).ready(function(){var escapeHtml=function(value){return $('<div>').text(value||'').html();};$('#series-form').appForm({onAjaxSuccess:function(result){if(!result.success&&result.code==='FISCAL_ISSUER_REQUIRED'){var html='<div class="alert alert-warning"><strong>'+escapeHtml(result.message)+'</strong>';if(result.errors&&result.errors.length){html+='<ul class="mb0 mt10">';$.each(result.errors,function(_,error){html+='<li>'+escapeHtml(error)+'</li>';});html+='</ul>';}if(result.configuration_url){html+='<div class="mt10"><a class="btn btn-default btn-sm" href="'+escapeHtml(result.configuration_url)+'">'+escapeHtml(result.action||'Configurar emisor')+'</a></div>';}$('#series-business-validation').html(html+'</div>');}},onSuccess:function(){location.reload();}});$('#series-form .select2').select2();});</script>
