"use strict";

const fs = require("fs");
const path = require("path");
const modal = require(path.resolve(__dirname, "../../assets/js/fiscal-cancellation-modal.js"));
let pass = 0;
let fail = 0;
function ok(value, message) {
    console.log((value ? "[PASS] " : "[FAIL] ") + message);
    value ? pass++ : fail++;
}

let state = "idle";
let start = modal.begin(state);
ok(start.accepted && start.state === "processing", "primer clic inicia el procesamiento");
state = start.state;
ok(modal.begin(state).accepted === false, "doble clic durante procesamiento queda bloqueado");

let outcome = modal.fromResponse({ success: true, message: "Solicitud recibida" });
ok(outcome.state === "complete" && outcome.retryAllowed === false, "respuesta exitosa cierra el ciclo sin reintento");

outcome = modal.fromResponse({ success: false, status: "blocked", retry_allowed: true, message: "Motivo inválido" });
ok(outcome.state === "retryable" && outcome.message === "Motivo inválido", "rechazo de validación definitivo libera el formulario");

outcome = modal.fromTransportError({ responseJSON: { success: false, status: "blocked", retry_allowed: true, message: "CSRF inválido" } });
ok(outcome.state === "retryable", "HTTP 4xx con JSON definitivo conserva su diagnóstico y permite corregir");

outcome = modal.fromTransportError({ status: 500 });
ok(outcome.state === "ambiguous" && outcome.retryAllowed === false, "HTTP 5xx sin JSON verificable bloquea reintento");

outcome = modal.fromTransportError({ status: 0 });
ok(outcome.state === "ambiguous" && outcome.message.includes("respuesta HTTP verificable"), "error de red se trata como resultado ambiguo");

outcome = modal.fromResponse({ success: false, status: "unknown", requires_reconciliation: true });
ok(outcome.state === "ambiguous" && outcome.retryAllowed === false, "respuesta fiscal ambigua exige conciliación");

delete global.unmaskModal;
ok(modal.fromResponse({ success: false, status: "blocked" }).state === "retryable", "controlador del modal no depende de una función global de desbloqueo");

const view = fs.readFileSync(path.resolve(__dirname, "../../app/Views/fiscal/invoices/cancel_form.php"), "utf8");
ok(view.includes(".done(function(response)") && view.includes(".fail(function(xhr)") && view.includes("function unlock()"), "integración local libera la máscara en HTTP 200, 4xx, 5xx y red");
ok(!view.includes("unmaskModal(") && !view.includes("ajaxError.fiscalCancellation"), "vista no llama APIs privadas de RISE ni usa handler global");

console.log(`TOTAL PASS=${pass} FAIL=${fail}; Node state-machine test, zero network and zero database.`);
process.exit(fail ? 1 : 0);
