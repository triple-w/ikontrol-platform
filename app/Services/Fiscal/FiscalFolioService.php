<?php
declare(strict_types=1);
namespace App\Services\Fiscal;

use App\Models\Fiscal\Fiscal_series_model;
use RuntimeException;

class FiscalFolioService
{
    private $db;
    public function __construct($db=null){$this->db=$db?:db_connect();}

    public function getDefaultSeries(int $issuerId,string $type='ingreso'): ?object
    {
        $row=$this->db->table('fiscal_series')->where(['issuer_profile_id'=>$issuerId,'document_type'=>$type,'is_default'=>1,'is_active'=>1,'deleted'=>0])->get(1)->getRow();
        return$row?:null;
    }
    public function previewNextFolio(int $seriesId): array
    {
        $row=$this->db->table('fiscal_series')->where(['id'=>$seriesId,'is_active'=>1,'deleted'=>0])->get(1)->getRow();
        if(!$row)throw new RuntimeException('La serie fiscal no está activa.');
        return['series_id'=>(int)$row->id,'series'=>(string)$row->series,'folio'=>max((int)$row->initial_folio,(int)$row->current_folio+1)];
    }
    public function reserveNextFolio(int $seriesId,?callable $afterReserve=null): array
    {
        $this->db->transBegin();
        try{$table=$this->db->prefixTable('fiscal_series');$row=$this->db->query("SELECT * FROM {$table} WHERE id = ? AND is_active = 1 AND deleted = 0 FOR UPDATE",[$seriesId])->getRow();if(!$row)throw new RuntimeException('La serie fiscal no está activa.');$folio=max((int)$row->initial_folio,(int)$row->current_folio+1);$this->db->table('fiscal_series')->where('id',$seriesId)->update(['current_folio'=>$folio,'updated_at'=>get_current_utc_time()]);$result=['series_id'=>(int)$row->id,'series'=>(string)$row->series,'folio'=>$folio];if($afterReserve)$afterReserve($result);if($this->db->transStatus()===false)throw new RuntimeException('No fue posible reservar el folio fiscal.');$this->db->transCommit();return$result;}catch(\Throwable$e){$this->db->transRollback();throw$e;}
    }
}
