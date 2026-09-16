<?php
namespace App\Commands;
use App\Services\Fiscal\Stamps\FiscalStampAccountService;use CodeIgniter\CLI\BaseCommand;use CodeIgniter\CLI\CLI;use RuntimeException;
final class FiscalStampsCredit extends BaseCommand{protected $group='Fiscal';protected $name='fiscal:stamps:credit';protected $description='Asigna timbres comerciales al wallet de un emisor.';protected $usage='fiscal:stamps:credit <issuer_id> <quantity> --reason=<reason> --actor=<id>';
 public function run(array$p):void{helper(['general','date_time']);$i=(int)($p[0]??0);$q=(int)($p[1]??0);$r=trim((string)CLI::getOption('reason'));$a=(int)CLI::getOption('actor');if($i<1||$q<1||$r===''||$a<1)throw new RuntimeException('issuer, quantity, reason y actor son obligatorios.');$m=(new FiscalStampAccountService())->allocate($i,$q,$r,$a,'admin-allocation:'.hash('sha256',"$i|$q|$r"));CLI::write("available_after: {$m->available_after}");CLI::write("reserved_after: {$m->reserved_after}");}}
