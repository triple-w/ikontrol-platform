<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Fiscal\SatCatalogImporterService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

final class FiscalCatalogsUpdate extends BaseCommand
{
    protected $group = 'Fiscal';
    protected $name = 'fiscal:catalogs:update';
    protected $description = 'Imports the verified versioned SAT catalog manifest without deleting catalog rows.';
    protected $usage = 'fiscal:catalogs:update [--dry-run] [--catalog=product-service] [--force] [--json]';

    public function run(array $params): int
    {
        $options = CLI::getOptions();
        $catalog = $options['catalog'] ?? null;
        $flags = [
            'json' => array_key_exists('json', $options),
            'dry-run' => array_key_exists('dry-run', $options),
            'force' => array_key_exists('force', $options),
        ];
        $arguments = array_values(array_filter(array_merge($params, $_SERVER['argv'] ?? []), 'is_string'));
        foreach ($arguments as $offset => $param) {
            if (! is_string($param)) continue;
            if (preg_match('/^--catalog=(.+)$/', $param, $matches)) $catalog = $matches[1];
            elseif ($param === '--catalog' && isset($params[$offset + 1])) $catalog = $params[$offset + 1];
            elseif (isset($flags[ltrim($param, '-')])) $flags[ltrim($param, '-')] = true;
        }
        $json = $flags['json'];
        try {
            $result = (new SatCatalogImporterService())->update($catalog, $flags['dry-run'], $flags['force']);
            if ($json) CLI::write(json_encode($result, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            else foreach ($result['catalogs'] as $row) CLI::write($row['catalog_name'] . ': ' . http_build_query($row, '', ' '));
            return 0;
        } catch (\Throwable $e) {
            if ($json) CLI::write(json_encode(['error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)); else CLI::error($e->getMessage());
            return 2;
        }
    }
}
