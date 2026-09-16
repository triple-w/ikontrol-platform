<?php

header('Content-Type: text/plain; charset=UTF-8');

echo 'SAPI: ' . PHP_SAPI . PHP_EOL;
echo 'PHP: ' . PHP_VERSION . PHP_EOL;
echo 'php.ini: ' . (php_ini_loaded_file() ?: 'No detectado') . PHP_EOL;
echo 'SOAP extension: ' . (extension_loaded('soap') ? 'SI' : 'NO') . PHP_EOL;
echo 'SoapClient class: ' . (class_exists(\SoapClient::class) ? 'SI' : 'NO') . PHP_EOL;
echo 'Timezone: ' . date_default_timezone_get() . PHP_EOL;