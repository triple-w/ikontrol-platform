<?php
declare(strict_types=1);
namespace App\Services\Legacy\Fc2;

use App\Domain\Legacy\LegacySourceReference;
use App\Services\Legacy\LegacyImportRegistryService;
use RuntimeException;
use Throwable;

final class Fc2MasterDataPreviewImporter
{
    private LegacyImportRegistryService $registry;
    public function __construct(private $source, private $target)
    {
        $this->registry = new LegacyImportRegistryService($target);
    }

    public function run(string $rfc, int $ownerId, bool $execute): array
    {
        (new Fc2ConnectionGuard($this->source, $this->target))->verify($rfc, $ownerId);
        $issuer = (new Fc2IssuerSource($this->source))->findByRfc($rfc, $ownerId);
        $clients = iterator_to_array((new Fc2ClientSource($this->source))->iterateByOwner($ownerId));
        $products = iterator_to_array((new Fc2ProductSource($this->source))->iterateByOwner($ownerId));
        $series = (new Fc2SeriesSource($this->source))->allByOwner($ownerId);
        $summary = $this->analyze($issuer, $clients, $products, $series);
        if (!$execute) return $summary + ['mode' => 'dry-run'];

        $batch = $this->registry->startBatch(['source_system'=>'fc2','source_database'=>'fc2_migration_source','source_owner_id'=>(string)$ownerId,'source_owner_key'=>$rfc,'entity_scope'=>'master_data_preview','summary'=>$summary]);
        $this->registry->markRunning($batch);
        try {
            $this->target->transBegin();
            $this->importIssuer($batch, $issuer, $ownerId);
            $this->importClients($batch, $clients, $ownerId);
            $this->ensureReferencedSatCatalogs($ownerId);
            $this->importProducts($batch, $products, $ownerId);
            if ($this->target->transStatus() === false) throw new RuntimeException('Preview import transaction failed.');
            $this->target->transCommit();
            $this->registry->completeBatchWithWarnings($batch, $summary);
        } catch (Throwable $e) {
            $this->target->transRollback();
            $this->registry->failBatch($batch, 'Master-data preview import failed.');
            throw $e;
        }
        return $summary + ['mode'=>'execute','batch_id'=>$batch];
    }

    private function analyze($issuer,array $clients,array $products,array $series):array
    {
        $seen=[];$exact=0;$missingRegime=0;
        foreach($clients as$c){$d=$c->data;$key=implode('|',[$d['rfc'],$d['razon_social_comparison'],$d['codigo_postal'],mb_strtoupper((string)$d['pais'])]);if(isset($seen[$key]))$exact++;else$seen[$key]=1;if(trim((string)$d['regimen_fiscal'])==='')$missingRegime++;}
        $keys=[];$dupRows=0;$zero=0;$noDescription=0;
        foreach($products as$p){$d=$p->data;$k=mb_strtoupper(trim((string)$d['clave_interna']));$keys[$k]=($keys[$k]??0)+1;if((string)$d['precio']==='0.0000')$zero++;if(trim((string)$d['descripcion'])==='')$noDescription++;}
        foreach($products as$p)if(($keys[mb_strtoupper(trim((string)$p->data['clave_interna']))]??0)>1)$dupRows++;
        $seriesConflicts=[];$pairs=[];foreach($series as$s){$k=mb_strtoupper((string)$s->data['tipo']).'|'.mb_strtoupper((string)$s->data['serie']);if(isset($pairs[$k]))$seriesConflicts[]=$k;$pairs[$k]=1;}
        return ['issuer'=>['accounts'=>1,'profiles'=>1],'clients'=>['source_rows'=>count($clients),'destination_expected'=>count($clients)-$exact,'mappings_expected'=>count($clients),'exact_duplicates'=>$exact,'fiscal_incomplete'=>$missingRegime,'default_cfdi_use_missing'=>count($clients)],'products'=>['source_rows'=>count($products),'destination_expected'=>count($products),'mappings_expected'=>count($products),'duplicate_key_rows'=>$dupRows,'zero_price'=>$zero,'missing_description'=>$noDescription,'fiscal_incomplete'=>count($products)],'series'=>['source_rows'=>count($series),'imported'=>0,'conflicts'=>$seriesConflicts],'warnings'=>$exact+$missingRegime+$dupRows+$zero+$noDescription+count($seriesConflicts),'errors'=>0];
    }

    private function importIssuer(int $batch,$issuer,int $owner):void
    {
        $profileRef=new LegacySourceReference('fc2','users_perfil',(string)$owner,(string)$issuer->sourceId);
        $profileMapping=$this->registry->findMapping($profileRef);
        if($profileMapping && hash_equals((string)$profileMapping->source_hash,$this->registry->hashSnapshot($issuer->snapshot))){$this->registry->recordSkip($batch,$profileRef,$issuer->snapshot,['source unchanged']);return;}
        $d=$issuer->data;$now=get_current_utc_time();$company=$this->target->table('company')->where('deleted',0)->orderBy('id')->get(1)->getRowArray();if(!$company)throw new RuntimeException('Preview company baseline missing.');
        $address=$d['domicilio'];$this->target->table('company')->where('id',$company['id'])->update(['name'=>$d['razon_social'],'address'=>$this->address($address),'phone'=>(string)$address['telefono'],'vat_number'=>$d['rfc']]);
        $regime=$this->catalogId('sat_tax_regimes',(string)$d['regimen_fiscal']);$profile=['profile_type'=>'issuer','company_id'=>(int)$company['id'],'rfc'=>$d['rfc'],'legal_name'=>$d['razon_social'],'tax_regime_id'=>$regime,'fiscal_postal_code'=>$d['codigo_postal']?:null,'expedition_postal_code'=>$d['codigo_postal']?:null,'fiscal_street'=>$address['calle'],'fiscal_external_number'=>$address['no_ext'],'fiscal_internal_number'=>$address['no_int'],'fiscal_neighborhood'=>$address['colonia'],'fiscal_locality'=>$address['localidad'],'fiscal_municipality'=>$address['municipio'],'fiscal_state'=>$address['estado'],'fiscal_country_code'=>$this->country($address['pais']),'phone'=>$address['telefono'],'status'=>'incomplete','environment'=>'preview','created_at'=>$now,'updated_at'=>$now];
        $this->target->table('fiscal_profiles')->insert($profile);$profileId=(int)$this->target->insertID();
        $user=$this->source->table('users')->select('id,email,username,active')->where('id',$owner)->get(1)->getRowArray();
        $this->record($batch,'users',$owner,$owner,['id'=>(string)$owner,'email'=>$user['email']??null,'username'=>$user['username']??null,'active'=>(string)($user['active']??'')],'company',(string)$company['id'],[]);
        $this->record($batch,'users_perfil',$owner,$issuer->sourceId,$issuer->snapshot,'fiscal_profiles',(string)$profileId,['issuer profile intentionally incomplete; no CSD, PAC or series']);
    }

    private function importClients(int $batch,array $records,int $owner):void
    {
        $seen=[];$now=get_current_utc_time();$admin=(int)($this->target->table('users')->select('id')->orderBy('id')->get(1)->getRow()->id??1);
        foreach($records as$r){$d=$r->data;$key=implode('|',[$d['rfc'],$d['razon_social_comparison'],$d['codigo_postal'],mb_strtoupper((string)$d['pais'])]);$warnings=[];
            $ref=new LegacySourceReference('fc2','clientes',(string)$owner,(string)$r->sourceId);$mapped=$this->registry->findMapping($ref);if($mapped&&hash_equals((string)$mapped->source_hash,$this->registry->hashSnapshot($r->snapshot))){$this->registry->recordSkip($batch,$ref,$r->snapshot,['source unchanged']);continue;}
            if(isset($seen[$key])){$clientId=$seen[$key];$warnings[]='exact normalized duplicate mapped to existing preview client';}
            else{$row=['company_name'=>$d['razon_social']?:('CLIENTE FC2 #'.$r->sourceId),'type'=>'organization','address'=>$this->address($d['domicilio']),'city'=>$d['domicilio']['municipio']?:$d['domicilio']['localidad'],'state'=>$d['domicilio']['estado'],'zip'=>$d['codigo_postal'],'country'=>$d['pais'],'created_date'=>$now,'phone'=>$d['telefono'],'starred_by'=>'','group_ids'=>'','deleted'=>0,'is_lead'=>0,'lead_status_id'=>0,'owner_id'=>0,'created_by'=>$admin,'lead_source_id'=>0,'last_lead_status'=>'','vat_number'=>$d['rfc'],'stripe_customer_id'=>'','stripe_card_ending_digit'=>0,'disable_online_payment'=>0,'managers'=>''];$this->target->table('clients')->insert($row);$clientId=(int)$this->target->insertID();$seen[$key]=$clientId;
                $regime=$this->catalogId('sat_tax_regimes',(string)$d['regimen_fiscal']);if(!$regime)$warnings[]='missing or unresolved tax regime';$this->target->table('fiscal_profiles')->insert(['profile_type'=>'receiver','client_id'=>$clientId,'rfc'=>$d['rfc']?:null,'legal_name'=>$d['razon_social']?:null,'tax_regime_id'=>$regime,'fiscal_postal_code'=>$d['codigo_postal']?:null,'default_cfdi_use_id'=>null,'fiscal_street'=>$d['domicilio']['calle'],'fiscal_external_number'=>$d['domicilio']['no_ext'],'fiscal_internal_number'=>$d['domicilio']['no_int'],'fiscal_neighborhood'=>$d['domicilio']['colonia'],'fiscal_locality'=>$d['domicilio']['localidad'],'fiscal_municipality'=>$d['domicilio']['municipio'],'fiscal_state'=>$d['domicilio']['estado'],'fiscal_country_code'=>$this->country($d['pais']),'email'=>$d['email'],'phone'=>$d['telefono'],'status'=>'incomplete','environment'=>'preview','created_at'=>$now,'updated_at'=>$now]);}
            $warnings[]='default CFDI use intentionally unset';$this->record($batch,'clientes',$owner,$r->sourceId,$r->snapshot,'clients',(string)$clientId,$warnings);
        }
    }

    private function importProducts(int $batch,array $records,int $owner):void
    {
        $now=get_current_utc_time();$admin=(int)($this->target->table('users')->select('id')->orderBy('id')->get(1)->getRow()->id??1);
        foreach($records as$r){$d=$r->data;$warnings=['ObjetoImp and taxes intentionally unset'];$description=trim((string)$d['descripcion']);if($description===''){$description='PRODUCTO FC2 #'.$r->sourceId.' SIN DESCRIPCIÓN';$warnings[]='missing source description; temporary visible title assigned';}
            $ref=new LegacySourceReference('fc2','productos',(string)$owner,(string)$r->sourceId);$mapped=$this->registry->findMapping($ref);if($mapped&&hash_equals((string)$mapped->source_hash,$this->registry->hashSnapshot($r->snapshot))){$ps=$this->catalogId('sat_product_service_keys',(string)$d['clave_prod_serv']);$unit=$this->catalogId('sat_unit_keys',(string)$d['clave_unidad']);$this->target->table('item_fiscal_settings')->where('item_id',(int)$mapped->destination_id)->update(['sat_product_service_key_id'=>$ps,'sat_unit_key_id'=>$unit,'updated_at'=>$now]);$this->registry->recordSkip($batch,$ref,$r->snapshot,['source unchanged']);continue;}
            $this->target->table('items')->insert(['title'=>$description,'description'=>$d['observaciones'],'unit_type'=>$d['unidad_comercial']??'','rate'=>$d['precio']??'0.000000','files'=>'','show_in_client_portal'=>0,'category_id'=>1,'taxable'=>0,'sort'=>0,'deleted'=>0]);$id=(int)$this->target->insertID();
            $ps=$this->catalogId('sat_product_service_keys',(string)$d['clave_prod_serv']);$unit=$this->catalogId('sat_unit_keys',(string)$d['clave_unidad']);if(!$ps||!$unit)$warnings[]='SAT catalog key unresolved';
            $this->target->table('item_fiscal_settings')->insert(['item_id'=>$id,'item_type'=>'product','sat_product_service_key_id'=>$ps,'sat_unit_key_id'=>$unit,'commercial_unit'=>$d['unidad_comercial'],'tax_object_code_id'=>null,'fiscal_description'=>$description,'identification_number'=>$d['clave_interna'],'is_default'=>1,'status'=>'incomplete','created_by'=>$admin,'created_at'=>$now,'updated_at'=>$now,'deleted'=>0]);
            $this->record($batch,'productos',$owner,$r->sourceId,$r->snapshot,'items',(string)$id,$warnings);
        }
    }

    private function ensureReferencedSatCatalogs(int $owner):void
    {
        $now=get_current_utc_time();
        $productRows=$this->source->table('productos p')->distinct()->select('c.clave code,c.descripcion description')->join('clave_prod_serv c','c.id=p.clave_prod_serv_id')->where('p.users_id',$owner)->get()->getResultArray();
        foreach($productRows as$row)if(!$this->target->table('sat_product_service_keys')->where('code',$row['code'])->countAllResults())$this->target->table('sat_product_service_keys')->insert(['code'=>$row['code'],'description'=>$row['description'],'is_active'=>1,'source_version'=>'fc2-preview-reference','created_at'=>$now,'updated_at'=>$now]);
        $unitRows=$this->source->table('productos p')->distinct()->select('c.clave code,c.descripcion description')->join('clave_unidad c','c.id=p.clave_unidad_id')->where('p.users_id',$owner)->get()->getResultArray();
        foreach($unitRows as$row)if(!$this->target->table('sat_unit_keys')->where('code',$row['code'])->countAllResults())$this->target->table('sat_unit_keys')->insert(['code'=>$row['code'],'name'=>$row['description'],'description'=>$row['description'],'is_active'=>1,'source_version'=>'fc2-preview-reference','created_at'=>$now,'updated_at'=>$now]);
    }

    private function record(int$b,string$table,int$owner,int|string$id,array$snapshot,string$dest,string$destId,array$warnings):void{$ref=new LegacySourceReference('fc2',$table,(string)$owner,(string)$id);$existing=$this->registry->findMapping($ref);if($existing)$this->registry->recordUpdate($b,$ref,$snapshot,$dest,$destId,null,$warnings);else$this->registry->recordImport($b,$ref,$snapshot,$dest,$destId,null,$warnings);}
    private function catalogId(string$table,string$code):?int{$code=trim($code);if($code==='')return null;$row=$this->target->table($table)->select('id')->where('code',$code)->get(1)->getRow();return$row?(int)$row->id:null;}
    private function address(array$a):string{return trim(implode(' ',array_filter([$a['calle']??null,$a['no_ext']??null,$a['no_int']??null,$a['colonia']??null,$a['municipio']??null,$a['localidad']??null,$a['estado']??null,$a['codigo_postal']??null,$a['pais']??null],fn($v)=>trim((string)$v)!=='')));}
    private function country(?string$v):?string{$v=mb_strtoupper(trim((string)$v));return in_array($v,['MEX','MX','MEXICO','MÉXICO'],true)?'MEX':($v!==''&&strlen($v)===3?$v:null);}
}
