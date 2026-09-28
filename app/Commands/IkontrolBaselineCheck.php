<?php

namespace App\Commands;

use App\Services\Baseline\IkontrolBaselineCheckService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

final class IkontrolBaselineCheck extends BaseCommand
{
    protected $group = 'iKontrol';
    protected $name = 'ikontrol:baseline-check';
    protected $description = 'Ejecuta un diagnóstico de baseline de solo lectura sin ejecutar migraciones ni alterar datos.';

    public function run(array $params): int
    {
        $json = in_array('--json', $params, true) || in_array('-j', $params, true);
        $service = new IkontrolBaselineCheckService();
        $result = $service->run();

        if ($json) {
            CLI::write("IKONTROL_JSON_BEGIN\n" . $service->jsonPayload() . "\nIKONTROL_JSON_END");

            return $result['summary']['fail'] > 0 ? 1 : 0;
        }

        CLI::write('Baseline status: ' . strtoupper((string) ($result['status'] ?? 'FAIL')));

        foreach ($result['checks'] as $check) {
            $tag = strtoupper((string) ($check['status'] ?? 'FAIL'));
            CLI::write(sprintf('[%s] %s - %s', $tag, $check['group'], $check['message']));
        }

        return $result['summary']['fail'] > 0 ? 1 : 0;
    }
}
