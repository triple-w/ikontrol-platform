<?php
$stamped=$note->status==='stamped';
$statusLabel=$stamped?'Timbrada':($note->status==='ready'?'Lista para revisión':'Borrador');
$statusClass=$stamped?'success':($note->status==='ready'?'info':'warning');
$dateNormalizer=new \App\Services\Fiscal\FiscalIssueDateNormalizer();
$dateZone=$dateNormalizer->timezone();
$dateNow=new \DateTimeImmutable('now',$dateZone);
$dateMin=$dateNow->modify('-'.(int)config('Fiscal')->maxIssueAgeHours.' hours')->format('Y-m-d\TH:i:s');
$dateMax=config('Fiscal')->allowFutureIssueDate?'':$dateNow->format('Y-m-d\TH:i:s');
$dateInput=$dateNormalizer->parseCanonical((string)$note->issue_date)->format('Y-m-d\TH:i:s');
?>
<style>
.credit-note-document .document-title{font-size:22px;font-weight:600}.credit-note-document .document-meta{color:#6c757d}.credit-note-document .credit-note-quantity{max-width:115px}.credit-note-document .line-remove{color:#dc3545;padding:4px 7px}.credit-note-document .summary-label{color:#6c757d}.credit-note-document .tax-summary{font-size:12px;color:#59636e}.credit-note-document .details-view-right-section{width:270px}.credit-note-document .document-table th{white-space:nowrap;background:#f7f9fb}.credit-note-document .document-table td{vertical-align:middle}
</style>
<div class="credit-note-document">
 <div class="page-title clearfix mb15"><div class="pull-left"><div class="document-title">Nota de Crédito #<?php echo (int)$note->id; ?> <span class="badge bg-<?php echo $statusClass; ?>"><?php echo esc($statusLabel); ?></span></div><div class="document-meta">CFDI de Egreso · TipoRelación 01</div></div></div>
 <div class="d-flex align-items-start gap-3">
  <div class="flex-grow-1 min-width-0">
   <div class="card mb15"><div class="card-body"><div class="row">
    <div class="col-md-4"><div class="summary-label">Cliente / receptor</div><strong><?php echo esc($note->receiver_name); ?></strong><br>RFC <?php echo esc($note->receiver_rfc); ?></div>
    <div class="col-md-5"><div class="summary-label">Factura relacionada</div><strong><?php echo esc(trim($note->source_series.' '.$note->source_folio)); ?></strong><br><code><?php echo esc($note->source_uuid); ?></code></div>
    <div class="col-md-3"><label class="summary-label" for="credit-note-issue-date">Fecha de emisión</label><input id="credit-note-issue-date" form="credit-note-form" class="form-control" type="datetime-local" step="1" name="issue_date" value="<?php echo esc($dateInput); ?>" min="<?php echo esc($dateMin); ?>" <?php echo $dateMax!==''?'max="'.esc($dateMax).'"':''; ?> <?php echo $stamped?'disabled':''; ?> required><small class="text-muted">Zona fiscal: <?php echo esc($dateZone->getName()); ?> · ventana <?php echo (int)config('Fiscal')->maxIssueAgeHours; ?> horas.</small></div>
   </div></div></div>
   <div class="card mb15"><div class="card-header"><strong>Resumen de la factura</strong></div><div class="card-body"><div class="row text-end">
    <div class="col-6 col-md-3"><div class="summary-label">Total factura</div><strong><?php echo to_currency($balance_summary['source_total']); ?></strong></div>
    <div class="col-6 col-md-3"><div class="summary-label">Pagos aplicados</div><strong><?php echo to_currency($balance_summary['paid']); ?></strong></div>
    <div class="col-6 col-md-3"><div class="summary-label">Notas previas vigentes</div><strong><?php echo to_currency($balance_summary['previous_credits']); ?></strong></div>
    <div class="col-6 col-md-3"><div class="summary-label">Disponible acreditable</div><strong class="text-primary"><?php echo to_currency($available); ?></strong></div>
   </div></div></div>
   <?php echo form_open(get_uri('credit_notes/'.$note->id.'/save'),['id'=>'credit-note-form','class'=>'general-form']); echo csrf_field(); ?>
   <div id="credit-note-save-error" class="alert alert-danger d-none"></div>
   <div class="card"><div class="card-header d-flex justify-content-between align-items-center"><strong>Conceptos acreditados</strong><span class="text-muted">Valor unitario tomado del CFDI original</span></div>
    <div class="table-responsive"><table class="table document-table mb0"><thead><tr><th>Concepto</th><th class="text-end">Cantidad original</th><th>Cantidad a acreditar</th><th class="text-end">Precio</th><th>Impuestos</th><th class="text-end">Total</th><th class="text-center">Acción</th></tr></thead><tbody>
    <?php foreach($items as $item): $taxData=[]; foreach($item->tax_summary as $tax)$taxData[]=['label'=>(($tax['code']??'')==='002'?'IVA':$tax['code']).' '.(($tax['factor']??'')==='Exento'?'Exento':rtrim(rtrim(bcmul((string)$tax['rate'],'100',6),'0'),'.').'%'),'type'=>$tax['type'],'factor'=>$tax['factor'],'source_amount'=>$tax['source_amount']]; ?>
     <tr class="credit-note-line" data-original-quantity="<?php echo esc($item->source_quantity); ?>" data-source-gross="<?php echo esc($item->source_gross_amount); ?>" data-source-discount="<?php echo esc($item->source_discount); ?>" data-taxes='<?php echo esc(json_encode($taxData,JSON_UNESCAPED_UNICODE), 'attr'); ?>'>
      <td><strong><?php echo esc($item->description); ?></strong><br><small class="text-muted"><?php echo esc($item->product_service_code); ?><?php echo $item->identification_number?' · '.esc($item->identification_number):''; ?></small></td>
      <td class="text-end"><?php echo esc(rtrim(rtrim($item->source_quantity,'0'),'.')); ?></td>
      <td><input class="form-control credit-note-quantity" inputmode="decimal" name="quantities[<?php echo (int)$item->id; ?>]" value="<?php echo esc($item->quantity); ?>" min="0.000001" max="<?php echo esc($item->source_quantity); ?>" <?php echo $stamped?'readonly':''; ?> required></td>
      <td class="text-end"><?php echo to_currency($item->unit_value); ?></td>
      <td><div class="tax-summary"><?php if(!$item->tax_summary): ?>Sin impuestos<?php else: foreach($item->tax_summary as $tax): ?><div><?php echo esc(($tax['code']==='002'?'IVA':$tax['code']).' '.($tax['factor']==='Exento'?'Exento':rtrim(rtrim(bcmul((string)$tax['rate'],'100',6),'0'),'.').'%')); ?>: <span class="line-tax-amount"><?php echo to_currency($tax['amount']); ?></span></div><?php endforeach; endif; ?></div></td>
      <td class="text-end"><strong class="line-total"><?php echo to_currency($item->total); ?></strong></td>
      <td class="text-center"><?php if(!$stamped&&$can_edit): ?><button type="button" class="btn btn-link line-remove remove-credit-line" title="Quitar concepto" data-url="<?php echo get_uri('credit_notes/'.$note->id.'/items/'.$item->id.'/remove'); ?>"><i data-feather="trash-2" class="icon-16"></i></button><?php endif; ?></td>
     </tr>
    <?php endforeach; ?>
    <?php if(!$items): ?><tr><td colspan="7" class="text-center text-muted p20">No hay conceptos agregados a la Nota.</td></tr><?php endif; ?>
    </tbody><tfoot><tr><th colspan="5" class="text-end">Total de la Nota</th><th class="text-end"><span id="credit-note-total"><?php echo to_currency($note->total); ?></span></th><th></th></tr></tfoot></table></div>
    <?php if(!$stamped&&$can_edit): ?><div class="card-footer text-end"><button id="save-credit-note" class="btn btn-primary" type="submit"><i data-feather="save" class="icon-16"></i> Guardar cambios</button></div><?php endif; ?>
   </div><?php echo form_close(); ?>
  </div>
  <div class="flex-shrink-0 details-view-right-section"><div class="card position-sticky" style="top:70px"><div class="card-header"><strong>Acciones</strong></div><div class="card-body">
   <?php echo modal_anchor(get_uri('credit_notes/'.$note->id.'/review'),'<i data-feather="check-circle" class="icon-16"></i> Revisión fiscal',['class'=>'btn btn-default w-100 mb10','title'=>'Revisión fiscal','data-modal-lg'=>'1']); ?>
   <?php echo modal_anchor(get_uri('credit_notes/'.$note->id.'/preview'),'<i data-feather="eye" class="icon-16"></i> Vista previa',['class'=>'btn btn-primary w-100 mb10','title'=>'Vista previa','data-modal-lg'=>'1']); ?>
   <?php if(!$stamped&&$can_stamp): ?><button id="stamp-credit-note" class="btn btn-success w-100" <?php echo empty($review['ready'])?'disabled':''; ?>><i data-feather="award" class="icon-16"></i> Timbrar Nota de Crédito</button><?php elseif($stamped&&$note->fiscal_document_id): ?><a class="btn btn-default w-100 mb10" target="_blank" href="<?php echo get_uri('fiscal/documents/'.$note->fiscal_document_id.'/pdf/preview'); ?>">Ver PDF</a><a class="btn btn-default w-100 mb10" href="<?php echo get_uri('fiscal/documents/'.$note->fiscal_document_id.'/pdf/download'); ?>">Descargar PDF</a><a class="btn btn-default w-100 mb10" href="<?php echo get_uri('fiscal/stamping/xml/download/'.$note->fiscal_document_id); ?>">Descargar XML</a><?php if(!empty($can_regenerate_pdf)&&!empty($stamp->uuid)&&!empty($stamp->stamped_xml_artifact_id)){try{$pdfTemplate=(new \App\Services\Fiscal\Pdf\FiscalPdfTemplateResolver())->resolve((int)$note->issuer_profile_id,(string)config('FiscalPdfProvider')->provider,'E')->templateCode;}catch(\Throwable){$pdfTemplate='-';}echo js_anchor('Regenerar PDF',['class'=>'btn btn-warning w-100 mb10 fiscal-regenerate-pdf','data-document-id'=>$note->fiscal_document_id,'data-document-label'=>'Nota #'.$note->id,'data-uuid'=>$stamp->uuid,'data-template'=>$pdfTemplate]);} ?><?php endif; ?>
  </div></div></div>
 </div>
</div>
<script>
$(function(){
 var form=$('#credit-note-form'),save=$('#save-credit-note'),errorBox=$('#credit-note-save-error');
 $('#ajaxModal').off('hide.bs.modal.creditNoteFocus').on('hide.bs.modal.creditNoteFocus',function(){if($.contains(this,document.activeElement)){document.activeElement.blur();}});
 function micros(value){var s=String(value||'0').trim(),negative=s.charAt(0)==='-';if(negative)s=s.slice(1);var p=s.split('.');return(negative?-1n:1n)*BigInt((p[0]||'0')+(p[1]||'').padEnd(6,'0').slice(0,6));}
 function prorate(source,quantity,original){var o=micros(original);if(o===0n)return 0n;return(micros(source)*micros(quantity)+(o/2n))/o;}
 function cents(micro){return micro>=0n?(micro+5000n)/10000n:(micro-5000n)/10000n;}
 function moneyFromCents(value){var neg=value<0n;if(neg)value=-value;var raw=value.toString().padStart(3,'0');return(neg?'-$':'$')+raw.slice(0,-2).replace(/\B(?=(\d{3})+(?!\d))/g,',')+'.'+raw.slice(-2);}
 function recalculate(){var total=0n;$('.credit-note-line').each(function(){var row=$(this),q=row.find('.credit-note-quantity').val(),oq=row.data('original-quantity'),subtotal=cents(prorate(row.data('source-gross'),q,oq)),discount=cents(prorate(row.data('source-discount'),q,oq)),transferred=0n,withheld=0n,taxes=row.data('taxes')||[];row.find('.line-tax-amount').each(function(index){if(!taxes[index])return;var amount=taxes[index].factor==='Exento'?0n:cents(prorate(taxes[index].source_amount,q,oq));$(this).text(moneyFromCents(amount));if(taxes[index].type==='transferred')transferred+=amount;else withheld+=amount;});var lineCents=subtotal-discount+transferred-withheld;row.find('.line-total').text(moneyFromCents(lineCents));total+=lineCents;});$('#credit-note-total').text(moneyFromCents(total));}
 $('.credit-note-quantity').on('input change',recalculate);recalculate();
 form.on('submit',function(e){e.preventDefault();errorBox.addClass('d-none').text('');save.prop('disabled',true);$.ajax({url:form.attr('action'),type:'POST',data:form.serialize(),dataType:'json'}).done(function(result){appAlert.success(result.message);location.reload();}).fail(function(xhr){var result=xhr.responseJSON||{},message=result.message||'No fue posible guardar la Nota de Crédito.';if(result.csrf)form.find('input[name="'+result.csrf.name+'"]').val(result.csrf.hash);errorBox.removeClass('d-none').text(message);appAlert.error(message);}).always(function(){save.prop('disabled',false);});});
 $('.remove-credit-line').on('click',function(){var button=$(this);if(!confirm('¿Quitar este concepto de la Nota de Crédito?'))return;button.prop('disabled',true);$.post(button.data('url'),{'<?php echo csrf_token(); ?>':'<?php echo csrf_hash(); ?>'},function(){location.reload();},'json').fail(function(xhr){appAlert.error((xhr.responseJSON||{}).message||'No fue posible quitar el concepto.');button.prop('disabled',false);});});
 $('#stamp-credit-note').on('click',function(){if(!confirm('¿Desea timbrar esta Nota de Crédito? Después no podrá modificarla.'))return;var button=$(this).prop('disabled',true);$.post('<?php echo get_uri('credit_notes/'.$note->id.'/stamp'); ?>',{'<?php echo csrf_token(); ?>':'<?php echo csrf_hash(); ?>'}).done(function(result){appAlert.success(result.message);location.reload();}).fail(function(xhr){appAlert.error((xhr.responseJSON||{}).message||'No fue posible timbrar.');button.prop('disabled',false);});});
});
</script>
<?php if(!empty($can_regenerate_pdf)){echo view('fiscal/pdf_regeneration_modal',['configure_pdf_allowed'=>$configure_pdf_allowed??false]);} ?>
