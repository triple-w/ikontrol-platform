<div id="page-content" class="page-wrapper clearfix">
    <div class="row">
        <div class="col-sm-3 col-lg-2">
            <?php
            $tab_view['active_tab'] = "taxes";
            echo view("settings/tabs", $tab_view);
            ?>
        </div>

        <div class="col-sm-9 col-lg-10">
            <div class="card">
                <div class="page-title clearfix">
                    <h4> <?php echo app_lang('taxes'); ?></h4>
                    <div class="title-button-group">
                        <?php echo modal_anchor(get_uri("taxes/modal_form"), "<i data-feather='plus-circle' class='icon-16'></i> " . app_lang('add_tax'), array("class" => "btn btn-default", "title" => app_lang('add_tax'))); ?>
                    </div>
                </div>
                <div class="alert alert-warning m15 mb0" role="note">
                    <i data-feather="alert-triangle" class="icon-16"></i>
                    <?php echo app_lang('administrative_taxes_notice'); ?>
                </div>
                <div class="table-responsive">
                    <table id="taxes-table" class="display" cellspacing="0" width="100%">            
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<script type="text/javascript">
    $(document).ready(function () {
        $("#taxes-table").appTable({
            source: '<?php echo_uri("taxes/list_data") ?>',
            columns: [
                {title: '<?php echo app_lang("tax_title"); ?>'},
                {title: '<?php echo app_lang("percentage"); ?>'},
                <?php if ($can_view_fiscal_tax_settings) { ?>{title: '<?php echo app_lang("sat_tax_code"); ?>'},
                {title: '<?php echo app_lang("fiscal_tax_type"); ?>'},
                {title: '<?php echo app_lang("factor_type"); ?>'},
                {title: '<?php echo app_lang("xml_rate_or_quota"); ?>'},
                {title: '<?php echo app_lang("administrative"); ?>'},
                {title: '<?php echo app_lang("fiscally_ready"); ?>'},<?php } ?>
                {visible: false, searchable: false},
                {title: '<i data-feather="menu" class="icon-16"></i>', "class": "text-center option w100"}
            ]
        });
    });
</script>
