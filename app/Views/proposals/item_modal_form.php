<?php echo form_open(get_uri("proposals/save_item"), array("id" => "proposal-item-form", "class" => "general-form", "role" => "form")); ?>
<div class="modal-body clearfix">
    <div class="container-fluid">
        <input type="hidden" name="id" value="<?php echo $model_info->id; ?>" />
        <input type="hidden" id="item_id" name="item_id" value="<?php echo (int) ($model_info->item_id ?? 0); ?>" />
        <input type="hidden" name="proposal_id" value="<?php echo $proposal_id; ?>" />
        <input type="hidden" name="add_new_item_to_library" value="" id="add_new_item_to_library" />
        <div class="form-group">
            <div class="row">
                <label for="proposal_item_title" class=" col-md-3"><?php echo app_lang('item'); ?></label>
                <div class="col-md-9">
                    <?php
                    echo form_input(array(
                        "id" => "proposal_item_title",
                        "name" => "proposal_item_title",
                        "value" => $model_info->title,
                        "class" => "form-control validate-hidden",
                        "placeholder" => app_lang('select_or_create_new_item'),
                        "data-rule-required" => true,
                        "data-msg-required" => app_lang("field_required"),
                    ));
                    ?>
                    <a id="proposal_item_title_dropdwon_icon" tabindex="-1" href="javascript:void(0);" style="color: #B3B3B3;float: right; padding: 5px 7px; margin-top: -35px; font-size: 18px;"><span>×</span></a>
                </div>
            </div>
        </div>
        <div class="form-group">
            <div class="row">
                <label for="proposal_item_description" class="col-md-3"><?php echo app_lang('description'); ?></label>
                <div class=" col-md-9">
                    <?php
                    echo form_textarea(array(
                        "id" => "proposal_item_description",
                        "name" => "proposal_item_description",
                        "value" => $model_info->description ? process_images_from_content($model_info->description, false) : "",
                        "class" => "form-control",
                        "placeholder" => app_lang('description'),
                        "data-rich-text-editor" => true
                    ));
                    ?>
                </div>
            </div>
        </div>
        <div class="form-group">
            <div class="row">
                <label for="proposal_item_quantity" class=" col-md-3"><?php echo app_lang('quantity'); ?></label>
                <div class="col-md-9">
                    <?php
                    echo form_input(array(
                        "id" => "proposal_item_quantity",
                        "name" => "proposal_item_quantity",
                        "value" => $model_info->quantity ? to_decimal_format($model_info->quantity) : "",
                        "class" => "form-control",
                        "placeholder" => app_lang('quantity'),
                        "data-rule-required" => true,
                        "data-msg-required" => app_lang("field_required"),
                    ));
                    ?>
                </div>
            </div>
        </div>
        <div class="form-group">
            <div class="row">
                <label for="proposal_unit_type" class=" col-md-3"><?php echo app_lang('unit_type'); ?></label>
                <div class="col-md-9">
                    <?php
                    echo form_input(array(
                        "id" => "proposal_unit_type",
                        "name" => "proposal_unit_type",
                        "value" => $model_info->unit_type,
                        "class" => "form-control",
                        "placeholder" => app_lang('unit_type') . ' (Ex: hours, pc, etc.)'
                    ));
                    ?>
                </div>
            </div>
        </div>
        <?php if(!empty($can_view_supplier_costs)): ?>
        <div class="card bg-light mb15"><div class="card-body"><h6><i data-feather="briefcase" class="icon-16"></i> Información interna de proveedor</h6>
          <div class="form-group"><label>Proveedor utilizado</label><div class="input-group"><?php $supplierAttributes="id='proposal-item-supplier' class='form-control select2'". (empty($can_edit_supplier_costs)?" disabled='disabled'":""); echo form_dropdown('supplier_id',$suppliers_dropdown??[],(int)($model_info->supplier_id??0),$supplierAttributes); ?><?php if(!empty($can_manage_suppliers)&&!empty($can_edit_supplier_costs)): ?><button type="button" class="btn btn-default" id="quick-supplier-open"><i data-feather="plus" class="icon-16"></i> Nuevo proveedor</button><?php endif; ?></div><div class="mt10"><button type="button" class="btn btn-outline-primary <?php echo empty($model_info->item_id)?'hide':''; ?>" id="compare-suppliers" data-product-id="<?php echo(int)($model_info->item_id??0); ?>" data-proposal-item-id="<?php echo(int)($model_info->id??0); ?>"><i data-feather="bar-chart-2" class="icon-16"></i> Comparar proveedores y ofertas</button><small class="text-muted ms10">Consulta el histórico aunque todavía no existan ofertas.</small></div></div>
          <?php if(!empty($can_edit_supplier_costs)){echo view('items/_commercial_margin_fields',['field_prefix'=>'proposal','model_info'=>$model_info]);} ?>
          <div id="selected-supplier-history" class="alert alert-light <?php echo empty($selected_supplier_history)?'hide':''; ?>" data-last-cost="<?php echo esc($selected_supplier_history['last_cost']??'','attr'); ?>"><strong>Último costo con este proveedor:</strong> <span data-selected-history="cost"><?php echo isset($selected_supplier_history['last_cost'])?to_currency($selected_supplier_history['last_cost']):'-'; ?></span> · <span data-selected-history="date"><?php echo isset($selected_supplier_history['last_date'])?esc(format_to_datetime($selected_supplier_history['last_date'])):'-'; ?></span><?php if(!empty($can_edit_supplier_costs)): ?> <button type="button" class="btn btn-default btn-sm" id="use-last-supplier-cost">Usar último costo</button><?php endif; ?><div id="supplier-cost-variation" class="mt5 hide"></div></div>
          <div id="supplier-cost-context" class="alert alert-info mt10 <?php echo empty($cost_indicators['supplier_count'])?'hide':''; ?>"><b>Referencia histórica (no se aplica automáticamente)</b><br>Último proveedor: <span data-history="supplier"><?php echo esc($cost_indicators['last_supplier']??'-'); ?></span> · Último costo: <span data-history="cost"><?php echo isset($cost_indicators['last_cost'])?to_currency($cost_indicators['last_cost']):'-'; ?></span> · Fecha: <span data-history="date"><?php echo esc($cost_indicators['last_date']??'-'); ?></span><br>Mejor histórico: <span data-history="best"><?php echo isset($cost_indicators['best_cost'])?to_currency($cost_indicators['best_cost']):'-'; ?></span> · Proveedores conocidos: <span data-history="count"><?php echo(int)($cost_indicators['supplier_count']??0); ?></span></div>
        </div></div>
        <?php endif; ?>
        <?php echo view('items/_fiscal_item_fields',['fiscal_configuration'=>$fiscal_configuration??[],'sat_tax_codes'=>$sat_tax_codes??[],'sat_tax_objects'=>$sat_tax_objects??[],'can_update_master_fiscal'=>$can_update_master_fiscal??false]); ?>
        <div class="form-group">
            <div class="row">
                <label for="proposal_item_rate" class=" col-md-3">Precio de venta</label>
                <div class="col-md-9">
                    <?php
                    echo form_input(array(
                        "id" => "proposal_item_rate",
                        "name" => "proposal_item_rate",
                        "value" => $model_info->rate ? to_decimal_format($model_info->rate) : "",
                        "class" => "form-control",
                        "placeholder" => app_lang('rate'),
                        "data-rule-required" => true,
                        "data-msg-required" => app_lang("field_required"),
                    ));
                    ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal-footer">
    <button type="button" class="btn btn-default" data-bs-dismiss="modal"><span data-feather="x" class="icon-16"></span> <?php echo app_lang('close'); ?></button>
    <button type="submit" class="btn btn-primary"><span data-feather="check-circle" class="icon-16"></span> <?php echo app_lang('save'); ?></button>
</div>
<?php echo form_close(); ?>

<?php if (!empty($can_manage_suppliers) && !empty($can_edit_supplier_costs)): ?>
<div class="modal fade" id="quick-supplier-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm"><div class="modal-content">
        <?php echo form_open(get_uri('suppliers/save'), ['id' => 'quick-supplier-form', 'class' => 'general-form']); ?>
        <div class="modal-header"><h5 class="modal-title">Nuevo proveedor</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
        <div class="modal-body"><?php echo view('suppliers/form_fields', ['model_info' => (object) ['id' => 0, 'status' => 'active'], 'prefix' => 'quick-supplier']); ?></div>
        <div class="modal-footer"><button type="button" class="btn btn-default" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Guardar</button></div>
        <?php echo form_close(); ?>
    </div></div>
</div>
<?php endif; ?>
<?php if(!empty($can_view_supplier_costs)): ?><div class="modal fade" id="supplier-comparison-modal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-xl"><div class="modal-content" id="supplier-comparison-content"></div></div></div><?php endif; ?>

<script type="text/javascript">
    $(document).ready(function () {
        $("#proposal-item-form").appForm({
            onSuccess: function (result) {
                $("#proposal-item-table").appTable({newData: result.data, dataId: result.id});
                $("#proposal-total-section").html(result.proposal_total_view);
            }
        });

        var proposalItemModalContent = $('#ajaxModalContent');
        function selectSupplierInParent(data) {
            var supplier = proposalItemModalContent.find('#proposal-item-supplier');
            var id = String(data.supplier_id || data.id || '');
            var text = data.supplier_name || data.name || data.text || '';
            if (supplier.length !== 1 || !id || !text) {
                return false;
            }
            var option = supplier.find('option[value="' + id + '"]');
            if (!option.length) {
                supplier.append(new Option(text, id, true, true));
            } else {
                option.text(text).prop('selected', true);
            }
            // Select2 v3 listens to the native change event. One event updates
            // its UI and invokes the existing cost-reference workflow once.
            supplier.val(id).trigger('change');
            return true;
        }

        var quickSupplierModal = document.getElementById('quick-supplier-modal');
        if (quickSupplierModal) {
            $(quickSupplierModal).appendTo(document.body);
            var supplierModal = new bootstrap.Modal(quickSupplierModal);
            var existingSupplier = null;
            var quickSupplierForm = $(quickSupplierModal).find('#quick-supplier-form');
            quickSupplierModal.addEventListener('hidden.bs.modal', function () {
                if ($('#ajaxModal').hasClass('show')) {
                    document.body.classList.add('modal-open');
                    proposalItemModalContent.find('#quick-supplier-open').trigger('focus');
                }
            });
            proposalItemModalContent.find('#quick-supplier-open').on('click', function () { existingSupplier=null;quickSupplierForm.find('[data-supplier-duplicate]').addClass('hide'); quickSupplierForm.find('[data-supplier-allow-similar]').val('0'); supplierModal.show(); });
            quickSupplierForm.appForm({
                isModal: false,
                closeModalOnSuccess: false,
                onSuccess: function (result) {
                    if (!selectSupplierInParent(result)) {
                        appAlert.error('El proveedor se guardó, pero no fue posible seleccionarlo en la partida.');
                        return;
                    }
                    supplierModal.hide();
                    quickSupplierForm[0].reset();
                },
                onError: function (result) {
                    if (!result.duplicate_supplier) { return; }
                    existingSupplier=result.existing;var form=quickSupplierForm,box=form.find('[data-supplier-duplicate]').removeClass('hide');
                    box.find('[data-supplier-duplicate-message]').text(result.message);box.find('[data-supplier-existing-name]').text(existingSupplier.name);
                    box.find('[data-supplier-existing-detail]').text([existingSupplier.rfc,existingSupplier.phone,existingSupplier.email].filter(Boolean).join(' · '));
                    box.find('[data-supplier-create-anyway]').toggleClass('hide',!result.allow_create);
                }
            });
            quickSupplierForm.on('click','[data-supplier-use-existing]',function(){if(existingSupplier&&selectSupplierInParent(existingSupplier)){supplierModal.hide();}});
            quickSupplierForm.on('click','[data-supplier-create-anyway]',function(){quickSupplierForm.find('[data-supplier-allow-similar]').val('1');quickSupplierForm.submit();});
            $('#ajaxModal').one('hidden.bs.modal', function () {
                supplierModal.dispose();
                $(quickSupplierModal).remove();
            });
        }

        var comparisonElement=document.getElementById('supplier-comparison-modal');
        if(comparisonElement){
            $(comparisonElement).appendTo(document.body);var comparisonModal=new bootstrap.Modal(comparisonElement),comparisonContent=$('#supplier-comparison-content');
            function loadSupplierComparison(){var button=$('#compare-suppliers'),productId=button.data('product-id');if(!productId)return;comparisonContent.html('<div class="modal-body text-center p30">Cargando comparación...</div>');$.ajax({url:'<?php echo get_uri('proposals/products'); ?>/'+productId+'/supplier-comparison',type:'POST',data:{proposal_item_id:button.data('proposal-item-id')||0,'<?php echo csrf_token(); ?>':'<?php echo csrf_hash(); ?>'}}).done(function(html){comparisonContent.html(html);comparisonContent.find('.select2').select2();if(window.feather)feather.replace();}).fail(function(xhr){comparisonContent.html('<div class="modal-body"><div class="alert alert-danger">'+((xhr.responseJSON||{}).message||'No fue posible consultar proveedores y ofertas.')+'</div></div>');});}
            $('#compare-suppliers').on('click',function(){comparisonModal.show();loadSupplierComparison();});
            comparisonContent.on('click','.comparison-sort',function(){var key=$(this).data('sort'),body=$('#supplier-comparison-table tbody'),rows=body.children('.supplier-comparison-row').get(),ascending=$(this).data('ascending')!==true;comparisonContent.find('.comparison-sort').data('ascending',false);$(this).data('ascending',ascending);rows.sort(function(a,b){var av=a.getAttribute('data-'+key),bv=b.getAttribute('data-'+key);if(key==='last'||key==='best'){av=parseMicros(av);bv=parseMicros(bv);}var result=av<bv?-1:(av>bv?1:0);return ascending?result:-result;});$.each(rows,function(_,row){var id=row.getAttribute('data-supplier'),history=body.children('.supplier-comparison-history[data-supplier="'+id+'"]');body.append(row).append(history);});});
            comparisonContent.on('click','.select-compared-supplier',function(){var button=$(this),supplier=$('#proposal-item-supplier'),id=String(button.data('id')),name=button.data('name');if(!supplier.find('option[value="'+id+'"]').length){supplier.append(new Option(name,id,false,false));}supplier.val(id);if(supplier.data('select2'))supplier.select2('val',id);supplier.trigger('change');setSelectedSupplierHistory(String(button.data('cost')),String(button.data('date')));comparisonModal.hide();});
            comparisonContent.on('click','[data-toggle-quote-form]',function(){comparisonContent.find('[data-supplier-quote-form]').removeClass('hide');});
            comparisonContent.on('click','[data-cancel-quote]',function(){comparisonContent.find('[data-supplier-quote-form]').addClass('hide')[0].reset();});
            comparisonContent.on('click','[data-toggle-inline-supplier]',function(){comparisonContent.find('[data-inline-supplier-form]').removeClass('hide');});
            comparisonContent.on('click','[data-cancel-inline-supplier]',function(){comparisonContent.find('[data-inline-supplier-form]').addClass('hide');});
            comparisonContent.on('click','[data-edit-quote]',function(){var d=$(this).data('edit-quote'),f=comparisonContent.find('[data-supplier-quote-form]').removeClass('hide');Object.keys(d).forEach(function(k){f.find('[name="'+k+'"]' ).val(d[k]===null?'':d[k]).trigger('change');});});
            comparisonContent.on('submit','[data-supplier-quote-form]',function(e){e.preventDefault();var itemId=comparisonContent.find('[data-supplier-comparison-item]').data('supplier-comparison-item');$.ajax({url:'<?php echo get_uri('proposals/items'); ?>/'+itemId+'/supplier-quotes/save',type:'POST',data:$(this).serialize(),dataType:'json'}).done(function(r){if(r.success){appAlert.success(r.message);loadSupplierComparison();}}).fail(function(x){appAlert.error((x.responseJSON||{}).message||'No fue posible guardar la oferta.');});});
            function chooseInlineSupplier(existing){var s=comparisonContent.find('[data-quote-supplier]'),id=String(existing.id||existing.supplier_id),name=existing.name||existing.supplier_name;if(!s.find('option[value="'+id+'"]').length)s.append(new Option(name,id,true,true));s.val(id).trigger('change');comparisonContent.find('[data-inline-supplier-form]').addClass('hide');}
            comparisonContent.on('submit','[data-inline-supplier-form]',function(e){e.preventDefault();var f=$(this);$.ajax({url:f.attr('action'),type:'POST',data:f.serialize(),dataType:'json'}).done(function(r){if(!r.success){if(r.duplicate_supplier&&r.existing){f.data('existing',r.existing);var box=f.find('[data-supplier-duplicate]').removeClass('hide');box.find('[data-supplier-duplicate-message]').text(r.message);box.find('[data-supplier-existing-name]').text(r.existing.name);box.find('[data-supplier-existing-detail]').text([r.existing.rfc,r.existing.phone,r.existing.email].filter(Boolean).join(' · '));box.find('[data-supplier-create-anyway]').toggleClass('hide',!r.allow_create);}else appAlert.error(r.message);return;}chooseInlineSupplier({id:r.supplier_id||r.id,name:r.supplier_name||r.text});}).fail(function(x){appAlert.error((x.responseJSON||{}).message||'No fue posible guardar el proveedor.');});});
            comparisonContent.on('click','[data-inline-supplier-form] [data-supplier-use-existing]',function(){var f=comparisonContent.find('[data-inline-supplier-form]'),existing=f.data('existing');if(existing)chooseInlineSupplier(existing);});
            comparisonContent.on('click','[data-inline-supplier-form] [data-supplier-create-anyway]',function(){var f=comparisonContent.find('[data-inline-supplier-form]');f.find('[data-supplier-allow-similar]').val('1');f.trigger('submit');});
            comparisonContent.on('click','[data-select-quote]',function(){var itemId=comparisonContent.find('[data-supplier-comparison-item]').data('supplier-comparison-item'),quoteId=$(this).data('select-quote');$.post('<?php echo get_uri('proposals/items'); ?>/'+itemId+'/supplier-quotes/'+quoteId+'/select',{'<?php echo csrf_token(); ?>':'<?php echo csrf_hash(); ?>'},function(r){if(!r.success)return;var d=r.selected,s=$('#proposal-item-supplier'),id=String(d.supplier_id);if(!s.find('option[value="'+id+'"]').length)s.append(new Option(d.supplier_name,id,true,true));s.val(id);if(s.data('select2'))s.select2('val',id);s.trigger('change');$('#proposal_item_cost').val(d.unit_cost).trigger('change');$('#proposal_item_profit_percentage').val(d.margin).trigger('change');appAlert.success(r.message);loadSupplierComparison();},'json').fail(function(x){appAlert.error((x.responseJSON||{}).message||'No fue posible seleccionar la oferta.');});});
            comparisonContent.on('click','[data-delete-quote]',function(){var itemId=comparisonContent.find('[data-supplier-comparison-item]').data('supplier-comparison-item'),quoteId=$(this).data('delete-quote');$.post('<?php echo get_uri('proposals/items'); ?>/'+itemId+'/supplier-quotes/'+quoteId+'/delete',{'<?php echo csrf_token(); ?>':'<?php echo csrf_hash(); ?>'},function(r){appAlert.success(r.message);loadSupplierComparison();},'json').fail(function(x){appAlert.error((x.responseJSON||{}).message||'No fue posible eliminar la oferta.');});});
            comparisonElement.addEventListener('hidden.bs.modal',function(){$('#compare-suppliers').trigger('focus');});
            $('#ajaxModal').one('hidden.bs.modal',function(){comparisonModal.dispose();$(comparisonElement).remove();});
        }

        function parseMicros(value){var match=String(value||'').trim().replace(',','.').match(/^(\d+)(?:\.(\d{0,6}))?$/);if(!match)return null;return BigInt(match[1])*1000000n+BigInt((match[2]||'').padEnd(6,'0'));}
        function signedMoney(micros){var sign=micros<0n?'-':'+';var value=micros<0n?-micros:micros,cents=(value+5000n)/10000n;return sign+'$'+(cents/100n).toString()+'.'+(cents%100n).toString().padStart(2,'0');}
        function refreshVariation(){var box=$('#selected-supplier-history'),last=parseMicros(box.attr('data-last-cost')),current=parseMicros($('#proposal_item_cost').val()),out=$('#supplier-cost-variation');if(last===null||current===null||last===0n){out.addClass('hide').empty();return;}var diff=current-last,scaled=(diff*100000000n)/last,sign=scaled<0n?'-':'+';var absolute=scaled<0n?-scaled:scaled,hundredths=(absolute+5000n)/10000n;out.removeClass('hide').text('Variación vs último costo: '+signedMoney(diff)+' · '+sign+(hundredths/100n).toString()+'.'+(hundredths%100n).toString().padStart(2,'0')+'%');}
        function moneyText(value){var micros=parseMicros(value);if(micros===null)return '-';var cents=(micros+5000n)/10000n;return '$'+(cents/100n).toString()+'.'+(cents%100n).toString().padStart(2,'0');}
        function setSelectedSupplierHistory(cost,date){var box=$('#selected-supplier-history');box.attr('data-last-cost',cost).removeClass('hide');box.find('[data-selected-history=cost]').text(moneyText(cost));box.find('[data-selected-history=date]').text(date);refreshVariation();}
        $('#use-last-supplier-cost').on('click',function(){var cost=$('#selected-supplier-history').attr('data-last-cost');if(cost)$('#proposal_item_cost').val(cost).trigger('input').trigger('change');});
        $('#proposal_item_cost').on('input change',refreshVariation);refreshVariation();
        $('#proposal-item-supplier').on('change',function(){var supplierId=$(this).val(),productId=$('#compare-suppliers').data('product-id');if(!supplierId||!productId){$('#selected-supplier-history').addClass('hide').attr('data-last-cost','');return;}$.ajax({url:'<?php echo get_uri('proposals/products'); ?>/'+productId+'/suppliers/'+supplierId+'/cost-reference',type:'POST',dataType:'json',data:{'<?php echo csrf_token(); ?>':'<?php echo csrf_hash(); ?>'}}).done(function(result){if(result.found){setSelectedSupplierHistory(result.reference.last_cost,result.reference.last_date);}else{$('#selected-supplier-history').addClass('hide').attr('data-last-cost','');$('#supplier-cost-variation').addClass('hide').empty();}});});

        //show item suggestion dropdown when adding new item
        var isUpdate = "<?php echo $model_info->id; ?>";
        if (!isUpdate) {
            applySelect2OnItemTitle();
        }

        //re-initialize item suggestion dropdown on request
        $("#proposal_item_title_dropdwon_icon").click(function () {
            applySelect2OnItemTitle();
        })

    });

    function applySelect2OnItemTitle() {
        $("#proposal_item_title").select2({
            showSearchBox: true,
            ajax: {
                url: "<?php echo get_uri("proposals/get_proposal_item_suggestion"); ?>",
                type: 'POST',
                dataType: 'json',
                quietMillis: 250,
                data: function (term, page) {
                    return {
                        q: term // search term
                    };
                },
                results: function (data, page) {
                    return {results: data};
                }
            }
        }).change(function (e) {
            if (e.val === "+") {
                //show simple textbox to input the new item
                $("#proposal_item_title").select2("destroy").val("").focus();
                $("#add_new_item_to_library").val(1); //set the flag to add new item in library
            } else if (e.val) {
                //get existing item info
                $("#add_new_item_to_library").val(""); //reset the flag to add new item in library
                appAjaxRequest({
                    url: "<?php echo get_uri("proposals/get_proposal_item_info_suggestion"); ?>",
                    data: {item_id: e.val},
                    cache: false,
                    type: 'POST',
                    dataType: "json",
                    success: function (response) {

                        //auto fill the description, unit type and rate fields.
                        if (response && response.success) {
                            $("#item_id").val(response.item_info.id);
                            $('#compare-suppliers').data('product-id',response.item_info.id).attr('data-product-id',response.item_info.id).removeClass('hide');
                            $("#proposal_item_title").val(response.item_info.title);
                            
                            $("#proposal_item_description").val(response.item_info.description);

                            $("#proposal_unit_type").val(response.item_info.unit_type);
                            $("#proposal_item_cost").val(response.item_info.cost || "").trigger('change');
                            var h=response.item_info.supplier_cost_history||{};if(h.supplier_count){$('#supplier-cost-context').removeClass('hide');$('[data-history=supplier]').text(h.last_supplier||'-');$('[data-history=cost]').text(h.last_cost||'-');$('[data-history=date]').text(h.last_date||'-');$('[data-history=best]').text(h.best_cost||'-');$('[data-history=count]').text(h.supplier_count);}else{$('#supplier-cost-context').addClass('hide');}
                            $("#proposal_item_rate").val(response.item_info.rate);
                            $("#proposal-item-form").trigger('fiscal:item:load',[response.item_info.fiscal||{},response.item_info]);
                        }
                    }
                });
            }

        });
    }

</script>
