<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Seeder;
use Config\Database;
use RuntimeException;

abstract class ExplicitConnectionSeeder extends Seeder
{
    public function __construct(Database $config, ?BaseConnection $db = null)
    {
        if (! $db instanceof BaseConnection) {
            throw new RuntimeException(static::class . ' requires an explicit database connection.');
        }
        $this->config = $config;
        $this->seedPath = rtrim($config->filesPath ?? APPPATH . 'Database/', '\\/') . '/Seeds/';
        $this->db = $db;
        $this->forge = Database::forge($db);
    }
}
