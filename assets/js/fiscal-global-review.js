(function (window, $) {
    "use strict";

    window.FiscalGlobalReview = {
        init: function (options) {
            $(function () {
                window.setTimeout(function () {
                    var form = $("#fiscal-review-form");
                    if (!form.length || form.data("global-review-v2")) return;
                    form.data("global-review-v2", true);

                    var draftId = Number(options.draftId || 0);
                    var pending = null;
                    var sequence = 0;
                    var dirty = draftId < 1;
                    var failed = false;
                    var persisted = null;
                    var status = $("#fiscal-global-save-status");
                    if (!status.length) status = $('<div id="fiscal-global-save-status" class="mt10 small" role="status" aria-live="polite"></div>').insertBefore("#fiscal-review-message");

                    function message(text, error) {
                        status.toggleClass("text-danger", !!error).toggleClass("text-success", !error).text(text);
                    }
                    function updateCsrf(response) {
                        if (response && response.csrf && response.csrf.name) form.find('input[name="' + response.csrf.name + '"]').val(response.csrf.hash);
                    }
                    function values() {
                        return {
                            global_periodicity: String(form.find('[name="global_periodicity"]').val() || ""),
                            global_months: String(form.find('[name="global_months"]').val() || ""),
                            global_year: String(form.find('[name="global_year"]').val() || "")
                        };
                    }
                    function same(left, right) {
                        return left && right && String(left.global_periodicity || "") === right.global_periodicity && String(left.global_months || "") === right.global_months && String(left.global_year || "") === right.global_year;
                    }
                    function save() {
                        var requestValues = values();
                        var mine = ++sequence;
                        dirty = false;
                        failed = false;
                        message("Guardando...", false);
                        $("#execute-fiscal-invoice,#show-fiscal-confirmation").prop("disabled", true);
                        if (pending && pending.readyState !== 4) pending.abort();
                        pending = $.ajax({url: form.attr("action"), type: "POST", dataType: "json", data: form.serialize()});
                        pending.done(function (response) {
                            if (mine !== sequence) return;
                            updateCsrf(response);
                            if (!response.success || !same(response.data && response.data.global_information, requestValues)) {
                                failed = true;
                                message(response.message || "El servidor no confirmó los valores guardados.", true);
                                return;
                            }
                            draftId = Number(response.data.id || draftId);
                            persisted = requestValues;
                            form.attr("action", options.updateBase + draftId);
                            var rendered = response.html ? $("<div>").html(response.html) : null;
                            var ready = rendered && rendered.find("#show-fiscal-confirmation").length && !rendered.find("#show-fiscal-confirmation").prop("disabled");
                            message("Guardado", false);
                            $("#show-fiscal-confirmation").prop("disabled", !ready);
                        }).fail(function (xhr, textStatus) {
                            if (textStatus === "abort" || mine !== sequence) return;
                            failed = true;
                            message("Error de guardado HTTP/CSRF (" + xhr.status + ").", true);
                        });
                        return pending;
                    }
                    function confirmPersisted() {
                        if (dirty || !draftId || !same(persisted, values())) return save();
                        return pending;
                    }
                    function execute(button) {
                        if (failed || !draftId || !same(persisted, values())) {
                            message("No se puede facturar hasta confirmar el guardado.", true);
                            return;
                        }
                        button.prop("disabled", true).text("Generando factura...");
                        var csrf = form.find('input[name="' + options.csrfName + '"]').serialize();
                        $.ajax({url: options.draftFlowBase + draftId + "/invoice-flow", type: "POST", dataType: "json", data: csrf}).done(function (response) {
                            updateCsrf(response);
                            if (response.success) {
                                appAlert.success(response.message);
                                $("#ajaxModal").modal("hide");
                                window.location = response.redirect_url;
                                return;
                            }
                            button.prop("disabled", false).text("Confirmar y facturar");
                            $("#fiscal-review-message").html('<div class="alert alert-danger"></div>').find(".alert").text(response.message || "No fue posible preparar la factura.");
                        }).fail(function (xhr) {
                            button.prop("disabled", false).text("Confirmar y facturar");
                            $("#fiscal-review-message").html('<div class="alert alert-danger">Error HTTP/CSRF ' + xhr.status + '. No se realizó el timbrado.</div>');
                        });
                    }

                    form.find('[name="global_periodicity"],[name="global_months"]').off(".globalSave").on("change.globalReview", function () { dirty = true; save(); });
                    form.find('[name="global_year"]').off(".globalSave").on("input.globalReview", function () { dirty = true; message("Cambios pendientes", false); }).on("blur.globalReview", save);
                    $("#execute-fiscal-invoice").off("click").on("click.globalReview", function (event) {
                        event.preventDefault();
                        event.stopImmediatePropagation();
                        var button = $(this);
                        var request = confirmPersisted();
                        if (request && request.readyState !== 4) request.always(function () { execute(button); }); else execute(button);
                    });
                    if (draftId) persisted = values();
                }, 0);
            });
        }
    };
})(window, jQuery);
