<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class IkontrolDatabaseCheck extends BaseCommand
{
    protected $group = 'iKontrol';
    protected $name = 'ikontrol:database-check';
    protected $description = 'Verifica la conexión configurada mediante una consulta de solo lectura.';

    public function run(array $params)
    {
        try {
            $database = \Config\Database::connect();
            $database->query('SELECT 1')->getRow();
            CLI::write("IKONTROL_JSON_BEGIN\n".json_encode(['status'=>'READY'])."\nIKONTROL_JSON_END", 'green');
        } catch (\Throwable) {
            CLI::error('ERROR');
            throw new \RuntimeException('No fue posible conectar con la base configurada.');
        }
    }
}