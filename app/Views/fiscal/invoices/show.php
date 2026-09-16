<?php
$tax = (new \App\Services\Fiscal\FiscalDecimalCalculator())->sub((string)$document->transferred_tax_total,(string)$document->withheld_tax_total,6);
$statusLabel = str_replace('_',' ',(string)$document->visible_status);
$pdfProviderLabel = static fn(string $provider): string => $provider === 'timbradorxpress-tools' ? 'WSTools33 / PAC' : 'Prueba local';
$artifactLabel = static function(object $artifact): string {
    if (($artifact->artifact_type ?? '') !== 'pac_pdf') return (string) ($artifact->artifact_type ?? '');
    return ($artifact->provider ?? '') === 'timbradorxpress-tools' ? 'PDF del PAC' : 'PDF de prueba';
};
$pdfTemplate='-';
if(!empty($permissions['pdf_regenerate'])){try{$pdfTemplate=(new \App\Services\Fiscal\Pdf\FiscalPdfTemplateResolver())->resolve((int)$document->issuer_profile_id,(string)config('FiscalPdfProvider')->provider,match(strtolower((string)$document->document_type)){'income','i'=>'I','expense','e'=>'E','payment','p'=>'P',default=>strtoupper(substr((string)$document->document_type,0,1))})->templateCode;}catch(\Throwable){}}
?>
<?php if(($document->environment??'')==='development'){ ?><div class="alert alert-warning"><strong>CFDI de prueba</strong> — Ambiente PAC de desarrollo.</div><?php } ?>
<div class="card">
    <div class="page-title clearfix">
        <h4>Factura fiscal <?php echo esc(trim($document->series.' '.$document->folio)); ?></h4>
        <div class="title-button-group"><a class="btn btn-default" href="<?php echo get_uri('fiscal/invoices'); ?>">Volver a Facturas</a></div>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-6"><h5>Emisor</h5><dl><dt>Razón social</dt><dd><?php echo esc($issuer->legal_name??'-'); ?></dd><dt>RFC</dt><dd><?php echo esc($issuer->rfc??'-'); ?></dd><dt>Régimen</dt><dd><?php echo esc($issuer->tax_regime_code??'-'); ?></dd></dl></div>
            <div class="col-md-6"><h5>Receptor</h5><dl><dt>Razón social</dt><dd><?php echo esc($receiver->legal_name??'-'); ?></dd><dt>RFC</dt><dd><?php echo esc($receiver->rfc??'-'); ?></dd><dt>Uso CFDI</dt><dd><?php echo esc($receiver->cfdi_use_code??'-'); ?></dd></dl></div>
        </div>
        <hr>
        <div class="row">
            <div class="col-md-4"><dl><dt>Serie / folio</dt><dd><?php echo esc($document->series.' / '.$document->folio); ?></dd><dt>Fecha</dt><dd><?php echo esc($document->issue_date); ?></dd><dt>Tipo</dt><dd><?php echo esc($document->document_type); ?></dd></dl></div>
            <div class="col-md-4"><dl><dt>UUID</dt><dd><code><?php echo esc($document->uuid?:'-'); ?></code></dd><dt>Estado fiscal</dt><dd><?php echo esc(ucfirst($statusLabel)); ?></dd><?php if($permissions['advanced_view']){ ?><dt>Estado PDF</dt><dd><?php echo esc($document->pdf_status?:'pending'); ?></dd><?php } ?><dt>Cancelación</dt><dd><?php echo esc($document->cancellation_status); ?></dd></dl></div>
            <div class="col-md-4"><dl><dt>Subtotal</dt><dd><?php echo to_currency($document->subtotal); ?></dd><dt>Impuestos</dt><dd><?php echo to_currency($tax); ?></dd><dt>Total</dt><dd><?php echo to_currency($document->total); ?></dd></dl></div>
        </div>
        <div class="btn-group mb20">
            <?php if($document->xml_available&&$permissions['xml_download']){?><a class="btn btn-default" href="<?php echo get_uri('fiscal/stamping/xml/download/'.$document->id); ?>">Descargar XML</a><?php }?>
            <?php if($document->pdf_available&&$permissions['pdf_view']){?><a class="btn btn-default" target="_blank" href="<?php echo get_uri('fiscal/documents/'.$document->id.'/pdf/preview'); ?>">Ver PDF</a><?php }?>
            <?php if($document->pdf_available&&$permissions['pdf_download']){?><a class="btn btn-default" href="<?php echo get_uri('fiscal/documents/'.$document->id.'/pdf/download'); ?>">Descargar PDF</a><?php }?>
            <?php if($document->xml_available&&!empty($document->uuid)&&!empty($permissions['pdf_regenerate'])){echo js_anchor('Regenerar PDF',['class'=>'btn btn-warning fiscal-regenerate-pdf','data-document-id'=>$document->id,'data-document-label'=>trim($document->series.' '.$document->folio),'data-uuid'=>$document->uuid,'data-template'=>$pdfTemplate]);} ?>
            <?php if($permissions['cancel']&&!empty($document->uuid)&&in_array($document->visible_status,['stamped','stamped_pdf_pending','stamped_pdf_error'],true)&&in_array($document->cancellation_status,['none','rejected'],true)){echo modal_anchor(get_uri('fiscal/invoices/cancel/form'),'Cancelar',['class'=>'btn btn-danger','data-post-document_id'=>$document->id]);} ?>
            <?php if($permissions['status_query']&&$document->cancellation_request_id&&$document->cancellation_status!=='cancelled'){echo modal_anchor(get_uri('fiscal/invoices/cancellation/status/form'),'Consultar estatus',['class'=>'btn btn-default','data-post-document_id'=>$document->id]);} ?>
            <?php if($permissions['receipt_view']&&$document->cancellation_ack_available){?><a class="btn btn-default" href="<?php echo get_uri('fiscal/invoices/cancellation/ack/'.$document->cancellation_request_id); ?>">Ver acuse</a><?php } ?>
        </div>
        <?php if($cancellation){$reasons=['01'=>'Comprobante emitido con errores con relación','02'=>'Comprobante emitido con errores sin relación','03'=>'No se llevó a cabo la operación','04'=>'Operación nominativa relacionada en una factura global'];$requestCost=$queryCost=0;$lastQuery=null;foreach($cancellation_movements as$m){if($m->movement_type==='cancellation_request')$requestCost=(int)$m->operation_count;if($m->movement_type==='cancellation_status_query'){$queryCost=(int)$m->operation_count;$lastQuery=$m->last_at;}}?><h5>Cancelación</h5><dl><dt>Motivo</dt><dd><?php echo esc($cancellation->cancellation_reason.' · '.($reasons[$cancellation->cancellation_reason]??'')); ?></dd><dt>Fecha de solicitud</dt><dd><?php echo esc($cancellation->requested_at); ?></dd><dt>Estado</dt><dd><?php echo esc(ucfirst(str_replace('_',' ',$document->cancellation_status))); ?></dd><dt>Respuesta</dt><dd><?php echo esc($cancellation->provider_message?:'Pendiente de respuesta'); ?></dd><dt>Última consulta</dt><dd><?php echo esc($lastQuery?:'Sin consultas comerciales'); ?></dd><dt>Consultas realizadas</dt><dd><?php echo$queryCost; ?></dd><dt>Timbres utilizados</dt><dd>Solicitud: <?php echo$requestCost; ?> · Consultas: <?php echo$queryCost; ?></dd><dt>Acuse disponible</dt><dd><?php echo $document->cancellation_ack_available?'Sí':'No'; ?></dd><?php if($cancellation->replacement_uuid){?><dt>UUID sustituto</dt><dd><code><?php echo esc($cancellation->replacement_uuid); ?></code></dd><?php }?></dl><?php } ?>
        <h5>Ventas relacionadas</h5>
        <?php if($sales){foreach($sales as$sale){ ?><a class="btn btn-default btn-sm mb10" href="<?php echo get_uri('invoices/view/'.$sale->sale_id); ?>"><?php echo esc($sale->display_id?:'#'.$sale->sale_id); ?> · <?php echo to_currency($sale->allocated_total); ?></a> <?php }}else{ ?><p class="text-muted">Documento histórico sin venta relacionada.</p><?php } ?>
        <?php if($permissions['advanced_view']){ ?>
        <h5>Herramientas fiscales avanzadas</h5>
        <h5>Intentos de timbrado</h5>
        <div class="table-responsive"><table class="table table-striped"><thead><tr><th>ID</th><th>Estado</th><th>Proveedor</th><th>Inicio</th><th>Fin</th></tr></thead><tbody><?php foreach($stamp_attempts as $a){?><tr><td><?php echo(int)$a->id;?></td><td><?php echo esc($a->status);?></td><td><?php echo esc($a->provider);?></td><td><?php echo esc($a->started_at?:$a->created_at);?></td><td><?php echo esc($a->completed_at?:'-');?></td></tr><?php }if(!$stamp_attempts){?><tr><td colspan="5">Sin intentos.</td></tr><?php }?></tbody></table></div>
        <h5>Intentos PDF</h5>
        <div class="table-responsive"><table class="table table-striped"><thead><tr><th>ID</th><th>Estado</th><th>Proveedor</th><th>Plantilla</th><th>Inicio</th><th>Fin</th></tr></thead><tbody><?php foreach($pdf_attempts as $a){?><tr><td><?php echo(int)$a->id;?></td><td><?php echo esc($a->status);?></td><td><?php echo esc($pdfProviderLabel((string)$a->provider));?></td><td><?php echo esc($a->template_code);?></td><td><?php echo esc($a->started_at?:$a->created_at);?></td><td><?php echo esc($a->completed_at?:'-');?></td></tr><?php }if(!$pdf_attempts){?><tr><td colspan="6">Sin intentos.</td></tr><?php }?></tbody></table></div>
        <h5>Artefactos disponibles</h5>
        <div class="table-responsive"><table class="table table-striped"><thead><tr><th>ID</th><th>Tipo</th><th>Estado</th><th>Tamaño</th><th>SHA-256</th><th>Fecha</th></tr></thead><tbody><?php foreach($artifacts as $a){?><tr><td><?php echo(int)$a->id;?></td><td><?php echo esc($artifactLabel($a));?></td><td><?php echo esc($a->validation_status);?></td><td><?php echo(int)($a->byte_size??$a->decoded_size_bytes??0);?> bytes</td><td><code><?php echo esc($a->sha256??$a->decoded_sha256??'-');?></code></td><td><?php echo esc($a->created_at);?></td></tr><?php }?></tbody></table></div>
        <?php } ?>
        <details><summary>XML timbrado</summary><p class="text-muted mt10">El XML completo no se carga en esta vista. Use la descarga protegida si cuenta con permiso.</p></details>
    </div>
</div>

<?php if(!empty($permissions['pdf_regenerate'])){ echo view('fiscal/pdf_regeneration_modal',['configure_pdf_allowed'=>(bool)($permissions['pdf_templates_manage']??false)]); } ?>
