<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Release\ReleaseArtifactBuilder;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

final class IkontrolReleaseBuild extends BaseCommand
{
    protected $group = 'iKontrol';
    protected $name = 'ikontrol:release:build';
    protected $description = 'Builds and validates a reproducible iKontrolAdmin release artifact.';
    protected $usage = 'ikontrol:release:build --version=X.Y.Z [--output=dist] [--allow-dirty] [--json]';

    public function run(array $params): int
    {
        $options = CLI::getOptions();
        $version = is_string($options['version'] ?? null) ? $options['version'] : '';
        $output = is_string($options['output'] ?? null) ? $options['output'] : ROOTPATH . 'dist';
        $flags = ['json' => array_key_exists('json', $options), 'allow-dirty' => array_key_exists('allow-dirty', $options)];
        $arguments = array_values(array_filter(array_merge($params, $_SERVER['argv'] ?? []), 'is_string'));
        foreach ($arguments as $offset => $argument) {
            if (preg_match('/^--version=(.+)$/', $argument, $matches)) {
                $version = $matches[1];
            } elseif ($argument === '--version' && isset($arguments[$offset + 1])) {
                $version = $arguments[$offset + 1];
            } elseif (preg_match('/^--output=(.+)$/', $argument, $matches)) {
                $output = $matches[1];
            } elseif ($argument === '--output' && isset($arguments[$offset + 1])) {
                $output = $arguments[$offset + 1];
            } elseif (array_key_exists(ltrim($argument, '-'), $flags)) {
                $flags[ltrim($argument, '-')] = true;
            }
        }
        $json = $flags['json'];
        try {
            $result = (new ReleaseArtifactBuilder(rtrim(ROOTPATH, '/\\')))->build($version, $output, $flags['allow-dirty']);
            CLI::write($json ? json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : print_r($result, true));
            return 0;
        } catch (\Throwable $error) {
            $payload = ['status' => 'ERROR', 'error' => $error->getMessage()];
            $json ? CLI::write(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) : CLI::error($error->getMessage());
            return 2;
        }
    }
}
