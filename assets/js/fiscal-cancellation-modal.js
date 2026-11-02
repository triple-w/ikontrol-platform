(function (root, factory) {
    var api = factory();
    if (typeof module === "object" && module.exports) {
        module.exports = api;
    } else {
        root.FiscalCancellationModal = api;
    }
}(typeof self !== "undefined" ? self : this, function () {
    "use strict";

    function begin(state) {
        if (state === "processing" || state === "ambiguous") {
            return { state: state, accepted: false };
        }
        return { state: "processing", accepted: true };
    }

    function fromResponse(response) {
        response = response || {};
        if (response.success === true) {
            return { state: "complete", retryAllowed: false, message: response.message || "" };
        }
        if (response.status === "unknown" || response.requires_reconciliation === true || response.retry_allowed === false) {
            return { state: "ambiguous", retryAllowed: false, message: response.message || "El resultado requiere conciliación." };
        }
        return { state: "retryable", retryAllowed: true, message: response.message || "No fue posible solicitar la cancelación." };
    }

    function fromTransportError(xhr) {
        var response = xhr && xhr.responseJSON;
        if (response) {
            return fromResponse(response);
        }
        return {
            state: "ambiguous",
            retryAllowed: false,
            message: "No se recibió una respuesta HTTP verificable. Revise el diagnóstico antes de volver a intentar."
        };
    }

    return { begin: begin, fromResponse: fromResponse, fromTransportError: fromTransportError };
}));
