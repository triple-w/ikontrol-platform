<div class="card">
    <div class="page-title clearfix">
        <h4>Facturación · Facturas</h4>
        <div class="title-button-group">
            <?php if(!empty($can_view_stamp_balance)){ ?><a class="btn btn-default" href="<?php echo get_uri('fiscal/stamps/balance'); ?>">Timbres disponibles: <?php echo (int)($stamp_balance['usable'] ?? 0); ?></a><?php } ?>
            <a class="btn btn-default" href="<?php echo get_uri('fiscal/pdf-templates'); ?>">
                <i data-feather="layout" class="icon-16"></i> Plantillas PDF
            </a>
        </div>
    </div>
    <div class="card-body">
        <details class="mb20" open>
            <summary class="strong">Filtros</summary>
            <div class="row mt15">
                <?php foreach ([
                    'search'=>'Buscar','series'=>'Serie','folio'=>'Folio','uuid'=>'UUID',
                    'client'=>'Cliente','rfc'=>'RFC','date_from'=>'Fecha desde','date_to'=>'Fecha hasta'
                ] as $id=>$label) { ?>
                    <div class="col-md-3 mb10">
                        <label for="fi-<?php echo $id; ?>"><?php echo $label; ?></label>
                        <input id="fi-<?php echo $id; ?>" class="form-control" <?php echo str_starts_with($id,'date_')?'type="date"':''; ?>>
                        <?php if(str_starts_with($id,'date_')){?><small class="text-muted">Formato visual: dd/mm/aaaa</small><?php }?>
                    </div>
                <?php } ?>
                <div class="col-md-3 mb10"><label>Tipo CFDI</label><?php echo form_dropdown('type',[''=>'Todos','I'=>'Ingreso','E'=>'Egreso','P'=>'Pago','T'=>'Traslado','N'=>'Nómina'],'',"id='fi-type' class='form-control'"); ?></div>
                <div class="col-md-3 mb10"><label>Estado fiscal</label><?php echo form_dropdown('status',[''=>'Todos','draft'=>'Borrador','ready_to_stamp'=>'Listo para timbrar','stamping'=>'Enviando','stamped'=>'Timbrado','stamping_error'=>'Error','stamp_status_unknown'=>'Resultado desconocido','cancelled'=>'Cancelado'],'',"id='fi-status' class='form-control'"); ?></div>
                <div class="col-md-3 mb10"><label>Estado PDF</label><?php echo form_dropdown('pdf_status',[''=>'Todos','pending'=>'Pendiente','processing'=>'Procesando','valid'=>'Disponible','error'=>'Error','unknown'=>'Desconocido'],'',"id='fi-pdf-status' class='form-control'"); ?></div>
                <div class="col-md-3 mb10"><label>Cancelación</label><?php echo form_dropdown('cancellation_status',[''=>'Todos','none'=>'No solicitada','requested'=>'Pendiente','pending'=>'Pendiente','accepted'=>'Cancelada','rejected'=>'Rechazada','unknown'=>'Verificando'],'',"id='fi-cancellation-status' class='form-control'"); ?></div>
                <div class="col-md-3 mb10 d-flex align-items-end"><button type="button" id="fi-clear" class="btn btn-default w-100">Limpiar filtros</button></div>
            </div>
        </details>
        <div class="table-responsive"><table id="fiscal-invoices-table" class="display" width="100%"></table></div>
    </div>
</div>
<?php if($advanced_view){ ?>
<form id="fiscal-pdf-csrf"><?php echo csrf_field(); ?></form>
<div class="modal fade" id="fiscal-pdf-confirm" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title" id="pdf-confirm-title">Generar PDF</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><dl>
            <dt>Serie / folio</dt><dd id="pdf-confirm-document"></dd>
            <dt>UUID</dt><dd><code id="pdf-confirm-uuid"></code></dd>
            <dt>Proveedor efectivo</dt><dd id="pdf-confirm-provider"></dd>
            <dt>Plantilla</dt><dd id="pdf-confirm-template"></dd>
        </dl><div id="pdf-confirm-progress" class="text-info"></div></div>
        <div class="modal-footer"><button type="button" class="btn btn-default" data-bs-dismiss="modal">Cerrar</button><button type="button" id="pdf-confirm-submit" class="btn btn-primary">Generar PDF</button></div>
    </div></div>
</div>
<?php } ?>
<script>
$(document).ready(function(){
    <?php if(!$advanced_view){ ?>$('#fi-type,#fi-status,#fi-pdf-status,#fi-cancellation-status').closest('.col-md-3').remove();<?php } ?>
    var filterSelectors={search:'#fi-search',series:'#fi-series',folio:'#fi-folio',uuid:'#fi-uuid',client:'#fi-client',rfc:'#fi-rfc',date_from:'#fi-date_from',date_to:'#fi-date_to',type:'#fi-type',status:'#fi-status',pdf_status:'#fi-pdf-status',cancellation_status:'#fi-cancellation-status'};
    function currentFilters(){
        var values={};
        $.each(filterSelectors,function(name,selector){values[name]=$(selector).val()||'';});
        return values;
    }
    function reloadInvoices(){
        if(window.InstanceCollection&&window.InstanceCollection['fiscal-invoices-table']){
            window.InstanceCollection['fiscal-invoices-table'].filterParams=currentFilters();
        }
        $('#fiscal-invoices-table').appTable({reload:true});
    }
    var table=$('#fiscal-invoices-table').appTable({
        source:'<?php echo_uri('fiscal/invoices/list'); ?>',
        filterParams:currentFilters(),
        searching:false,
        stateSave:false,
        language:{emptyTable:'No se encontraron facturas con los filtros seleccionados.'},
        columns:<?php if($advanced_view){ ?>[
            {title:'Serie'},{title:'Folio'},{title:'Tipo CFDI'},{title:'Fecha'},
            {title:'Cliente'},{title:'RFC receptor'},{title:'Total'},{title:'UUID'},
            {title:'Estado fiscal'},{title:'Estado PDF'},
            {title:'Cancelación'},{title:'<i data-feather="menu" class="icon-16"></i>',class:'text-center option'}
        ]<?php }else{ ?>[
            {title:'Serie / folio'},{title:'Fecha'},{title:'Cliente'},{title:'Ventas relacionadas'},
            {title:'UUID'},{title:'Total'},{title:'Estado'},{title:'Acciones',class:'text-center option'}
        ]<?php } ?>
    });
    $('#fi-search,#fi-series,#fi-folio,#fi-uuid,#fi-client,#fi-rfc').on('keyup',reloadInvoices);
    $('#fi-date_from,#fi-date_to,#fi-type,#fi-status,#fi-pdf-status,#fi-cancellation-status').on('change',reloadInvoices);
    $('#fi-clear').on('click',function(){$.each(filterSelectors,function(k,selector){$(selector).val('').trigger('change.select2');});reloadInvoices();});
    <?php if($advanced_view){ ?>
    var pendingButton=null,confirmModal=new bootstrap.Modal(document.getElementById('fiscal-pdf-confirm'));
    $(document).on('click','.fiscal-generate-pdf',function(){
        pendingButton=$(this);if(pendingButton.data('busy'))return false;
        var uuid=String(pendingButton.data('uuid')||''),shortUuid=uuid?uuid.substring(0,8)+'…'+uuid.slice(-4):'-';
        $('#pdf-confirm-title').text(pendingButton.data('action-label'));
        $('#pdf-confirm-document').text(pendingButton.data('series')+' '+pendingButton.data('folio'));
        $('#pdf-confirm-provider').text(pendingButton.data('provider-label'));
        $('#pdf-confirm-uuid').text(shortUuid);$('#pdf-confirm-template').text(pendingButton.data('template'));
        $('#pdf-confirm-progress').text('');$('#pdf-confirm-submit').prop('disabled',false).text(pendingButton.data('action-label'));
        confirmModal.show();return false;
    });
    $('#pdf-confirm-submit').on('click',function(){
        var button=pendingButton;if(!button||button.data('busy'))return false;
        var token=$('#fiscal-pdf-csrf input'),data={regenerate:button.data('regenerate')?1:0}; data[token.attr('name')]=token.val();
        button.data('busy',true).addClass('disabled');$('#pdf-confirm-submit').prop('disabled',true);$('#pdf-confirm-progress').text('Generando PDF…');
        var pdfAction=button.data('regenerate')?'regenerate':'generate';
        $.post('<?php echo get_uri('fiscal/documents'); ?>/'+button.data('document-id')+'/pdf/'+pdfAction,data,function(result){
            if(result.csrf)token.attr('name',result.csrf.name).val(result.csrf.hash);
            if(result.success){appAlert.success(result.message||'PDF generado correctamente.');confirmModal.hide();$('#fiscal-invoices-table').appTable({reload:true});}
            else{appAlert.error(result.message||'No fue posible generar el PDF.');$('#pdf-confirm-progress').text(result.message||'No fue posible generar el PDF.');}
        },'json').fail(function(){appAlert.error('No fue posible generar el PDF.');})
          .always(function(){button.data('busy',false).removeClass('disabled');$('#pdf-confirm-submit').prop('disabled',false).text('Generar PDF');});
        return false;
    });
    <?php } ?>
});
</script>

<?php if(!empty($can_regenerate_pdf)){ echo view('fiscal/pdf_regeneration_modal', ['configure_pdf_allowed'=>(bool)($configure_pdf_allowed??false)]); } ?>
