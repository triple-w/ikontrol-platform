(function ($, window, document) {
    "use strict";

    function html(value) {
        return $("<div>").text(value == null ? "" : String(value)).html();
    }

    function options(values, selected) {
        return values.map(function (row) {
            var value = row.code || row;
            return '<option value="' + html(value) + '" ' + (value === selected ? "selected" : "") + '>' + html(row.label || row) + '</option>';
        }).join("");
    }

    function parseData($editor, name) {
        var raw = $editor.attr(name);
        if (!raw) return [];
        try { return JSON.parse(raw); } catch (error) { return []; }
    }

    function initializeOne($editor) {
        var $form = $editor.closest("form"), $rows = $editor.find(".fiscal-tax-rows");
        var codes = parseData($editor, "data-tax-codes"), factors = ["Tasa", "Cuota", "Exento"], index = 0;
        var alreadyInitialized = $editor.data("fiscal-item-editor-initialized") === true;

        function activate() {
            $editor.find(".fiscal-override-enabled").prop("checked", true);
            $editor.find(".fiscal-override-fields").removeClass("d-none");
        }
        function syncFactor($row) {
            var exempt = $row.find(".tax-factor").val() === "Exento";
            var $rate = $row.find(".tax-rate"), $hidden = $row.find(".tax-rate-hidden");
            $rate.prop("disabled", exempt);
            $hidden.prop("disabled", !exempt);
            if (exempt) { $rate.val(""); $hidden.val(""); }
        }
        function addTax(tax) {
            tax = tax || {};
            var current = index++, name = "fiscal_taxes[" + current + "]";
            var $row = $('<div class="fiscal-tax-row border rounded p-2 mb10"><div class="row g-2">' +
                '<div class="col-md-3"><label>Tipo</label><select class="form-control tax-type" name="' + name + '[tax_type]"><option value="transfer">Traslado</option><option value="withholding">Retención</option></select></div>' +
                '<div class="col-md-3"><label>Impuesto SAT</label><select class="form-control tax-code" name="' + name + '[tax_code]">' + options(codes, tax.tax_code || "") + '</select></div>' +
                '<div class="col-md-2"><label>Factor</label><select class="form-control tax-factor" name="' + name + '[factor_type]">' + options(factors, tax.factor_type || "Tasa") + '</select></div>' +
                '<div class="col-md-3"><label>Tasa/cuota</label><input class="form-control tax-rate" name="' + name + '[rate_or_quota]" value="' + html(tax.rate_or_quota || "") + '" inputmode="decimal"><input type="hidden" class="tax-rate-hidden" name="' + name + '[rate_or_quota]" disabled></div>' +
                '<div class="col-md-1"><button type="button" class="btn btn-danger btn-sm remove-tax mt25">Eliminar</button></div></div></div>');
            $row.find(".tax-type").val(tax.tax_type || "transfer");
            $rows.append($row);
            syncFactor($row);
        }
        function render(taxes) {
            $rows.empty(); index = 0; (taxes || []).forEach(addTax);
        }
        function loadProduct(fiscal, item) {
            fiscal = fiscal || {}; item = item || {};
            var setting = fiscal.setting || fiscal;
            $editor.find(".fiscal-item-summary").attr("class", "fiscal-item-summary alert " + (fiscal.ready ? "alert-success" : "alert-warning"))
                .text(fiscal.ready ? "Configuración fiscal completa." : "Falta configuración fiscal: " + (fiscal.missing || []).join(", "));
            $editor.find(".fiscal-product-code").val(setting.product_service_code || "");
            $editor.find(".fiscal-unit-code").val(setting.unit_code || "");
            $editor.find(".fiscal-commercial-unit").val(setting.commercial_unit || item.unit_type || "");
            $editor.find(".fiscal-tax-object").val(setting.tax_object_code || "");
            $editor.find(".fiscal-description").val(setting.fiscal_description || item.description || item.title || "");
            $editor.find(".fiscal-pricing-mode").val(setting.pricing_mode || setting.tax_pricing_mode || fiscal.pricing_mode || "tax_inclusive");
            render(fiscal.taxes || []);
            $editor.find(".fiscal-override-enabled").prop("checked", false);
            $editor.find(".fiscal-override-fields").removeClass("d-none");
        }

        $editor.off(".fiscalItem")
            .on("change.fiscalItem input.fiscalItem", ".fiscal-override-fields :input", activate)
            .on("change.fiscalItem", ".fiscal-override-enabled", function () { $editor.find(".fiscal-override-fields").toggleClass("d-none", !this.checked); })
            .on("click.fiscalItem", ".fiscal-add-tax", function () { addTax({}); activate(); })
            .on("click.fiscalItem", ".remove-tax", function () { $(this).closest(".fiscal-tax-row").remove(); activate(); })
            .on("change.fiscalItem", ".tax-factor", function () { syncFactor($(this).closest(".fiscal-tax-row")); activate(); })
            .on("change.fiscalItem", ".fiscal-tax-object", function () { if (this.value === "01") $rows.empty(); activate(); });
        $form.off("fiscal:item:load.fiscalItem").on("fiscal:item:load.fiscalItem", function (event, fiscal, item) { loadProduct(fiscal, item); });
        if (!alreadyInitialized) render(parseData($editor, "data-initial-taxes"));
        $editor.data("fiscal-item-editor-initialized", true);
    }

    window.initializeFiscalItemEditors = function (container) {
        var $container = container ? $(container) : $(document);
        $container.find(".fiscal-item-editor").addBack(".fiscal-item-editor").each(function () { initializeOne($(this)); });
    };

    function initializeMarginOne($box) {
        var $cost = $box.find(".commercial-cost");
        var $margin = $box.find(".commercial-margin");
        var prefix = $box.attr("data-prefix") || "";
        var $price = $box.closest("form").find("#" + prefix + "_item_rate");
        var $origin = $box.closest("form").find("[name=price_origin]");
        var syncing = false;
        function number(value) {
            var normalized = String(value == null ? "" : value).replace(/,/g, "").trim();
            return normalized === "" ? NaN : Number(normalized);
        }
        function loss() {
            var cost = number($cost.val()), price = number($price.val());
            $box.find(".commercial-loss-warning").toggleClass("d-none", !(Number.isFinite(cost) && Number.isFinite(price) && price < cost));
        }
        function forward() {
            if (syncing) return;
            var cost = number($cost.val()), margin = number($margin.val());
            $box.find(".commercial-margin-error").toggleClass("d-none", !(Number.isFinite(margin) && margin >= 100));
            if (!Number.isFinite(margin)) { loss(); return; }
            if (!Number.isFinite(cost) || margin < 0 || margin >= 100) return;
            syncing = true;
            $price.val((cost / (1 - margin / 100)).toFixed(6));
            $origin.val("cost_margin");
            syncing = false;
            loss();
        }
        function inverse() {
            if (syncing || String($margin.val() || "").trim() === "") { loss(); return; }
            var cost = number($cost.val()), price = number($price.val());
            if (!Number.isFinite(cost) || !Number.isFinite(price) || price < 0) return;
            syncing = true;
            $origin.val("manual");
            $margin.val(price === 0 ? "0.000000" : (((price - cost) / price) * 100).toFixed(6));
            syncing = false;
            loss();
        }
        $box.off(".commercialMargin")
            .on("input.commercialMargin change.commercialMargin", ".commercial-cost, .commercial-margin", forward);
        $price.off(".commercialMargin").on("input.commercialMargin change.commercialMargin", inverse);
        $box.data("commercial-margin-initialized", true);
    }

    window.initializeCommercialMarginFields = function (container) {
        var $container = container ? $(container) : $(document);
        $container.find(".commercial-margin-fields").addBack(".commercial-margin-fields").each(function () { initializeMarginOne($(this)); });
    };

    function initializeItemComponents(container) {
        window.initializeFiscalItemEditors(container);
        window.initializeCommercialMarginFields(container);
    }

    $(function () { initializeItemComponents(document); });
    $(document).ajaxComplete(function () { initializeItemComponents($("#ajaxModalContent")); });
    $(document).on("shown.bs.modal.fiscalItem", "#ajaxModal", function () { initializeItemComponents($(this)); });
})(jQuery, window, document);
