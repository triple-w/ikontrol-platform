<?php echo form_open(get_uri('fiscal/client-profiles/save'), ['id' => 'fiscal-profile-form', 'class' => 'general-form']); ?>
<div class="modal-body">
    <input type="hidden" name="id" value="<?php echo $model_info->id; ?>">
    <input type="hidden" name="client_id" value="<?php echo $client_id; ?>">

    <?php
    $inputs = [
        ['rfc', 'RFC'], ['legal_name', app_lang('legal_name')],
        ['tax_residency_country', app_lang('tax_residency_country')],
        ['foreign_tax_registration', app_lang('foreign_tax_registration')]
    ];
    foreach ($inputs as $field) { ?>
        <div class="form-group row">
            <label class="col-md-3"><?php echo $field[1]; ?></label>
            <div class="col-md-9"><?php echo form_input(['name' => $field[0], 'value' => $model_info->{$field[0]}, 'class' => 'form-control']); ?></div>
        </div>
    <?php } ?>

    <div class="form-group row"><label class="col-md-3"><?php echo app_lang('tax_regime'); ?></label><div class="col-md-9"><?php echo form_dropdown('tax_regime_id', $regimes, $model_info->tax_regime_id, "class='select2 form-control'"); ?></div></div>
    <div class="form-group row"><label class="col-md-3"><?php echo app_lang('default_cfdi_use'); ?></label><div class="col-md-9"><?php echo form_dropdown('default_cfdi_use_id', $uses, $model_info->default_cfdi_use_id, "class='select2 form-control'"); ?></div></div>

    <hr>
    <div class="d-flex justify-content-between align-items-center mb15">
        <h5 class="m0"><?php echo app_lang('fiscal_address'); ?></h5>
        <?php if ($client_info) { ?><button type="button" id="copy-commercial-address" class="btn btn-default btn-sm"><i data-feather="copy" class="icon-14"></i> <?php echo app_lang('copy_commercial_address'); ?></button><?php } ?>
    </div>
    <div class="alert alert-info"><?php echo app_lang('fiscal_address_cfdi_help'); ?></div>

    <?php
    $addressInputs = [
        ['fiscal_country_code', app_lang('country'), (($model_info->id ?? 0) ? ($model_info->fiscal_country_code ?? '') : 'MEX')],
        ['fiscal_postal_code', app_lang('fiscal_postal_code'), $model_info->fiscal_postal_code ?? ''],
        ['fiscal_state', app_lang('state'), $model_info->fiscal_state ?? ''],
        ['fiscal_municipality', app_lang('fiscal_municipality'), $model_info->fiscal_municipality ?? ''],
        ['fiscal_locality', app_lang('fiscal_locality'), $model_info->fiscal_locality ?? ''],
        ['fiscal_neighborhood', app_lang('fiscal_neighborhood'), $model_info->fiscal_neighborhood ?? ''],
        ['fiscal_street', app_lang('street'), $model_info->fiscal_street ?? ''],
        ['fiscal_external_number', app_lang('external_number'), $model_info->fiscal_external_number ?? ''],
        ['fiscal_internal_number', app_lang('internal_number'), $model_info->fiscal_internal_number ?? ''],
        ['fiscal_address_reference', app_lang('references'), $model_info->fiscal_address_reference ?? ''],
    ];
    foreach ($addressInputs as $field) { ?>
        <div class="form-group row">
            <label class="col-md-3"><?php echo $field[1]; ?></label>
            <div class="col-md-9"><?php echo form_input(['name' => $field[0], 'value' => $field[2], 'class' => 'form-control'] + ($field[0] === 'fiscal_country_code' ? ['maxlength' => 3, 'pattern' => '[A-Za-z]{3}'] : [])); ?></div>
        </div>
    <?php } ?>

    <div class="form-group row"><label class="col-md-3"><?php echo app_lang('status'); ?></label><div class="col-md-9"><?php echo form_dropdown('status', ['draft' => app_lang('fiscal_profile_draft'), 'incomplete' => app_lang('fiscal_profile_incomplete'), 'ready' => app_lang('fiscal_profile_ready'), 'inactive' => app_lang('fiscal_profile_inactive')], $model_info->status ?: 'draft', "class='select2 form-control'"); ?></div></div>
    <div class="form-group row"><label class="col-md-3"><?php echo app_lang('default'); ?></label><div class="col-md-9"><?php echo form_checkbox('is_default', '1', (bool) $model_info->is_default, "class='form-check-input'"); ?></div></div>
    <p class="text-muted"><?php echo app_lang('rfc_format_not_sat_validation'); ?></p>
</div>
<div class="modal-footer"><button class="btn btn-default" data-bs-dismiss="modal" type="button"><?php echo app_lang('close'); ?></button><button class="btn btn-primary" type="submit"><?php echo app_lang('save'); ?></button></div>
<?php echo form_close(); ?>

<script>
$(document).ready(function () {
    $('#fiscal-profile-form').appForm({onSuccess: function () { location.reload(); }});
    $('#fiscal-profile-form .select2').select2();
    $('#copy-commercial-address').on('click', function () {
        var commercial = <?php echo json_encode([
            'fiscal_street' => $client_info->address ?? '',
            'fiscal_municipality' => $client_info->city ?? '',
            'fiscal_state' => $client_info->state ?? '',
            'fiscal_postal_code' => $client_info->zip ?? ''
        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
        Object.keys(commercial).forEach(function (field) {
            $('#fiscal-profile-form [name="' + field + '"]').val(commercial[field]);
        });
    });
});
</script>
