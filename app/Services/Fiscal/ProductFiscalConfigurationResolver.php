<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

/** Resolves the master product only when its complete fiscal configuration is usable. */
final class ProductFiscalConfigurationResolver
{
    public function __construct(private mixed $db = null) { $this->db ??= db_connect(); }

    public function resolve(int $productId): array
    {
        if ($productId < 1) return ['source'=>'manual_line','ready'=>false,'product_id'=>0,'setting'=>null,'taxes'=>[],'missing'=>['datos fiscales de la partida']];
        $setting=$this->db->table('item_fiscal_settings s')->select('s.*,p.code product_service_code,u.code unit_code,o.code tax_object_code')
            ->join('sat_product_service_keys p','p.id=s.sat_product_service_key_id','left')->join('sat_unit_keys u','u.id=s.sat_unit_key_id','left')->join('sat_tax_object_codes o','o.id=s.tax_object_code_id','left')
            ->where(['s.item_id'=>$productId,'s.is_default'=>1,'s.deleted'=>0])->orderBy('s.id','DESC')->get(1)->getRowArray();
        $taxes=[];
        if($setting)$taxes=$this->db->table('item_fiscal_taxes ft')->select('t.fiscal_tax_type tax_type,c.code tax_code,f.name factor_type,COALESCE(t.xml_rate,t.xml_quota) rate_or_quota,ft.sort_order calculation_order')
            ->join('taxes t','t.id=ft.tax_id')->join('sat_tax_codes c','c.id=t.sat_tax_code_id','left')->join('sat_tax_factor_types f','f.id=t.factor_type_id','left')
            ->where(['ft.item_fiscal_setting_id'=>$setting['id'],'ft.is_active'=>1,'t.deleted'=>0,'t.use_for_fiscal'=>1,'t.is_fiscal_ready'=>1])->orderBy('ft.sort_order')->get()->getResultArray();
        $assessment=(new ProductFiscalReadinessService())->evaluate($setting,$taxes);
        return ['source'=>'master_product','ready'=>$assessment['ready'],'product_id'=>$productId,'setting'=>$setting,'taxes'=>$taxes,'missing'=>$assessment['missing']];
    }
}
