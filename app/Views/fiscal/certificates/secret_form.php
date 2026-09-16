<?php echo form_open(get_uri('fiscal/certificates/secret/configure'), [
    'id' => 'csd-secret-form',
    'class' => 'general-form',
]); ?>
<?php echo csrf_field(); ?>
<div class="modal-body clearfix">
    <input type="hidden" name="certificate_id" value="<?php echo (int) $certificate->id; ?>">
    <div class="form-group row">
        <label class="col-md-4"><?php echo app_lang('certificate_number'); ?></label>
        <div class="col-md-8"><p class="form-control-static"><?php
            echo htmlspecialchars($certificate->certificate_number);
        ?></p></div>
    </div>
    <div class="form-group row">
        <label class="col-md-4"><?php echo app_lang('csd_operational_status'); ?></label>
        <div class="col-md-8"><p class="form-control-static"><?php
            echo htmlspecialchars($csd_status['label']);
        ?></p></div>
    </div>
    <div class="form-group row">
        <label class="col-md-4"><?php echo app_lang('private_key_password'); ?></label>
        <div class="col-md-8">
            <input type="password" name="private_key_password" autocomplete="new-password"
                   class="form-control" required>
            <small><?php echo app_lang('csd_password_vault_notice'); ?></small>
        </div>
    </div>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-default" data-bs-dismiss="modal">
        <?php echo app_lang('close'); ?>
    </button>
    <button type="submit" class="btn btn-primary">
        <?php echo app_lang($csd_status['ready'] ? 'update_csd_password' : 'configure_csd_password'); ?>
    </button>
</div>
<?php echo form_close(); ?>
<script>
$(document).ready(function () {
    var form = $('#csd-secret-form');
    var password = form.find('[name="private_key_password"]');

    form.on('submit', function (event) {
        event.preventDefault();

        if (!password.val()) {
            password.trigger('focus');
            return false;
        }

        var submit = form.find('[type="submit"]');
        submit.prop('disabled', true);

        $.ajax({
            url: form.attr('action'),
            type: 'POST',
            data: new FormData(form[0]),
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function (result) {
                if (result.csrf && result.csrf.name && result.csrf.hash) {
                    form.find('input[name="' + result.csrf.name + '"]').val(result.csrf.hash);
                }

                password.val('');

                if (result.success) {
                    appAlert.success(result.message);
                    closeAjaxModal(true);
                    location.reload();
                    return;
                }

                submit.prop('disabled', false);
                appAlert.error(result.message, {container: '.modal-body', animate: false});
                password.trigger('focus');
            },
            error: function (xhr) {
                password.val('');
                submit.prop('disabled', false);

                if (xhr.status === 403) {
                    closeAjaxModal(true);
                    appAlert.error('<?php echo addslashes(app_lang('csrf_session_expired')); ?>');
                    return;
                }

                appAlert.error('<?php echo addslashes(app_lang('something_went_wrong')); ?>', {
                    container: '.modal-body',
                    animate: false
                });
            }
        });

        return false;
    });

    $('#ajaxModal').one('hidden.bs.modal', function () {
        password.val('');
    });
});
</script>
