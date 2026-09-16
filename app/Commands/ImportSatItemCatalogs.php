<?php
declare(strict_types=1);

namespace App\Commands;

use App\Services\Fiscal\SatItemCatalogImporter;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class ImportSatItemCatalogs extends BaseCommand
{
    protected $group = 'Fiscal';
    protected $name = 'fiscal:import-item-catalogs';
    protected $description = 'Imports ClaveProdServ and ClaveUnidad from a verified FactuCare database on the same server.';
    protected $usage = 'fiscal:import-item-catalogs --source factucare';
    protected $options = ['--source' => 'Source database containing clave_prod_serv and clave_unidad.'];

    public function run(array $params): void
    {
        if (ENVIRONMENT === 'production') {
            CLI::error('Catalog import is disabled in production; run it only after an approved deployment review.');
            return;
        }

        $source = (string) (CLI::getOption('source') ?: 'factucare');
        try {
            $result = (new SatItemCatalogImporter())->import($source);
            foreach ($result as $catalog => $stats) {
                CLI::write($catalog . ': ' . http_build_query($stats, '', ' '), 'green');
            }
        } catch (\Throwable $exception) {
            CLI::error($exception->getMessage());
        }
    }
}
