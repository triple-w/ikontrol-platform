<?php
declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use RuntimeException;

final class FiscalIntegrationReconcileInfo extends BaseCommand
{
    protected $group='Fiscal';
    protected $name='fiscal:integration:reconcile-info';
    protected $description='Muestra datos sanitizados para localizar un CFDI sin consultar ni reenviar al PAC.';
    protected $usage='fiscal:integration:reconcile-info <document_id>';

    public function run(array $params):void
    {
        $id=filter_var($params[0]??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if(!$id)throw new RuntimeException('Indica un document_id válido.');
        $db=db_connect();
        CLI::write('database: '.$db->getDatabase());
        $row=$db->table('fiscal_documents d')->select('d.id,d.source_draft_id,d.series,d.folio,d.total,d.issue_date,d.status,d.environment,i.rfc issuer_rfc,r.rfc receiver_rfc,a.id attempt_id,a.status attempt_status,a.started_at,a.sent_at,a.responded_at,a.idempotency_key,a.request_hash,a.provider,a.environment attempt_environment,a.requires_reconciliation')
            ->join('fiscal_document_issuers i','i.fiscal_document_id=d.id','left')->join('fiscal_document_receivers r','r.fiscal_document_id=d.id','left')
            ->join('fiscal_stamp_attempts a','a.fiscal_document_id=d.id','left')->where('d.id',(int)$id)->orderBy('a.id','DESC')->get(1)->getRow();
        if(!$row){CLI::error('DOCUMENT_NOT_FOUND_IN_ACTIVE_DATABASE');return;}
        foreach(['id','source_draft_id','series','folio','issuer_rfc','receiver_rfc','total','issue_date','status','environment','attempt_id','attempt_status','provider','attempt_environment','started_at','sent_at','responded_at','idempotency_key','request_hash','requires_reconciliation'] as $field){CLI::write($field.': '.($row->{$field}??'—'));}
        CLI::write('automatic_resend: no');
    }
}
