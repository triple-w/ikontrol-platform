<?php

declare(strict_types=1);

define('ROOTPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('FCPATH', ROOTPATH);
define('TESTPATH', __DIR__ . DIRECTORY_SEPARATOR);

require ROOTPATH . 'app/Config/Paths.php';

$paths = new Config\Paths();

define('APPPATH', realpath($paths->appDirectory) . DIRECTORY_SEPARATOR);
define('SYSTEMPATH', realpath($paths->systemDirectory) . DIRECTORY_SEPARATOR);
define('WRITEPATH', realpath($paths->writableDirectory) . DIRECTORY_SEPARATOR);
define('ENVIRONMENT', 'testing');

require $paths->systemDirectory . '/Boot.php';

CodeIgniter\Boot::bootTest($paths);
