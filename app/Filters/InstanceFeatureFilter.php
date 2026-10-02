<?php

declare(strict_types=1);

namespace App\Filters;

use App\Services\Instance\InstanceFeaturesService;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/** Route boundary used as `instancefeature:feature_name`. */
final class InstanceFeatureFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $feature = (string) ($arguments[0] ?? '');
        if ($feature === '' || (new InstanceFeaturesService(db_connect()))->enabled($feature)) return null;
        return Services::response()->setStatusCode(404)->setJSON(['error' => 'INSTANCE_FEATURE_DISABLED', 'feature' => $feature]);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
