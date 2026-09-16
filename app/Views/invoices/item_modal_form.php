<?php echo form_open(get_uri("invoices/save_item"), array("id" => "invoice-item-form", "class" => "general-form", "role" => "form")); ?>
<div class="modal-body clearfix">
    <div class="container-fluid">
        <input type="hidden" name="id" value="<?php echo $model_info->id; ?>" />
        <input type="hidden" id="item_id" name="item_id" value="<?php echo $model_info->item_id; ?>" />
        <input type="hidden" name="invoice_id" value="<?php echo $invoice_id; ?>" />
        <input type="hidden" name="fiscal_only" value="<?php echo !empty($fiscal_only)?1:0;?>" />
        <input type="hidden" name="add_new_item_to_library" value="" id="add_new_item_to_library" />
        <div class="form-group">
            <div class="row">
                <label for="invoice_item_title" class=" col-md-3"><?php echo app_lang('item'); ?></label>
                <div class="col-md-9">
                    <?php
                    echo form_input(array(
                        "id" => "invoice_item_title",
                        "name" => "invoice_item_title",
                        "value" => $model_info->title,
                        "class" => "form-control validate-hidden",
                        "placeholder" => app_lang('select_or_create_new_item'),
                        "data-rule-required" => true,
                        "data-msg-required" => app_lang("field_required"),
                    ));
                    ?>
                    <a id="invoice_item_title_dropdwon_icon" tabindex="-1" href="javascript:void(0);" style="color: #B3B3B3;float: right; padding: 5px 7px; margin-top: -35px; font-size: 18px;"><span>×</span></a>
                </div>
            </div>
        </div>
        <div class="form-group">
            <div class="row">
                <label for="invoice_item_description" class="col-md-3"><?php echo app_lang('description'); ?></label>
                <div class=" col-md-9">
                    <?php
                    echo form_textarea(array(
                        "id" => "invoice_item_description",
                        "name" => "invoice_item_description",
                        "value" => $model_info->description ? $model_info->description : "",
                        "class" => "form-control",
                        "placeholder" => app_lang('description')
                    ));
                    ?>
                </div>
            </div>
        </div>
        <div class="form-group">
            <div class="row">
                <label for="invoice_item_quantity" class=" col-md-3"><?php echo app_lang('quantity'); ?></label>
                <div class="col-md-9">
                    <?php
                    echo form_input(array(
                        "id" => "invoice_item_quantity",
                        "name" => "invoice_item_quantity",
                        "value" => $model_info->quantity ? to_decimal_format($model_info->quantity) : "",
                        "class" => "form-control",
                        "placeholder" => app_lang('quantity'),
                        "data-rule-required" => true,
                        "data-msg-required" => app_lang("field_required"),
                    ));
                    ?>
                </div>
            </div>
        </div>
        <div class="form-group">
            <div class="row">
                <label for="invoice_unit_type" class=" col-md-3"><?php echo app_lang('unit_type'); ?></label>
                <div class="col-md-9">
                    <?php
                    echo form_input(array(
                        "id" => "invoice_unit_type",
                        "name" => "invoice_unit_type",
                        "value" => $model_info->unit_type,
                        "class" => "form-control",
                        "placeholder" => app_lang('unit_type') . ' (Ex: hours, pc, etc.)'
                    ));
                    ?>
                </div>
            </div>
        </div>
        <?php if(empty($fiscal_only))echo view('items/_supplier_cost_fields',array_merge(get_defined_vars(),['field_prefix'=>'invoice'])); ?>
        <div class="form-group">
            <div class="row">
                <label for="invoice_item_rate" class=" col-md-3">Precio de venta</label>
                <div class="col-md-9">
                    <?php
                    echo form_input(array(
                        "id" => "invoice_item_rate",
                        "name" => "invoice_item_rate",
                        "value" => $model_info->rate ? to_decimal_format($model_info->rate) : "",
                        "class" => "form-control",
                        "placeholder" => app_lang('rate'),
                        "data-rule-required" => true,
                        "data-msg-required" => app_lang("field_required"),
                    ));
                    ?>
                </div>
            </div>
        </div>
        <?php echo view('items/_fiscal_item_fields',['fiscal_configuration'=>$fiscal_configuration??[],'sat_tax_codes'=>$sat_tax_codes??[],'sat_tax_objects'=>$sat_tax_objects??[],'can_update_master_fiscal'=>$can_update_master_fiscal??false]); ?>
    </div>
</div>

<div class="modal-footer">
    <button type="button" class="btn btn-default" data-bs-dismiss="modal"><span data-feather="x" class="icon-16"></span> <?php echo app_lang('close'); ?></button>
    <button type="submit" class="btn btn-primary"><span data-feather="check-circle" class="icon-16"></span> <?php echo app_lang('save'); ?></button>
</div>
<?php echo form_close(); ?>
<?php if(empty($fiscal_only))echo view('items/_supplier_cost_modals',array_merge(get_defined_vars(),['field_prefix'=>'invoice'])); ?>

<script type="text/javascript">
    $(document).ready(function() {
        $("#invoice-item-form").appForm({
            onSuccess: function(result) {
                $("#invoice-item-table").appTable({
                    newData: result.data,
                    dataId: result.id
                });
            }
        });

        //show item suggestion dropdown when adding new item
        var isUpdate = "<?php echo $model_info->id; ?>";
        if (!isUpdate) {
            applySelect2OnItemTitle();
        }

        //re-initialize item suggestion dropdown on request
        $("#invoice_item_title_dropdwon_icon").click(function() {
            applySelect2OnItemTitle();
        })

    });

    function applySelect2OnItemTitle() {
        $("#invoice_item_title").select2({
            showSearchBox: true,
            ajax: {
                url: "<?php echo get_uri("invoices/get_invoice_item_suggestion"); ?>",
                type: 'POST',
                dataType: 'json',
                quietMillis: 250,
                data: function(term, page) {
                    return {
                        q: term // search term
                    };
                },
                results: function(data, page) {
                    return {
                        results: data
                    };
                }
            }
        }).change(function(e) {
            if (e.val === "+") {
                //show simple textbox to input the new item
                $("#invoice_item_title").select2("destroy").val("").focus();
                $("#add_new_item_to_library").val(1); //set the flag to add new item in library
            } else if (e.val) {
                //get existing item info
                $("#add_new_item_to_library").val(""); //reset the flag to add new item in library
                appAjaxRequest({
                    url: "<?php echo get_uri("invoices/get_invoice_item_info_suggestion"); ?>",
                    data: {
                        item_id: e.val
                    },
                    cache: false,
                    type: 'POST',
                    dataType: "json",
                    success: function(response) {

                        //auto fill the description, unit type and rate fields.
                        if (response && response.success) {
                            $("#item_id").val(response.item_info.id);
                            $("#invoice_item_title").val(response.item_info.title);

                            $("#invoice_item_description").val(response.item_info.description);

                            $("#invoice_unit_type").val(response.item_info.unit_type);
                            $("#invoice_item_cost").val(response.item_info.cost || "").trigger('change');
                            $("#invoice_item_rate").val(response.item_info.rate);

                            $("#invoice-item-form").trigger('fiscal:item:load',[response.item_info.fiscal||{},response.item_info]);
                        }
                    }
                });
            }

        });
    }
</script>
