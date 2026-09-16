<?php
declare(strict_types=1);

namespace App\Commands;

use App\Models\Invoice_items_model;
use App\Models\Invoices_model;
use App\Services\Fiscal\FiscalDraftWorkflowService;
use App\Services\Sales\SaleLifecycleService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

final class FiscalIntegrationCreateCandidate extends BaseCommand
{
    protected $group = 'Fiscal';
    protected $name = 'fiscal:integration:create-candidate';
    protected $description = 'Crea, mediante modelos y servicios normales, una venta cerrada y un borrador ready para PAC development.';

    public function run(array $params): void
    {
        helper(['general','date_time','currency','plugin']);
        require_once APPPATH.'ThirdParty/PHP-Hooks/php-hooks.php';
        $db = db_connect();
        try {
            $status=(new \App\Services\Fiscal\FiscalIntegrationStatusService($db))->inspect();
            if(!$status['ready']) throw new RuntimeException('La integración no supera fiscal:integration:status.');
            $existing=$db->table('fiscal_drafts')->where([
                'environment'=>'development','data_origin'=>'integration_manual_candidate','status'=>'ready',
            ])->where('fiscal_document_id',null)->orderBy('id','DESC')->get(1)->getRow();
            if($existing){
                CLI::write('Candidato existente reutilizado.','yellow');
                CLI::write('draft_id: '.$existing->id);
                return;
            }
            $user=(int)($db->table('users')->where(['is_admin'=>1,'deleted'=>0])->orderBy('id')->get(1)->getRow()->id??0);
            $receiver=$db->table('fiscal_profiles')->where(['profile_type'=>'receiver','environment'=>'development'])
                ->whereIn('status',['active','ready'])->get(1)->getRow();
            $issuer=$db->table('fiscal_profiles')->where(['profile_type'=>'issuer','environment'=>'development'])
                ->whereIn('status',['active','ready'])->get(1)->getRow();
            $series=$db->table('fiscal_series')->where([
                'issuer_profile_id'=>$issuer->id??0,'series'=>'TEST','environment'=>'development','is_active'=>1,'deleted'=>0,
            ])->get(1)->getRow();
            if(!$user||!$receiver||!$issuer||!$series) throw new RuntimeException('Faltan usuario, emisor, receptor o serie TEST.');
            $source=$db->table('invoices i')->select('i.*')->join('invoice_items ii','ii.invoice_id=i.id')
                ->join('item_fiscal_settings fs','fs.item_id=ii.item_id')
                ->where(['i.client_id'=>$receiver->client_id,'i.deleted'=>0,'ii.deleted'=>0,'fs.deleted'=>0])
                ->whereIn('fs.status',['active','ready'])->orderBy('i.id','DESC')->get(1)->getRowArray();
            if(!$source) throw new RuntimeException('No existe una venta fuente compatible; prepara cliente y producto desde la interfaz.');
            $sourceItem=$db->table('invoice_items ii')->select('ii.*')->join('item_fiscal_settings fs','fs.item_id=ii.item_id')
                ->where(['ii.invoice_id'=>$source['id'],'ii.deleted'=>0,'fs.deleted'=>0])->whereIn('fs.status',['active','ready'])->get(1)->getRowArray();
            if(!$sourceItem) throw new RuntimeException('No existe una partida fiscalmente configurada.');
            unset($source['id'],$source['created_at'],$source['closed_at'],$source['closed_by'],$source['cancelled_at'],$source['cancelled_by']);
            $today=date('Y-m-d');$source['bill_date']=$today;$source['due_date']=$today;$source['status']='not_paid';
            $source['commercial_status']='open';$source['display_id']='C231-TEST-'.date('YmdHis');
            $source['invoice_subtotal']='100.00';$source['discount_total']='0.00';$source['tax']='16.00';$source['tax2']='0.00';
            $source['tax3']='0.00';$source['invoice_total']='116.00';$source['deleted']=0;
            $saleId=(int)(new Invoices_model())->ci_save($source);
            unset($sourceItem['id'],$sourceItem['created_at']);$sourceItem['invoice_id']=$saleId;
            $sourceItem['quantity']='1';$sourceItem['rate']='100.00';$sourceItem['total']='100.00';$sourceItem['deleted']=0;
            $saleItemId=(int)(new Invoice_items_model())->ci_save($sourceItem);
            (new SaleLifecycleService($db))->close($saleId,$user,'Candidato controlado C2.3.1');
            $input=[
                'sale_ids'=>[$saleId],'quantities'=>[$saleItemId=>'1'],
                'issuer_id'=>(int)$issuer->id,'receiver_profile_id'=>(int)$receiver->id,
                'fiscal_series_id'=>(int)$series->id,
                'issue_date'=>(new DateTimeImmutable('now',new DateTimeZone('America/Mexico_City')))->format('Y-m-d\TH:i'),
                'currency_code'=>'MXN','exchange_rate'=>'1','payment_form_code'=>'99','payment_method_code'=>'PPD',
            ];
            $draft=(new FiscalDraftWorkflowService($db))->save($input,$user);
            $db->table('fiscal_drafts')->where('id',$draft['id'])->update(['data_origin'=>'integration_manual_candidate']);
            CLI::write('Candidato creado mediante flujo normal.','green');
            CLI::write('sale_id: '.$saleId);CLI::write('commercial_status: closed');CLI::write('payment_status: not_paid (campo status del modelo actual)');
            CLI::write('draft_id: '.$draft['id']);CLI::write('draft_status: '.$draft['status']);CLI::write('series: TEST');
        } catch(Throwable $error) {
            CLI::error($error->getMessage());
        }
    }
}
