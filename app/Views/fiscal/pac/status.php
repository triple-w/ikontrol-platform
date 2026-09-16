<div class="card"><div class="card-header"><h4><?php echo app_lang('stamping_provider'); ?></h4></div><div class="card-body">
<dl class="row"><dt class="col-md-4"><?php echo app_lang('provider'); ?></dt><dd class="col-md-8"><?php echo esc($provider); ?></dd>
<dt class="col-md-4"><?php echo app_lang('environment'); ?></dt><dd class="col-md-8"><span class="badge bg-warning"><?php echo $environment==='sandbox'?app_lang('test_environment'):app_lang('production'); ?></span></dd>
<dt class="col-md-4"><?php echo app_lang('pdf_template'); ?></dt><dd class="col-md-8"><?php echo esc($pdf_template); ?></dd>
<dt class="col-md-4"><?php echo app_lang('technical_status'); ?></dt><dd class="col-md-8"><?php echo $configured?app_lang('pac_configured'):app_lang('pac_not_configured'); ?></dd></dl>
<div class="alert alert-info"><?php echo app_lang('pac_env_only_notice'); ?></div>
</div></div>
