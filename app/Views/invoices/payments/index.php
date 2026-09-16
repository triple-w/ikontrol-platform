<div class="card">
    <div class="card-header fw-bold">
        <i data-feather="credit-card" class="icon-16"></i> &nbsp;<?php echo app_lang("payments"); ?>
    </div>
    <?php if (isset($administrative_payment_summary)) { ?><div class="card-body border-bottom"><strong>Total:</strong> <?php echo to_currency($invoice_total_summary->invoice_total ?? 0); ?> &nbsp; <strong>Pagos aplicados:</strong> <?php echo to_currency($administrative_payment_summary['paid']); ?> &nbsp; <strong>Saldo administrativo:</strong> <?php echo to_currency($administrative_payment_summary['outstanding']); ?></div><?php if ($administrative_payment_summary['payments']) { ?><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Fecha</th><th>Pago</th><th>Forma de pago</th><th>Monto recibido</th><th>Aplicado a esta venta</th><th>Estado</th></tr></thead><tbody><?php foreach($administrative_payment_summary['payments'] as $payment){ ?><tr><td><?php echo esc($payment->payment_date); ?></td><td>#<?php echo $payment->payment_id; ?></td><td><?php echo esc($payment->payment_method_title); ?></td><td><?php echo to_currency($payment->payment_amount); ?></td><td><?php echo to_currency($payment->amount_applied); ?></td><td><span class="badge bg-info">Administrativo</span></td></tr><?php } ?></tbody></table></div><?php } ?><?php } ?>

    <?php if ($invoice_status !== "cancelled" && $invoice_info->status !== "credited" && $can_edit_invoices) { ?>
        <div class="card-body">
            <?php
            echo modal_anchor(get_uri("invoice_payments/payment_modal_form"), "<i data-feather='plus' class='icon-16'></i> " . app_lang('add_payment'), array("class" => "inline-block", "data-post-invoice_id" => $invoice_id, "title" => app_lang('add_payment')));
            ?>
        </div>
    <?php } ?>

    <div class="table-responsive">
        <table id="invoice-details-page-payment-table" class="display no-thead b-t b-b-only no-hover hide-dtr-control" width="100%">
        </table>
    </div>
</div>

<script type="text/javascript">
    $(document).ready(function () {
        var optionVisibility = false;
        if ("<?php echo $can_edit_invoices ?>") {
            optionVisibility = true;
        }

        $("#invoice-details-page-payment-table").appTable({
            source: '<?php echo_uri("invoice_payments/payment_list_data/" . $invoice_id) ?>' + '/1',
            order: [[0, "asc"]],
            hideTools: true,
            displayLength: 100,
            stateSave: false,
            responsive: true,
            mobileMirror: true,
            reloadHooks: [{
                    type: "app_form",
                    id: "invoice-payment-form",
                    filter: {invoice_id: "<?php echo $invoice_id ?>"},
                }
            ],
            columns: [
                {targets: [0], visible: false, searchable: false},
                {visible: false, searchable: false},
                {title: '<?php echo app_lang("payment_date") ?> ', "class": "w15p all", "iDataSort": 1},
                {title: '<?php echo app_lang("payment_method") ?>', "class": "w15p"},
                {title: '<?php echo app_lang("note") ?>', "class": "text-wrap"},
                {title: '<?php echo app_lang("amount") ?>', "class": "text-right w15p"},
                {title: '<i data-feather="menu" class="icon-16"></i>', "class": "text-center option w100", visible: optionVisibility}
            ]
        });
    });
</script>
