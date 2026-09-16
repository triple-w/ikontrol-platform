<?php $canonical_tax_breakdown=$canonical_tax_breakdown??[]; ?>
<table id="invoice-item-table" class="table display dataTable text-right strong table-responsive no-body-top-bottom-border">
    <tr>
        <td><?php echo app_lang("sub_total"); ?></td>
        <td style="width: 120px;"><?php echo to_currency(!empty($canonical_tax_breakdown['ready'])?$canonical_tax_breakdown['subtotal']:$invoice_total_summary->invoice_subtotal, $invoice_total_summary->currency_symbol); ?></td>
        <?php if ($can_edit_invoices && $is_invoice_editable) { ?>
            <td style="width: 100px;"> </td>
        <?php } ?>
    </tr>

    <?php
    $discount_edit_btn = "";
    $total_after_discount_btn = "";
    if ($can_edit_invoices && $is_invoice_editable) {
        $discount_edit_btn = "<td class='text-center option w100'>" . modal_anchor(get_uri("invoices/discount_modal_form"), "<i data-feather='edit' class='icon-16'></i>", array("class" => "edit", "data-post-invoice_id" => $invoice_id, "title" => app_lang('edit_discount'))) . "<span class='p20'>&nbsp;&nbsp;&nbsp;</span></td>";
        $total_after_discount_btn = "<td></td>";
    }
    $discount_row = "<tr>
                        <td style='padding-top:13px;'>" . app_lang("discount") . "</td>
                        <td style='padding-top:13px;'>" . to_currency($invoice_total_summary->discount_total, $invoice_total_summary->currency_symbol) . "</td>
                        $discount_edit_btn
                    </tr>";

    $total_after_discount_row = "<tr>
                                    <td>" . app_lang("total_after_discount") . "</td>
                                    <td style='width:120px;'>" . to_currency($invoice_total_summary->invoice_subtotal - $invoice_total_summary->discount_total, $invoice_total_summary->currency_symbol) . "</td>
                                    $total_after_discount_btn
                                </tr>";

    if ($invoice_total_summary->invoice_subtotal && (!$invoice_total_summary->discount_total || ($invoice_total_summary->discount_total !== 0 && $invoice_total_summary->discount_type == "before_tax"))) {
        //when there is discount and type is before tax or no discount
        echo $discount_row;

        if ($invoice_total_summary->discount_total) {
            echo $total_after_discount_row;
        }
    }
    ?>

    <?php if (empty($canonical_tax_breakdown['ready']) && $invoice_total_summary->tax) { ?>
        <tr>
            <td><?php echo $invoice_total_summary->tax_name; ?></td>
            <td><?php echo to_currency($invoice_total_summary->tax, $invoice_total_summary->currency_symbol); ?></td>
            <?php if ($can_edit_invoices && $is_invoice_editable) { ?>
                <td></td>
            <?php } ?>
        </tr>
    <?php } ?>
    <?php if (!empty($canonical_tax_breakdown['ready']) && \App\Services\Fiscal\FiscalDecimal::micros((string)$canonical_tax_breakdown['transferred']) > 0) { ?>
        <tr><td>Impuestos trasladados</td><td><?php echo to_currency($canonical_tax_breakdown['transferred'], $invoice_total_summary->currency_symbol); ?></td><?php if ($can_edit_invoices && $is_invoice_editable) { ?><td></td><?php } ?></tr>
    <?php } ?>
    <?php if (!empty($canonical_tax_breakdown['ready']) && \App\Services\Fiscal\FiscalDecimal::micros((string)$canonical_tax_breakdown['withheld']) > 0) { ?>
        <tr><td>Retenciones</td><td><?php echo to_currency($canonical_tax_breakdown['withheld'], $invoice_total_summary->currency_symbol); ?></td><?php if ($can_edit_invoices && $is_invoice_editable) { ?><td></td><?php } ?></tr>
    <?php } ?>
    <?php if (!empty($canonical_tax_breakdown['ready'])) { ?>
        <tr><td>Total</td><td><?php echo to_currency($canonical_tax_breakdown['total'], $invoice_total_summary->currency_symbol); ?></td><?php if ($can_edit_invoices && $is_invoice_editable) { ?><td></td><?php } ?></tr>
    <?php } ?>
    <?php if (empty($canonical_tax_breakdown['ready']) && $invoice_total_summary->tax2) { ?>
        <tr>
            <td><?php echo $invoice_total_summary->tax_name2; ?></td>
            <td><?php echo to_currency($invoice_total_summary->tax2, $invoice_total_summary->currency_symbol); ?></td>
            <?php if ($can_edit_invoices && $is_invoice_editable) { ?>
                <td></td>
            <?php } ?>
        </tr>
    <?php } ?>
    <?php if (empty($canonical_tax_breakdown['ready']) && $invoice_total_summary->tax3) { ?>
        <tr>
            <td><?php echo $invoice_total_summary->tax_name3; ?></td>
            <td><?php echo to_currency($invoice_total_summary->tax3, $invoice_total_summary->currency_symbol); ?></td>
            <?php if ($can_edit_invoices && $is_invoice_editable) { ?>
                <td></td>
            <?php } ?>
        </tr>
    <?php } ?>
    <?php
    if ($invoice_total_summary->discount_total && $invoice_total_summary->discount_type == "after_tax") {
        //when there is discount and type is after tax
        echo $discount_row;
    }
    ?>
    <?php if ($invoice_total_summary->total_paid) { ?>
        <tr>
            <td><?php echo app_lang("paid"); ?></td>
            <td><?php echo to_currency($invoice_total_summary->total_paid, $invoice_total_summary->currency_symbol); ?></td>
            <?php if ($can_edit_invoices && $is_invoice_editable) { ?>
                <td></td>
            <?php } ?>
        </tr>
    <?php } ?>
    <tr>
        <td><?php echo app_lang("balance_due"); ?></td>
        <td><?php echo to_currency($invoice_total_summary->balance_due, $invoice_total_summary->currency_symbol); ?></td>
        <?php if ($can_edit_invoices && $is_invoice_editable) { ?>
            <td></td>
        <?php } ?>
    </tr>
</table>
