<?php echo form_open(get_uri("taxes/save"), array("id" => "tax-form", "class" => "general-form", "role" => "form")); ?>
<div class="modal-body clearfix">
    <div class="container-fluid">
        <input type="hidden" name="id" value="<?php echo $model_info->id; ?>" />
        <div class="form-group">
            <div class="row">
                <label for="title" class=" col-md-3"><?php echo app_lang('tax_title'); ?></label>
                <div class=" col-md-9">
                    <?php
                    echo form_input(array(
                        "id" => "title",
                        "name" => "title",
                        "value" => $model_info->title,
                        "class" => "form-control",
                        "placeholder" => app_lang('tax_title'),
                        "autofocus" => true,
                        "data-rule-required" => true,
                        "data-msg-required" => app_lang("field_required"),
                    ));
                    ?>
                </div>
            </div>
        </div>
        <?php if ($can_manage_fiscal_tax_settings) { ?><hr><h5><?php echo app_lang('optional_fiscal_configuration'); ?></h5>
        <div class="alert alert-warning"><?php echo app_lang('fiscal_tax_does_not_generate_cfdi'); ?></div>
        <?php $fields = [
            ['use_for_administrative','checkbox',app_lang('use_for_administrative')], ['use_for_fiscal','checkbox',app_lang('use_for_fiscal')],
            ['sat_tax_code_id','dropdown',app_lang('sat_tax_code'),$tax_codes], ['fiscal_tax_type','dropdown',app_lang('fiscal_tax_type'),[''=>'-','transfer'=>app_lang('transfer'),'withholding'=>app_lang('withholding')]],
            ['factor_type_id','dropdown',app_lang('factor_type'),$factor_types], ['xml_rate','input',app_lang('xml_rate')], ['xml_quota','input',app_lang('xml_quota')], ['fiscal_notes','input',app_lang('fiscal_notes')]
        ]; foreach($fields as $f){ $name=$f[0]; ?>
        <div class="form-group"><div class="row"><label class="col-md-3" for="<?php echo $name; ?>"><?php echo $f[2]; ?></label><div class="col-md-9">
        <?php if($f[1]==='checkbox') echo form_checkbox($name,'1',property_exists($model_info,$name) ? (bool)$model_info->$name : $name==='use_for_administrative',"id='$name' class='form-check-input'");
        elseif($f[1]==='dropdown') echo form_dropdown($name,$f[3],property_exists($model_info,$name)?$model_info->$name:'',"id='$name' class='form-control select2'");
        else echo form_input(['name'=>$name,'id'=>$name,'value'=>property_exists($model_info,$name)?$model_info->$name:'','class'=>'form-control']); ?>
        </div></div></div><?php } ?>
        <p class="text-muted"><?php echo app_lang('xml_rate_help'); ?></p>
        <?php } ?>
        <div class="form-group">
            <div class="row">
                <label for="percentage" class=" col-md-3"><?php echo app_lang('percentage'); ?></label>
                <div class=" col-md-9">
                    <?php
                    echo form_input(array(
                        "id" => "percentage",
                        "name" => "percentage",
                        "value" => $model_info->percentage ? to_decimal_format($model_info->percentage) : "",
                        "class" => "form-control",
                        "placeholder" => app_lang('percentage'),
                        "data-rule-required" => true,
                        "data-msg-required" => app_lang("field_required"),
                    ));
                    ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal-footer">
    <button type="button" class="btn btn-default" data-bs-dismiss="modal"><span data-feather="x" class="icon-16"></span> <?php echo app_lang('close'); ?></button>
    <button type="submit" class="btn btn-primary"><span data-feather="check-circle" class="icon-16"></span> <?php echo app_lang('save'); ?></button>
</div>
<?php echo form_close(); ?>

<script type="text/javascript">
        $(document).ready(function () {
        $("#tax-form").appForm({
            onSuccess: function (result) {
                $("#taxes-table").appTable({newData: result.data, dataId: result.id});
            }
        });
        function toggleFiscalFields() { var enabled = $('#use_for_fiscal').is(':checked'); $('#sat_tax_code_id, #fiscal_tax_type, #factor_type_id, #xml_rate, #xml_quota, #fiscal_notes').prop('disabled', !enabled); }
        $('#use_for_fiscal').on('change', toggleFiscalFields); toggleFiscalFields();
        $('.select2').select2();
        setTimeout(function () {
            $("#title").focus();
        }, 200);
    });
</script>
