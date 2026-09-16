<?php echo form_open(get_uri('fiscal/items/save'), ['id'=>'item-fiscal-form','class'=>'general-form','role'=>'form']); ?>
<div class="modal-body clearfix"><div class="container-fluid">
    <input type="hidden" name="id" value="<?php echo $model_info->id ?? 0; ?>">
    <input type="hidden" name="item_id" value="<?php echo $item_id; ?>">
    <div class="alert alert-info"><?php echo app_lang('item_fiscal_optional_help'); ?></div>
    <?php if(!$can_manage){ ?><div class="alert alert-warning"><?php echo app_lang('fiscal_item_read_only'); ?></div><?php } ?>
    <?php if(in_array((string)($tax_object->code??''),['03','04'],true)){ ?><div class="alert alert-warning"><?php echo app_lang('fiscal_advanced_tax_object_warning'); ?></div><?php } ?>
    <?php $disabled=$can_manage?'':'disabled'; ?>

    <div class="form-group"><div class="row"><label class="col-md-3"><?php echo app_lang('item_type'); ?></label><div class="col-md-9"><?php echo form_dropdown('item_type',['product'=>app_lang('product'),'service'=>app_lang('service')],$model_info->item_type??'product',"class='form-control select2' $disabled"); ?></div></div></div>
    <div class="form-group"><div class="row"><label class="col-md-3"><?php echo app_lang('sat_product_service_key'); ?></label><div class="col-md-9"><input type="hidden" name="sat_product_service_key_id" id="sat-product-key" class="form-control" value="<?php echo (int)($model_info->sat_product_service_key_id??0)?:''; ?>" <?php echo $disabled; ?>></div></div></div>
    <div class="form-group"><div class="row"><label class="col-md-3"><?php echo app_lang('sat_unit_key'); ?></label><div class="col-md-9"><input type="hidden" name="sat_unit_key_id" id="sat-unit-key" class="form-control" value="<?php echo (int)($model_info->sat_unit_key_id??0)?:''; ?>" <?php echo $disabled; ?>></div></div></div>
    <div class="form-group"><div class="row"><label class="col-md-3"><?php echo app_lang('fiscal_applicable_taxes'); ?></label><div class="col-md-9"><?php echo form_multiselect('tax_ids[]',$taxes,$tax_ids,"id='fiscal-tax-ids' class='form-control select2' $disabled"); ?></div></div></div>

    <div class="card bg-light"><div class="card-body">
        <h5><?php echo app_lang('automatic_summary'); ?></h5>
        <div><strong><?php echo app_lang('commercial_unit'); ?>:</strong> <span id="summary-commercial-unit"><?php echo htmlspecialchars($item_info->unit_type?:'-'); ?></span></div>
        <div><strong><?php echo app_lang('used_description'); ?>:</strong> <span id="summary-description"><?php echo htmlspecialchars($item_info->description?:'-'); ?></span></div>
        <div><strong><?php echo app_lang('tax_object_code'); ?>:</strong> <span id="summary-tax-object"><?php echo htmlspecialchars(($tax_object->code??'-').' - '.($tax_object->description??app_lang('not_available'))); ?></span></div>
        <div><strong><?php echo app_lang('status'); ?>:</strong> <span id="summary-status" class="badge <?php echo $readiness['status']==='ready'?'bg-success':($readiness['status']==='incomplete'?'bg-warning':($readiness['status']==='inactive'?'bg-secondary':'bg-light text-dark')); ?>"><?php echo app_lang('item_fiscal_status_'.$readiness['status']); ?></span></div>
    </div></div>
</div></div>
<div class="modal-footer">
    <?php if($can_manage&&!empty($model_info->id)){if(($model_info->status??'')==='inactive'){ ?><button type="button" class="btn btn-default fiscal-state-action" data-url="<?php echo get_uri('fiscal/items/activate'); ?>"><?php echo app_lang('activate_fiscal_configuration'); ?></button><?php }else{ ?><button type="button" class="btn btn-danger fiscal-state-action" data-url="<?php echo get_uri('fiscal/items/deactivate'); ?>"><?php echo app_lang('deactivate_fiscal_configuration'); ?></button><?php }} ?>
    <button type="button" class="btn btn-default" data-bs-dismiss="modal"><?php echo app_lang('close'); ?></button>
    <?php if($can_manage){ ?><button type="submit" class="btn btn-primary"><?php echo app_lang('save'); ?></button><?php } ?>
</div>
<?php echo form_close(); ?>
<script>
$(document).ready(function(){
    $('#item-fiscal-form .select2').select2();
    function remote(selector,url,selected){$(selector).select2({allowClear:true,placeholder:'-',minimumInputLength:3,ajax:{url:url,type:'POST',dataType:'json',quietMillis:350,data:function(term,page){return {term:term||'',page:page||1,limit:20};},results:function(data){return {results:data.results||[],more:!!data.more};}},initSelection:function(element,callback){if(element.val()&&selected&&selected.text){callback(selected);}}});}
    remote('#sat-product-key','<?php echo get_uri('fiscal/catalogs/product-service/search'); ?>',<?php echo json_encode(['id'=>(int)($model_info->sat_product_service_key_id??0),'text'=>$product_label],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>);
    remote('#sat-unit-key','<?php echo get_uri('fiscal/catalogs/units/search'); ?>',<?php echo json_encode(['id'=>(int)($model_info->sat_unit_key_id??0),'text'=>$unit_label],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>);
    var advanced=<?php echo in_array((string)($tax_object->code??''),['03','04'],true)?'true':'false'; ?>;
    var inactive=<?php echo ($readiness['status']??'')==='inactive'?'true':'false'; ?>;
    function updateSummary(){
        if(!advanced){var hasTaxes=$('#fiscal-tax-ids option:selected').length>0;$('#summary-tax-object').text(hasTaxes?'02 - <?php echo addslashes(app_lang('tax_object_02_short')); ?>':'01 - <?php echo addslashes(app_lang('tax_object_01_short')); ?>');}
        var complete=$('[name="item_type"]').val()&&$('#sat-product-key').val()&&$('#sat-unit-key').val()&&<?php echo json_encode(trim((string)$item_info->description)!==''); ?>;
        var status=inactive?'<?php echo addslashes(app_lang('item_fiscal_status_inactive')); ?>':(complete?'<?php echo addslashes(app_lang('item_fiscal_status_ready')); ?>':'<?php echo addslashes(app_lang('item_fiscal_status_incomplete')); ?>');
        $('#summary-status').text(status).removeClass('bg-success bg-warning bg-secondary').addClass(inactive?'bg-secondary':(complete?'bg-success':'bg-warning'));
    }
    $('#sat-product-key,#sat-unit-key,#fiscal-tax-ids,[name="item_type"]').on('change',updateSummary);updateSummary();
    $('#item-fiscal-form').appForm({onSuccess:function(){location.reload();}});
    $('.fiscal-state-action').on('click',function(){var button=$(this);$.ajax({url:button.data('url'),type:'POST',dataType:'json',data:{item_id:<?php echo (int)$item_id; ?>},success:function(result){if(result.success){location.reload();}else{appAlert.error(result.message||'<?php echo addslashes(app_lang('error_occurred')); ?>');}}});});
});
</script>
