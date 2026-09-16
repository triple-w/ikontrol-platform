# C2.3.0-R1 — comprobación DOM real

No hay Node.js, jsdom, Playwright, Selenium ni navegador headless disponible en el entorno CLI auditado. Por ello no se simuló un DOM incompleto como sustituto de navegador.

Comprobación manual obligatoria en Proposal, Estimate e Invoice:

1. Abrir por AJAX una partida nueva y confirmar que `window.initializeFiscalItemEditors` existe.
2. Dejar Margen vacío, capturar costo/precio y guardar.
3. Seleccionar un producto configurado; confirmar ObjetoImp, modalidad e impuestos.
4. Pulsar Agregar impuesto una vez: debe aparecer exactamente una fila.
5. Pulsarlo otra vez: deben existir exactamente dos filas.
6. Cerrar y reabrir; los impuestos persistidos deben reaparecer.
7. Ejecutar dos veces `initializeFiscalItemEditors($('#ajaxModalContent'))` y pulsar Agregar impuesto: debe agregarse sólo una fila.
8. Repetir con una línea libre (`item_id=0`) antes de su primer guardado.

La aprobación final queda reservada al usuario que ejecuta estas pruebas en la instancia real.
