<?php
$fc=$fiscal_configuration??[];$setting=(array)($fc['setting']??[]);
$override=in_array((string)($fc['source']??''),['invoice_item_override','item_override'],true);
$value=static fn(string$key):string=>(string)($override?($fc[$key]??''):($setting[$key]??''));
$taxes=array_values((array)($fc['taxes']??[]));
$pricingMode=(string)($override?($fc['pricing_mode']??'tax_inclusive'):($setting['pricing_mode']??$setting['tax_pricing_mode']??'tax_inclusive'));
if(!in_array($pricingMode,['tax_inclusive','tax_exclusive'],true))$pricingMode='tax_inclusive';
?>
<div class="fiscal-item-editor" data-initial-taxes="<?php echo esc(json_encode($taxes,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'attr');?>" data-tax-codes="<?php echo esc(json_encode(array_map(fn($x)=>['code'=>$x->code,'label'=>$x->code.' · '.$x->description],$sat_tax_codes??[]),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'attr');?>">
 <hr><h4>Datos fiscales</h4>
 <div class="fiscal-item-summary alert <?php echo!empty($fc['ready'])?'alert-success':'alert-warning';?>"><?php echo!empty($fc['ready'])?'Configuración fiscal completa.':'Falta configuración fiscal para esta partida.';?></div>
 <div class="form-check mb15"><input type="checkbox" class="form-check-input fiscal-override-enabled" name="fiscal_override_enabled" value="1" <?php echo$override?'checked':'';?>><label>Usar configuración específica para esta partida</label><div><small>No modifica el producto maestro.</small></div></div>
 <div class="fiscal-override-fields <?php echo$override?'':'d-none';?>">
  <div class="row g-2">
   <div class="col-md-6"><label>ClaveProdServ SAT</label><input class="form-control fiscal-product-code" name="product_service_code" value="<?php echo esc($value('product_service_code'));?>"></div>
   <div class="col-md-6"><label>ClaveUnidad SAT</label><input class="form-control fiscal-unit-code" name="unit_code" value="<?php echo esc($value('unit_code'));?>"></div>
   <div class="col-md-6"><label>Unidad</label><input class="form-control fiscal-commercial-unit" name="fiscal_commercial_unit" value="<?php echo esc($value('commercial_unit'));?>"></div>
   <div class="col-md-6"><label>Objeto de impuesto SAT</label><select class="form-control fiscal-tax-object" name="tax_object_code"><?php foreach(($sat_tax_objects??[])as$obj){?><option value="<?php echo esc($obj->code);?>" <?php echo$value('tax_object_code')===$obj->code?'selected':'';?>><?php echo esc($obj->code.' · '.$obj->description);?></option><?php }?></select></div>
   <div class="col-md-6"><label>Modalidad del precio</label><select class="form-control fiscal-pricing-mode" name="pricing_mode"><option value="tax_inclusive" <?php echo$pricingMode==='tax_inclusive'?'selected':'';?>>Impuestos incluidos</option><option value="tax_exclusive" <?php echo$pricingMode==='tax_exclusive'?'selected':'';?>>Impuestos excluidos</option></select></div>
   <div class="col-md-12"><label>Descripción fiscal</label><input class="form-control fiscal-description" name="fiscal_description" value="<?php echo esc($value('fiscal_description'));?>"></div>
  </div>
  <h5 class="mt15">Impuestos</h5><div class="fiscal-tax-rows"></div>
  <button type="button" class="btn btn-default btn-sm mt5 fiscal-add-tax">+ Agregar impuesto</button>
  <?php if(!empty($can_update_master_fiscal)){?><div class="form-check mt15"><input class="form-check-input" type="checkbox" name="update_master_fiscal" value="1"><label>Guardar también como configuración predeterminada del producto</label><div><small class="text-warning">Esta configuración se utilizará en futuras ventas.</small></div></div><?php }?>
 </div>
</div>
<script type="application/x-c230-legacy-disabled">(function(script){
 var root=$(script).prev('.fiscal-item-editor'),form=root.closest('form'),rows=root.find('.fiscal-tax-rows'),index=0;
 var codes=<?php echo json_encode(array_map(fn($x)=>['code'=>$x->code,'label'=>$x->code.' · '.$x->description],$sat_tax_codes??[]),JSON_UNESCAPED_UNICODE);?>,factors=['Tasa','Cuota','Exento'];
 function escHtml(v){return $('<div>').text(String(v==null?'':v)).html();}
 function options(values,value){return values.map(function(row){var key=row.code||row,label=row.label||row;return '<option value="'+escHtml(key)+'" '+(key===value?'selected':'')+'>'+escHtml(label)+'</option>';}).join('');}
 function activate(){root.find('.fiscal-override-enabled').prop('checked',true);root.find('.fiscal-override-fields').removeClass('d-none');}
 function syncFactor(row){var exempt=row.find('.tax-factor').val()==='Exento',rate=row.find('.tax-rate'),hidden=row.find('.tax-rate-hidden');rate.prop('disabled',exempt);hidden.prop('disabled',!exempt);if(exempt){rate.val('');hidden.val('');}}
 function addTax(t){t=t||{};var i=index++,name='fiscal_taxes['+i+']',row=$('<div class="fiscal-tax-row border rounded p-2 mb10"><div class="row g-2"><div class="col-md-3"><label>Tipo</label><select class="form-control tax-type" name="'+name+'[tax_type]"><option value="transfer">Traslado</option><option value="withholding">Retención</option></select></div><div class="col-md-3"><label>Impuesto SAT</label><select class="form-control tax-code" name="'+name+'[tax_code]">'+options(codes,t.tax_code||'')+'</select></div><div class="col-md-2"><label>Factor</label><select class="form-control tax-factor" name="'+name+'[factor_type]">'+options(factors,t.factor_type||'Tasa')+'</select></div><div class="col-md-3"><label>Tasa/cuota</label><input class="form-control tax-rate" name="'+name+'[rate_or_quota]" value="'+escHtml(t.rate_or_quota||'')+'" inputmode="decimal"><input type="hidden" class="tax-rate-hidden" name="'+name+'[rate_or_quota]" disabled></div><div class="col-md-1"><button type="button" class="btn btn-danger btn-sm remove-tax mt25">Eliminar</button></div></div></div>');row.find('.tax-type').val(t.tax_type||'transfer');rows.append(row);syncFactor(row);}
 function render(taxes){rows.empty();index=0;(taxes||[]).forEach(addTax);}
 root.off('.fiscalItem').on('change.fiscalItem input.fiscalItem','.fiscal-override-fields :input',activate).on('change.fiscalItem','.fiscal-override-enabled',function(){root.find('.fiscal-override-fields').toggleClass('d-none',!this.checked);}).on('click.fiscalItem','.fiscal-add-tax',function(){addTax({});activate();}).on('click.fiscalItem','.remove-tax',function(){$(this).closest('.fiscal-tax-row').remove();activate();}).on('change.fiscalItem','.tax-factor',function(){syncFactor($(this).closest('.fiscal-tax-row'));activate();}).on('change.fiscalItem','.fiscal-tax-object',function(){if(this.value==='01')rows.empty();activate();});
 form.off('fiscal:item:load.c230').on('fiscal:item:load.c230',function(e,fiscal,item){fiscal=fiscal||{};item=item||{};var setting=fiscal.setting||fiscal;root.find('.fiscal-item-summary').attr('class','fiscal-item-summary alert '+(fiscal.ready?'alert-success':'alert-warning')).text(fiscal.ready?'Configuración fiscal completa.':'Falta configuración fiscal: '+((fiscal.missing||[]).join(', ')));root.find('.fiscal-product-code').val(setting.product_service_code||'');root.find('.fiscal-unit-code').val(setting.unit_code||'');root.find('.fiscal-commercial-unit').val(setting.commercial_unit||item.unit_type||'');root.find('.fiscal-tax-object').val(setting.tax_object_code||'');root.find('.fiscal-description').val(setting.fiscal_description||item.description||item.title||'');root.find('.fiscal-pricing-mode').val(setting.pricing_mode||setting.tax_pricing_mode||'tax_inclusive');render(fiscal.taxes||[]);root.find('.fiscal-override-enabled').prop('checked',false);root.find('.fiscal-override-fields').addClass('d-none');});
 var initial=root.attr('data-initial-taxes');try{render(initial?JSON.parse(initial):[]);}catch(e){render([]);}
})(document.currentScript);</script>
