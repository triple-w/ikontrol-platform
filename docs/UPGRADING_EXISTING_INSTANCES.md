# Actualización de instancias existentes

No use `php spark migrate` global, `db:build-clean` ni una base limpia encima de una instancia cliente.

1. Tome un backup verificable de la base y de los artefactos fiscales antes de cualquier cambio.
2. Revise el estado de schema, historial y datos con el ledger P02. Clasifique cada precondición antes de escribir.
3. Aplique únicamente migraciones aditivas o correctivas mediante runner dirigido y valide la postcondición de cada paquete.
4. Preserve UUID, XML, PDF, pagos, allocations, series, folios y documentos históricos. Nunca reconstruya evidencia fiscal desde catálogos actuales.
5. Valide después de cada paquete: proposal, supplier costs, warehouse, fiscal sandbox MOCK, complementos, PDF/XML y permisos.
6. Si falla una operación, deténgase, conserve evidencia y restaure el backup. No manipule el historial de `migrations` para simular éxito.

Las migraciones históricas con conciliación de datos, vaciado o truncado son antecedentes de instalación nueva y no pasos automáticos de actualización.
