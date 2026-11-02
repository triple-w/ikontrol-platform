<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use CodeIgniter\Database\BaseConnection;
use RuntimeException;

final class FiscalInstallationInitializerService
{
    public function __construct(private BaseConnection $db, private ?string $catalogRoot = null) {}

    public function run(string $expectedDatabase, bool $dryRun = true): array
    {
        $this->assertTarget($expectedDatabase);
        $infrastructureService = new SatCatalogInfrastructureService($this->db);
        $infrastructure = $dryRun ? $infrastructureService->inspect() : $infrastructureService->apply();
        if (! $infrastructure['ready']) throw new RuntimeException('Fiscal catalog schema is incomplete; use the directed schema upgrade first.');
        $catalogs = (new SatCatalogImporterService($this->db, $this->catalogRoot))->update(null, $dryRun, false);
        $defaults = $this->defaults($dryRun);
        return ['database'=>$expectedDatabase,'dry_run'=>$dryRun,'catalogs'=>$catalogs['catalogs'],'defaults'=>$defaults];
    }

    private function defaults(bool $dryRun): array
    {
        foreach (['taxes','clients','fiscal_profiles','sat_tax_codes','sat_tax_factor_types','sat_tax_regimes','sat_cfdi_uses'] as $table) {
            if (! $this->db->tableExists($table)) throw new RuntimeException("Required initialization table missing: {$table}");
        }
        $taxCode = $this->ids('sat_tax_codes');
        $factor = $this->ids('sat_tax_factor_types');
        $regime = $this->ids('sat_tax_regimes');
        $uses = $this->ids('sat_cfdi_uses');
        foreach (['002'] as $code) if (!isset($taxCode[$code])) throw new RuntimeException("Required SAT tax code missing: {$code}");
        foreach (['Tasa','Exento'] as $code) if (!isset($factor[$code])) throw new RuntimeException("Required SAT factor missing: {$code}");
        if (!isset($regime['616'],$uses['S01'])) throw new RuntimeException('Generic receiver SAT keys 616/S01 are missing.');

        $changes=['taxes'=>[],'clients'=>[],'warnings'=>[
            'Retenciones e IEPS dependen de la operación y del contribuyente; no se asignan ni crean tasas universales automáticamente.',
            'Los receptores genéricos quedan draft hasta completar el código postal fiscal desde el emisor y, para extranjero, los datos reales aplicables.',
        ]];
        $taxes=[
            ['IKONTROL_BASELINE:IVA16','IVA 16% trasladado','16','transfer',$factor['Tasa'],'0.160000',null],
            ['IKONTROL_BASELINE:IVA0','IVA 0% trasladado','0','transfer',$factor['Tasa'],'0.000000',null],
            ['IKONTROL_BASELINE:IVAEXENTO','IVA exento','0','transfer',$factor['Exento'],null,null],
        ];
        foreach($taxes as [$marker,$title,$percentage,$type,$factorId,$rate,$quota]) {
            $exists=$this->db->table('taxes')->where('fiscal_notes',$marker)->countAllResults()>0;
            $changes['taxes'][]=['key'=>$marker,'action'=>$exists?'unchanged':'insert'];
            if(!$dryRun&&!$exists)$this->db->table('taxes')->insert(['title'=>$title,'percentage'=>$percentage,'deleted'=>0,'stripe_tax_id'=>'','sat_tax_code_id'=>$taxCode['002'],'fiscal_tax_type'=>$type,'factor_type_id'=>$factorId,'xml_rate'=>$rate,'xml_quota'=>$quota,'is_fiscal_ready'=>1,'use_for_administrative'=>1,'use_for_fiscal'=>1,'fiscal_notes'=>$marker,'updated_at'=>date('Y-m-d H:i:s')]);
        }
        $clients=[
            ['XAXX010101000','PUBLICO EN GENERAL','Público en General (México)',['PUBLICO EN GENERAL','Público en General (México)']],
            ['XEXX010101000',null,'Extranjero Genérico (completar datos fiscales)',['EXTRANJERO GENERICO','Extranjero Genérico (completar datos fiscales)']],
        ];
        foreach($clients as [$rfc,$legalName,$companyName,$aliases]) {
            $profile=$this->db->table('fiscal_profiles')->where(['rfc'=>$rfc,'profile_type'=>'receiver'])->get(1)->getRowArray();
            if($profile){$changes['clients'][]=['rfc'=>$rfc,'action'=>'unchanged'];continue;}
            $existingClient=$this->db->table('clients')->where('deleted',0)->whereIn('company_name',$aliases)->orderBy('id','ASC')->get(1)->getRowArray();
            $changes['clients'][]=['rfc'=>$rfc,'action'=>$existingClient?'attach_draft_profile':'insert_draft'];
            if($dryRun)continue;
            if($existingClient)$clientId=(int)$existingClient['id'];else{
                $this->db->table('clients')->insert(['company_name'=>$companyName,'type'=>'organization','created_date'=>date('Y-m-d H:i:s'),'starred_by'=>'','group_ids'=>'','deleted'=>0,'is_lead'=>0,'lead_status_id'=>0,'owner_id'=>0,'created_by'=>0,'sort'=>0,'lead_source_id'=>0,'last_lead_status'=>'','stripe_customer_id'=>'','stripe_card_ending_digit'=>0,'currency'=>'MXN','disable_online_payment'=>1,'managers'=>'']);
                $clientId=(int)$this->db->insertID();
            }
            $this->db->table('fiscal_profiles')->insert(['profile_type'=>'receiver','client_id'=>$clientId,'rfc'=>$rfc,'legal_name'=>$legalName,'tax_regime_id'=>$regime['616'],'fiscal_postal_code'=>null,'default_cfdi_use_id'=>$uses['S01'],'is_default'=>1,'status'=>'draft','created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);
        }
        return $changes;
    }

    private function ids(string $table): array { $out=[]; foreach($this->db->table($table)->select('id,code')->where('is_active',1)->get()->getResultArray() as $row)$out[(string)$row['code']]=(int)$row['id']; return $out; }
    private function assertTarget(string $expected): void
    {
        if($expected===''||$this->db->getDatabase()!==$expected)throw new RuntimeException('The selected database does not match --expected-database.');
        if($this->db->DBDriver!=='SQLite3'){
            $selected=(string)($this->db->query('SELECT DATABASE() name')->getRow()->name??'');
            if($selected!==$expected)throw new RuntimeException('Database connection identity changed.');
        }
    }
}
