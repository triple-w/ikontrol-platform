<?php
$color = get_setting("proposal_color");
if (!$color) {
    $color = get_setting("invoice_color") ? get_setting("invoice_color") : "#2AA384";
}

$pdf_render = isset($mode);
$image_column_width = $pdf_render ? 18 : 20;
$item_column_width = $pdf_render ? 42 : 40;
$image_max_width = $pdf_render ? 90 : 95;
$image_max_height = $pdf_render ? 85 : 90;
$item_cell_padding = $pdf_render ? 5 : 10;

$discount_row = '<tr>
                        <td colspan="4" style="text-align: right;">' . app_lang("discount") . '</td>
                        <td style="text-align: right; width: 20%; border: 1px solid #fff; background-color: #f4f4f4;">' . to_currency($proposal_total_summary->discount_total, $proposal_total_summary->currency_symbol) . '</td>
                    </tr>';

$total_after_discount_row = '<tr>
                                    <td colspan="4" style="text-align: right;">' . app_lang("total_after_discount") . '</td>
                                    <td style="text-align: right; width: 20%; border: 1px solid #fff; background-color: #f4f4f4;">' . to_currency($proposal_total_summary->proposal_subtotal - $proposal_total_summary->discount_total, $proposal_total_summary->currency_symbol) . '</td>
                                </tr>';
?>

<table class="table-responsive" cellpadding="0" style="width: 100%; border-collapse: collapse;">
    <tr style="font-weight: bold; background-color: <?php echo $color; ?>; color: #fff;  ">
        <th style="width: <?php echo $image_column_width; ?>%; border-right: 1px solid #eee; text-align: center;"> Imagen </th>
        <th style="width: <?php echo $item_column_width; ?>%; border-right: 1px solid #eee;"> <?php echo app_lang("item"); ?> </th>
        <th style="text-align: center; width: 12%; border-right: 1px solid #eee;"> <?php echo app_lang("quantity"); ?></th>
        <th style="text-align: right; width: 14%; border-right: 1px solid #eee;"> <?php echo app_lang("rate"); ?></th>
        <th style="text-align: right; width: 14%; "> <?php echo app_lang("total"); ?></th>
    </tr>
    <?php
    foreach ($proposal_items as $item) {
        $product_image_source = '';
        if (!empty($item->product_image)) {
            if ($pdf_render) {
                $candidate = (string) $item->product_image;
                if (preg_match('#\Adata:image/(?:png|jpeg);base64,[A-Za-z0-9+/]+={0,2}\z#D', $candidate)) {
                    // This source is generated from a validated local PNG/JPEG by
                    // get_store_item_image_pdf_source(). Attribute escaping would
                    // encode the URI delimiters and TCPDF would no longer detect it.
                    $product_image_source = esc($candidate);
                }
            } else {
                $product_image_source = esc($item->product_image, 'attr');
            }
        }
    ?>
        <tr nobr="true" style="background-color: #f4f4f4; page-break-inside: avoid;">
            <td style="width: <?php echo $image_column_width; ?>%; border: 1px solid #fff; padding: <?php echo $pdf_render ? 3 : 5; ?>px; text-align: center; vertical-align: middle;">
                <?php if ($product_image_source) { ?>
                    <img src="<?php echo $product_image_source; ?>" style="max-width: <?php echo $image_max_width; ?>px; max-height: <?php echo $image_max_height; ?>px; width: auto; height: auto;" />
                <?php } ?>
            </td>
            <td style="width: <?php echo $item_column_width; ?>%; border: 1px solid #fff; padding: <?php echo $item_cell_padding; ?>px; hyphens: auto;"><p class="mb5"><?php echo $item->title; ?></p>
                <span style="color: #888; font-size: 90%;"><?php echo custom_nl2br($item->description ? process_images_from_content($item->description) : ""); ?></span>
            </td>
            <td style="text-align: center; width: 12%; border: 1px solid #fff;"> <?php echo $item->quantity . " " . $item->unit_type; ?></td>
            <td style="text-align: right; width: 14%; border: 1px solid #fff;"> <?php echo to_currency($item->rate, $item->currency_symbol); ?></td>
            <td style="text-align: right; width: 14%; border: 1px solid #fff;"> <?php echo to_currency($item->total, $item->currency_symbol); ?></td>
        </tr>
    <?php } ?>
    <tr>
        <td colspan="4" style="text-align: right;"><?php echo app_lang("sub_total"); ?></td>
        <td style="text-align: right; width: 20%; border: 1px solid #fff; background-color: #f4f4f4;">
            <?php echo to_currency($proposal_total_summary->proposal_subtotal, $proposal_total_summary->currency_symbol); ?>
        </td>
    </tr>
    <?php
    if ($proposal_total_summary->discount_total && $proposal_total_summary->discount_type == "before_tax") {
        echo $discount_row . $total_after_discount_row;
    }
    ?>
    <?php if ($proposal_total_summary->tax) { ?>
        <tr>
            <td colspan="4" style="text-align: right;"><?php echo $proposal_total_summary->tax_name; ?></td>
            <td style="text-align: right; width: 20%; border: 1px solid #fff; background-color: #f4f4f4;">
                <?php echo to_currency($proposal_total_summary->tax, $proposal_total_summary->currency_symbol); ?>
            </td>
        </tr>
    <?php } ?>
    <?php if ($proposal_total_summary->tax2) { ?>
        <tr>
            <td colspan="4" style="text-align: right;"><?php echo $proposal_total_summary->tax_name2; ?></td>
            <td style="text-align: right; width: 20%; border: 1px solid #fff; background-color: #f4f4f4;">
                <?php echo to_currency($proposal_total_summary->tax2, $proposal_total_summary->currency_symbol); ?>
            </td>
        </tr>
    <?php } ?>
    <?php
    if ($proposal_total_summary->discount_total && $proposal_total_summary->discount_type == "after_tax") {
        echo $discount_row;
    }
    ?>
    <tr>
        <td colspan="4" style="text-align: right;"><?php echo app_lang("total"); ?></td>
        <td style="text-align: right; width: 20%; background-color: <?php echo $color; ?>; color: #fff;">
            <?php echo to_currency($proposal_total_summary->proposal_total, $proposal_total_summary->currency_symbol); ?>
        </td>
    </tr>
</table>
