<?php
$technicalUnknown=false;foreach($summary['active_documents']as$document){if(in_array($document['document_status'],['stamp_status_unknown','unknown','stamping'],true)){$technicalUnknown=true;break;}}
$visibleStatus='Sin facturar';
if($technicalUnknown)$visibleStatus='Estamos verificando el resultado. No vuelva a facturar.';
elseif($summary['active_documents'])$visibleStatus='Facturada';
elseif($summary['active_drafts'])$visibleStatus=($review_status??null)==='ready'?'Lista para facturar':'Revisión necesaria';
$advanced=!empty($permissions['advanced']);
?>
<div class="card mb15">
    <div class="card-header fw-bold">Resumen fiscal de la venta</div>
    <div class="card-body">
        <div class="row"><div class="col-md-4"><small>Total venta</small><div><?php echo to_currency($summary['sale_total']); ?></div></div><div class="col-md-8"><small>Estado fiscal</small><div><span class="badge bg-info"><?php echo esc($visibleStatus); ?></span></div></div></div>
        <div class="mt15">
            <?php if ($permissions['create_draft']) { ?>
                <?php if(!$summary['active_documents']&&!$technicalUnknown){ echo modal_anchor(get_uri('fiscal/drafts/create/'.$invoice_id),'Facturar',['class'=>'btn btn-primary btn-sm','title'=>'Revisión fiscal','data-modal-lg'=>'1','data-action-method'=>'GET']); } ?>
            <?php } ?>
            <?php if ($permissions['view_draft'] && $summary['active_drafts']) { ?>
                <?php foreach($summary['active_drafts']as$draft){ ?><a class="btn btn-default btn-sm" target="_blank" href="<?php echo get_uri('fiscal/drafts/'.$draft['fiscal_draft_id'].'/preinvoice'); ?>">Vista previa</a><?php if($advanced){?> <a class="btn btn-default btn-sm" href="<?php echo get_uri('fiscal/drafts/'.$draft['fiscal_draft_id']); ?>">Herramientas avanzadas</a><?php }} ?>
            <?php } ?>
            <?php if ($permissions['view_invoice']) {
                foreach ($summary['active_documents'] as $document) { ?>
                    <a class="btn btn-default btn-sm" href="<?php echo get_uri('fiscal/invoices/'.$document['fiscal_document_id']); ?>">Ver factura</a>
                <?php }
                if ($summary['active_documents'] || $summary['cancelled_documents']) { ?>
                    <a class="btn btn-default btn-sm" href="<?php echo get_uri('fiscal/invoices'); ?>">Ver documentos relacionados</a>
                <?php }
            } ?>
        </div>
        <?php if($advanced){?><details class="mt15"><summary>Datos técnicos</summary><div class="row mt10"><div class="col-md-3">Facturado: <?php echo to_currency($summary['active_invoiced_total']);?></div><div class="col-md-3">Reservado: <?php echo to_currency($summary['draft_reserved_total']);?></div><div class="col-md-3">Disponible: <?php echo to_currency($summary['available_to_invoice']);?></div><div class="col-md-3">Estado interno: <?php echo esc($summary['fiscal_status']);?></div></div></details><?php }?>
    </div>
</div>
