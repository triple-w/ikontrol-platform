<?php

declare(strict_types=1);

require_once __DIR__.'/CanonicalFileHasher.php';

use Ikontrol\LegacyBootstrap\CanonicalFileHasher;

$root = dirname(__DIR__, 2);
$files = [
    'app/Config/Version.php' => 'canonical_version',
    'app/Commands/IkontrolVersion.php' => 'command',
    'app/Commands/IkontrolDatabaseCheck.php' => 'command',
    'app/Commands/IkontrolBaselineCheck.php' => 'command',
    'app/Commands/IkontrolAdoptBaseline.php' => 'command',
    'app/Commands/IkontrolUpgradePlan.php' => 'command',
    'app/Commands/IkontrolUpgrade.php' => 'command',
    'app/Services/Baseline/BaselineCheckResult.php' => 'baseline_dependency',
    'app/Services/Baseline/IkontrolBaselineCheckService.php' => 'baseline_dependency',
    'app/Services/Instance/InstanceVersionService.php' => 'version_dependency',
    'app/Services/Instance/InstanceIdentityService.php' => 'upgrade_dependency',
    'app/Services/Instance/InstanceFeaturesService.php' => 'upgrade_dependency',
    'app/Services/Upgrade/LegacyBaselineAdoptionService.php' => 'adoption_dependency',
    'app/Services/Upgrade/InstanceUpgradeService.php' => 'upgrade_dependency',
    'app/Services/Upgrade/FinancialAccountMovementsSchemaUpgrade.php' => 'upgrade_dependency',
    'app/Services/Upgrade/PaymentAllocationsSchemaUpgrade.php' => 'upgrade_dependency',
    'app/Services/Upgrade/ExpensesSchemaUpgrade.php' => 'upgrade_dependency',
    'app/Services/Upgrade/Legacy/PhysicalSchemaInspector.php' => 'upgrade_dependency',
    'app/Services/Fiscal/SatCatalogInfrastructureService.php' => 'upgrade_dependency',
    'app/Services/Fiscal/SatCatalogImporterService.php' => 'upgrade_dependency',
    'app/Services/Fiscal/SatCatalogTextNormalizer.php' => 'upgrade_dependency',
    'resources/fiscal/catalogs/sat/manifest.json' => 'catalog_manifest',
];

$entries = [];
foreach ($files as $path => $role) {
    $absolute = $root.'/'.$path;
    if (! is_file($absolute)) throw new RuntimeException('Bootstrap payload source is missing: '.$path);
    $entries[] = ['path'=>$path,'role'=>$role,'hash_mode'=>CanonicalFileHasher::mode($path),'sha256'=>CanonicalFileHasher::hashFile($absolute,$path),'bytes_on_build_host'=>filesize($absolute)];
}
$manifest = [
    'schema_version'=>2,
    'bootstrap_version'=>'legacy-bootstrap-1.1.5.1',
    'canonical_version'=>'1.1.5',
    'generated_at'=>'2026-10-08T00:00:00Z',
    'policy'=>['additive_only'=>true,'overwrite'=>false,'database_writes'=>false,'text_checksum'=>'normalize CRLF and CR to LF before SHA-256','binary_checksum'=>'SHA-256 over physical bytes'],
    'files'=>$entries,
];
$json = json_encode($manifest, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
if (in_array('--write', $_SERVER['argv'] ?? [], true)) {
    $target = $root.'/resources/upgrade/legacy-bootstrap/manifest.json';
    if (! is_dir(dirname($target)) && ! mkdir(dirname($target),0775,true) && ! is_dir(dirname($target))) throw new RuntimeException('Unable to create manifest directory.');
    if (file_put_contents($target,$json,LOCK_EX)===false) throw new RuntimeException('Unable to write bootstrap manifest.');
    fwrite(STDOUT,$target.PHP_EOL);
} else {
    fwrite(STDOUT,$json);
}
