<div class="modal-body">
 <div class="alert alert-warning"><b>No es posible generar la vista previa todavía.</b></div>
 <p>Corrija los siguientes bloqueantes fiscales:</p>
 <ul><?php foreach($blockers as$blocker): ?><li><?php echo esc($blocker); ?></li><?php endforeach; ?></ul>
</div>
<div class="modal-footer"><button type="button" class="btn btn-default" data-bs-dismiss="modal">Cerrar y volver al generador</button></div>
