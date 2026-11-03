<?php echo get_reports_topbar(); ?>
<div id="page-content" class="page-wrapper clearfix grid-button">
 <div class="card clearfix">
  <div class="page-title clearfix"><h1><?php echo app_lang('sales_item_detail_report'); ?></h1></div>
  <div class="p15"><div class="alert alert-info mb15"><?php echo app_lang('sales_item_detail_amount_help'); ?></div><div id="sales-item-summary" class="mb15 text-off"></div><div class="table-responsive"><table id="sales-item-detail-table" class="display" width="100%"></table></div></div>
 </div>
</div>
<script type="text/javascript">
$(document).ready(function(){
 var table="#sales-item-detail-table", filters=[
  {name:"client_id",class:"w200",options:<?php echo $clients_dropdown; ?>},
  {name:"item_id",class:"w200",options:<?php echo $items_dropdown; ?>},
  {name:"payment_status",class:"w180",options:[{id:"",text:"- <?php echo app_lang('status'); ?> -"},{id:"not_paid",text:"<?php echo app_lang('not_paid'); ?>"},{id:"partially_paid",text:"<?php echo app_lang('partially_paid'); ?>"},{id:"paid",text:"<?php echo app_lang('fully_paid'); ?>"},{id:"credited",text:"<?php echo app_lang('credited'); ?>"}]},
  {name:"payment_method_id",class:"w180",options:<?php echo $payment_methods_dropdown; ?>}
 ];
 $(table).on('xhr.dt',function(e,settings,json){if(json&&json.summation){$('#sales-item-summary').text('<?php echo app_lang('quantity'); ?>: '+json.summation.quantity_total+' | <?php echo app_lang('total'); ?>: '+toCurrency(json.summation.amount_total)+' | <?php echo app_lang('unique_remissions'); ?>: '+json.summation.remission_count);}});
 $(table).appTable({source:'<?php echo_uri('sales_item_report/list_data'); ?>',serverSide:true,filterDropdown:filters,rangeDatepicker:[{startDate:{name:'start_date',value:'<?php echo $default_start; ?>'},endDate:{name:'end_date',value:'<?php echo $default_end; ?>'},showClearButton:true}],columns:[
  {title:'<?php echo app_lang('quantity'); ?>',order_by:'quantity',class:'text-right all'},{title:'<?php echo app_lang('item'); ?>',order_by:'item',class:'all'},{title:'<?php echo app_lang('rate'); ?>',order_by:'rate',class:'text-right'},{title:'<?php echo app_lang('total'); ?>',order_by:'total',class:'text-right all'},{title:'<?php echo app_lang('remission_id'); ?>',order_by:'remission',class:'all'},{title:'<?php echo app_lang('client'); ?>',order_by:'client'},{title:'<?php echo app_lang('collection_date'); ?>',order_by:'collection_date'},{title:'<?php echo app_lang('due_date'); ?>',order_by:'due_date'},{title:'<?php echo app_lang('client_vat_or_gst_number'); ?>',order_by:'vat'},{title:'<?php echo app_lang('status'); ?>',order_by:'status'}],order:[[4,'desc']],summation:[{column:0,fieldName:'quantity_total',dataType:'number'},{column:3,fieldName:'amount_total',dataType:'currency'}],printColumns:[0,1,2,3,4,5,6,7,8,9],xlsColumns:[]});
 var wrapper=$(table).closest('.dataTables_wrapper');wrapper.find('.filter-section-right').prepend('<div class="filter-item-box"><input id="sales-item-remission" class="form-control" placeholder="<?php echo app_lang('remission_id'); ?>"></div><div class="filter-item-box"><button id="sales-item-csv" class="btn btn-default">CSV</button> <button id="sales-item-xlsx" class="btn btn-default">Excel</button></div>');
 var timer;$('#sales-item-remission').on('input',function(){clearTimeout(timer);var value=this.value;timer=setTimeout(function(){window.InstanceCollection['sales-item-detail-table'].filterParams.remission=value;window.appTables['sales-item-detail-table']._fnReDraw();},350);});
 function exportReport(type){var params=$.extend({},window.InstanceCollection['sales-item-detail-table'].filterParams,{remission:$('#sales-item-remission').val(),search_by:wrapper.find('input[type=search]').val()||''});window.location='<?php echo get_uri('sales_item_report'); ?>'+'/'+type+'?'+$.param(params);}
 $('#sales-item-csv').click(function(){exportReport('export_csv');});$('#sales-item-xlsx').click(function(){exportReport('export_excel');});
});
</script>
