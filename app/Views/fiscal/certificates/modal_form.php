<?php echo form_open_multipart(get_uri('fiscal/certificates/upload'), ['id' => 'csd-upload-form', 'class' => 'general-form']); ?>
<div class="modal-body">
    <input type="hidden" name="issuer_profile_id" value="<?php echo (int) $issuer->id; ?>">
    <div class="alert alert-info"><?php echo app_lang('csd_upload_help'); ?></div>
    <div class="form-group row"><label class="col-md-4"><?php echo app_lang('certificate_file'); ?> (.cer)</label><div class="col-md-8"><input type="file" name="certificate_file" accept=".cer,.pem" class="form-control" required></div></div>
    <div class="form-group row"><label class="col-md-4"><?php echo app_lang('private_key_file'); ?> (.key)</label><div class="col-md-8"><input type="file" name="private_key_file" accept=".key,.pem" class="form-control" required></div></div>
    <div class="form-group row"><label class="col-md-4"><?php echo app_lang('private_key_password'); ?></label><div class="col-md-8"><input type="password" name="private_key_password" autocomplete="new-password" class="form-control" required><small><?php echo app_lang('csd_password_vault_notice'); ?></small></div></div>
    <div class="form-group row"><label class="col-md-9"><?php echo app_lang('default'); ?></label><div class="col-md-3"><?php echo form_checkbox('is_default', '1', false, "class='form-check-input'"); ?></div></div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-default" data-bs-dismiss="modal"><?php echo app_lang('close'); ?></button><button type="submit" class="btn btn-primary"><?php echo app_lang('upload_csd'); ?></button></div>
<?php echo form_close(); ?>
<script>$(document).ready(function(){$('#csd-upload-form').appForm({onSuccess:function(){location.reload();}});});</script>
