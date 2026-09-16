<div class="modal-body">
    <div class="alert alert-success"><?php echo app_lang('local_signature_correct'); ?></div>
    <div class="alert alert-warning"><?php echo app_lang('signed_xml_not_stamped'); ?></div>
    <dl>
        <dt>SHA-256</dt><dd><code><?php echo htmlspecialchars($artifact->sha256); ?></code></dd>
        <dt><?php echo app_lang('structural_validation'); ?></dt><dd><?php echo app_lang('prexml_status_' . $artifact->validation_status); ?></dd>
    </dl>
    <pre class="bg-light p15" style="max-height:500px;overflow:auto;white-space:pre-wrap"><?php echo htmlspecialchars($xml, ENT_QUOTES, 'UTF-8'); ?></pre>
</div>
<div class="modal-footer">
    <a class="btn btn-default" href="<?php echo url_to('fiscal_signed_xml_download', $artifact->fiscal_document_id); ?>"><?php echo app_lang('download'); ?></a>
    <button type="button" class="btn btn-default" data-bs-dismiss="modal"><?php echo app_lang('close'); ?></button>
</div>
