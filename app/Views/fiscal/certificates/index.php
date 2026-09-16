<div class="card">
    <div class="page-title clearfix">
        <h4><?php echo app_lang('digital_seal_certificates'); ?> · <?php echo htmlspecialchars($issuer->legal_name); ?></h4>
        <?php if ($can_manage) {
            echo modal_anchor(get_uri('fiscal/certificates/form'), '<i data-feather="plus-circle" class="icon-16"></i> ' . app_lang('upload_csd'), [
                'class' => 'btn btn-default', 'title' => app_lang('upload_csd'),
                'data-post-issuer_profile_id' => $issuer->id,
            ]);
        } ?>
    </div>
    <div class="card-body">
        <div class="alert alert-warning"><?php echo app_lang('csd_password_vault_notice'); ?></div>
        <div class="alert alert-info"><?php echo app_lang('csd_local_validity_notice'); ?></div>
        <div class="table-responsive"><table id="csd-table" class="display" width="100%"></table></div>
    </div>
</div>
<script>
$(document).ready(function(){
    $('#csd-table').appTable({
        source:'<?php echo_uri('fiscal/certificates/list/' . $issuer->id); ?>',
        columns:[
            {title:'<?php echo app_lang('certificate_number'); ?>'},
            {title:'RFC'},
            {title:'<?php echo app_lang('valid_from'); ?>'},
            {title:'<?php echo app_lang('valid_to'); ?>'},
            {title:'<?php echo app_lang('status'); ?>'},
            {title:'<?php echo app_lang('csd_operational_status'); ?>'},
            {title:'<?php echo app_lang('default'); ?>'},
            {title:'<i data-feather="menu" class="icon-16"></i>',class:'text-center option w100'}
        ]
    });
});
</script>
