# Onboarding de instancia

La instalación técnica y la activación operativa son pasos separados.

## Instalación técnica

- [ ] Crear una base MySQL vacía y configurar `.env` según [INSTALLATION.md](INSTALLATION.md).
- [ ] Ejecutar `ikontrol:install-canonical` y conservar la contraseña inicial fuera del repositorio.
- [ ] Ejecutar el smoke test de instalación limpia.
- [ ] Confirmar acceso del administrador y configurar roles/permisos iniciales.

## Activación operativa y fiscal

- [ ] Registrar datos de empresa, RFC, régimen fiscal y domicilio fiscal completos.
- [ ] Configurar impuestos comerciales, productos, clientes iniciales y métodos de pago.
- [ ] Crear perfiles fiscales y series separadas para ingreso, pago y egreso.
- [ ] Cargar CSD y contraseña CSD; almacenar las llaves de cifrado sólo en el entorno de la instancia.
- [ ] Configurar ambiente fiscal sandbox, credencial PAC sandbox y ejecutar una prueba fake/MOCK o sandbox autorizada.
- [ ] Registrar saldo/timbres y validar reservas mediante el flujo administrativo correspondiente.
- [ ] Configurar credencial PAC production y habilitar production sólo después de la validación sandbox y aprobación operativa explícita.
- [ ] Confirmar que el administrador, los roles y los permisos de timbrar, cancelar, descargar, suppliers y warehouses corresponden a las personas autorizadas.

No copie RFC, CSD, credenciales PAC, series, XML, PDF, usuarios, logos ni datos comerciales desde otra instancia.
