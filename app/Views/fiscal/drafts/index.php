<div class="page-title clearfix">
    <h1>Facturación · Borradores</h1>
    <div class="title-button-group"><span class="text-muted">Los borradores no tienen validez fiscal.</span></div>
</div>
<div class="card">
    <div class="card-body">
        <div class="row g-2 mb15" id="draft-filters">
            <div class="col-md-2"><label>Cliente</label><input class="form-control" id="draft-client"></div>
            <div class="col-md-1"><label>Venta</label><input class="form-control" id="draft-sale"></div>
            <div class="col-md-2"><label>Estado</label><?php echo form_dropdown('', $statuses, '', "id='draft-status' class='form-control'"); ?></div>
            <div class="col-md-2"><label>Creado desde</label><input type="date" class="form-control" id="draft-created"></div>
            <div class="col-md-2"><label>Expedición desde</label><input type="date" class="form-control" id="draft-issue"></div>
            <div class="col-md-2"><label>Importe exacto</label><input class="form-control" id="draft-amount" inputmode="decimal"></div>
            <div class="col-md-1"><label>Usuario ID</label><input class="form-control" id="draft-user" inputmode="numeric"></div>
            <div class="col-md-1 d-flex align-items-end"><button class="btn btn-default" id="draft-filter">Filtrar</button></div>
        </div>
        <div class="table-responsive"><table id="fiscal-drafts-table" class="display" width="100%"></table></div>
    </div>
</div>
<script>
$(document).ready(function(){
    var table=$("#fiscal-drafts-table").appTable({
        source:"<?php echo get_uri('fiscal/drafts/list'); ?>",
        serverSide:false,
        filterParams:{
            client:function(){return $("#draft-client").val();},sale_id:function(){return $("#draft-sale").val();},
            status:function(){return $("#draft-status").val();},created_from:function(){return $("#draft-created").val();},
            issue_from:function(){return $("#draft-issue").val();},amount:function(){return $("#draft-amount").val();},
            user_id:function(){return $("#draft-user").val();}
        },
        columns:[
            {title:"Folio interno"},{title:"Creación"},{title:"Expedición"},{title:"Cliente"},
            {title:"Ventas"},{title:"Total",class:"text-end"},{title:"Estado"},{title:"Creado por"},
            {title:"Actualizado"},{title:"Acciones",class:"text-center option w150"}
        ]
    });
    $("#draft-filter").on("click",function(){table.reload();});
    $(document).on("click",".discard-draft",function(){
        if(!confirm("¿Descartar este borrador y liberar sus reservas?"))return;
        $.post("<?php echo get_uri('fiscal/drafts'); ?>/"+$(this).data("id")+"/discard",{"<?php echo csrf_token(); ?>":"<?php echo csrf_hash(); ?>"},function(result){
            if(result.success){appAlert.success(result.message);table.reload();}else appAlert.error(result.message);
        },"json");
    });
});
</script>
