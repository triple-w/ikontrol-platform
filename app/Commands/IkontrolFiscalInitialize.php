<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Fiscal\FiscalInstallationInitializerService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use RuntimeException;

final class IkontrolFiscalInitialize extends BaseCommand
{
    protected $group='iKontrol';
    protected $name='ikontrol:fiscal-initialize';
    protected $description='Validates or applies the idempotent official SAT catalogs and safe fiscal baseline.';
    protected $usage='ikontrol:fiscal-initialize --expected-database=NAME [--group=default] [--apply] [--json]';
    public function run(array $params): int
    {
        $expected=(string)$this->option($params,'expected-database','');
        if($expected==='')throw new RuntimeException('--expected-database is required.');
        $group=(string)$this->option($params,'group','default');
        $apply=(bool)$this->option($params,'apply',false);
        $result=(new FiscalInstallationInitializerService(Database::connect($group,false)))->run($expected,!$apply);
        CLI::write(json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));
        return 0;
    }
    private function option(array $params,string $name,mixed $default=null):mixed
    {
        $native=CLI::getOption($name);if($native!==null)return $native;
        $args=array_values(array_filter(array_merge($params,$_SERVER['argv']??[]),'is_string'));
        foreach($args as$i=>$arg){if($arg==="--{$name}")return $args[$i+1]??true;if(str_starts_with($arg,"--{$name}="))return substr($arg,strlen($name)+3);}
        return $default;
    }
}
