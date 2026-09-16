<div class="modal-body clearfix general-form">
    <div class="container-fluid">

        <?php
        if ($model_info->files) {
            $files = @unserialize($model_info->files);
            if (count($files)) {
                if (!isset($login_user->id) || (isset($login_user->id) && !$login_user->is_admin)) {
                    ?>
                    <div class="col-md-12 mt15">
                        <?php
                        if ($files) {
                            $total_files = count($files);
                            echo view("includes/timeline_preview", array("files" => $files));
                        }
                        ?>
                    </div>
                    <?php
                }
            }
        }
        ?>

        <div class="clearfix">
            <div class="col-md-12">
                <strong class="font-18"><?php echo $model_info->title; ?></strong>
                <?php if ($model_info->show_in_client_portal && isset($login_user->id) && $login_user->is_admin && get_setting("module_order")) { ?>
                    <span class="ml5 text-off font-11" data-bs-toggle="tooltip" data-placement="right" title="<?php echo app_lang('showing_in_client_portal'); ?>"><i data-feather="shopping-cart" class="icon-16"></i></span>
                <?php } ?>
                <?php if ($model_info->taxable && isset($login_user->id)) { ?>
                    <span class="ml5 text-off font-11" data-bs-toggle="tooltip" data-placement="right" title="<?php echo app_lang('taxable'); ?>"><i data-feather="file-text" class="icon-16"></i></span>
                <?php } ?>
            </div>
        </div>

        <div class="col-md-12 mb15">
            <span class="badge item-rate-badge font-18 strong"><?php echo to_currency($model_info->rate); ?></span> <?php echo $model_info->unit_type ? "/" . $model_info->unit_type : ""; ?>
        </div>

        <div class="col-md-12 mb15">
            <?php echo $model_info->description ? custom_nl2br(link_it(process_images_from_content($model_info->description))) : "-"; ?>
        </div>

        <?php
        if ($model_info->files) {
            $files = @unserialize($model_info->files);
            if (count($files)) {
                if (isset($login_user->id) && $login_user->is_admin && get_setting("module_order")) {
                    ?>
                    <div class="col-md-12 mt15">
                        <div class="mb15 text-off"><i data-feather="help-circle" class="icon-16"></i> <?php echo app_lang("item_image_sorting_help_message"); ?></div>
                        <div class="row">
                            <?php echo view("includes/sortable_file_list", array("files" => $model_info->files, "action_url" => get_uri("items/save_files_sort"), "id" => $model_info->id)); ?>
                        </div>
                    </div>
                    <?php
                }
            }
        }
        ?>

        <?php
            if (count($custom_fields_list)) {
                foreach ($custom_fields_list as $data) {
                    if ($data->value) {
                        ?>
                        <div class="col-md-12 pt10">
                            <strong><?php echo $data->title . ": "; ?> </strong> <?php echo view("custom_fields/output_" . $data->field_type, array("value" => $data->value)); ?>
                        </div>
                        <?php
                    }
                }
            }
        ?>

    </div>
</div>

<?php if(!empty($can_view_supplier_costs)): ?><div class="col-md-12 mt15"><hr><h5>Proveedores y costos</h5><div class="row mb10"><div class="col-md-3"><small>Último costo</small><br><b><?php echo isset($supplier_cost_indicators['last_cost'])?to_currency($supplier_cost_indicators['last_cost']):'-'; ?></b></div><div class="col-md-3"><small>Último proveedor</small><br><b><?php echo esc($supplier_cost_indicators['last_supplier']??'-'); ?></b></div><div class="col-md-3"><small>Mejor histórico</small><br><b><?php echo isset($supplier_cost_indicators['best_cost'])?to_currency($supplier_cost_indicators['best_cost']):'-'; ?></b></div><div class="col-md-3"><small>Proveedores</small><br><b><?php echo(int)($supplier_cost_indicators['supplier_count']??0); ?></b></div></div><?php if(!empty($supplier_cost_summary)): ?><table class="table table-hover"><thead><tr><th>Proveedor</th><th>Último costo</th><th>Última fecha</th><th>Veces cotizado</th></tr></thead><tbody><?php foreach($supplier_cost_summary as$r): ?><tr><td><a href="<?php echo get_uri('suppliers/view/'.$r->supplier_id); ?>"><?php echo esc($r->supplier_name); ?></a></td><td><?php echo to_currency($r->last_cost); ?></td><td><?php echo esc(format_to_date($r->last_date,false)); ?></td><td><?php echo(int)$r->quote_count; ?></td></tr><?php endforeach; ?></tbody></table><details><summary>Ver historial de costos</summary><table class="table mt10"><thead><tr><th>Fecha</th><th>Proveedor</th><th>Costo</th><th>Cotización</th><th>Cliente</th><th>Precio venta</th></tr></thead><tbody><?php foreach(($supplier_cost_history??[])as$h): ?><tr><td><?php echo esc(format_to_date($h->quoted_at,false)); ?></td><td><a href="<?php echo get_uri('suppliers/view/'.$h->supplier_id); ?>"><?php echo esc($h->supplier_name); ?></a></td><td><?php echo to_currency($h->unit_cost); ?></td><td><a href="<?php echo get_uri('proposals/view/'.$h->proposal_id); ?>">#<?php echo(int)$h->proposal_id; ?></a></td><td><?php echo esc($h->company_name); ?></td><td><?php echo to_currency($h->sale_unit_price); ?></td></tr><?php endforeach; ?></tbody></table></details><?php else: ?><div class="alert alert-info">No existe historial comercial formalizado para este producto. Las propuestas en borrador no generan registros históricos.</div><?php endif; ?></div><?php endif; ?><div class="modal-footer">
    <?php
    if (isset($login_user->id) && $login_user->user_type == "staff") {
        echo modal_anchor(get_uri("items/modal_form"), "<i data-feather='edit' class='icon-16'></i> " . app_lang('edit_item'), array("class" => "btn btn-default", "data-post-id" => $model_info->id, "title" => app_lang('edit_item')));
    }
    ?>
    <button type="button" class="btn btn-default" data-bs-dismiss="modal"><span data-feather="x" class="icon-16"></span> <?php echo app_lang('close'); ?></button>
    <?php
    //show add to cart button on client portal
    if (!$model_info->added_to_cart && (!isset($login_user->id) || (isset($login_user->id) && $login_user->user_type == "client"))) {
        echo js_anchor("<i data-feather='shopping-cart' class='icon-16'></i> " . app_lang("add_to_cart"), array("class" => "btn btn-info text-white item-add-to-cart-btn", "data-item_id" => $model_info->id));
    }
    ?>
</div>

<script type="text/javascript">
    $(document).ready(function () {
        $('[data-bs-toggle="tooltip"]').tooltip();
    });
</script>
