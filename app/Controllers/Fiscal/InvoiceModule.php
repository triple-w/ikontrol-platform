<?php
declare(strict_types=1);

namespace App\Controllers\Fiscal;

use App\Controllers\Security_Controller;
use App\Services\Fiscal\FiscalInvoiceCenterQueryService;
use App\Services\Fiscal\FiscalIssuerResolver;
use App\Services\Fiscal\Pdf\FiscalPdfTemplateResolver;
use CodeIgniter\Exceptions\PageNotFoundException;
use Throwable;
use App\Services\Fiscal\Stamps\FiscalStampBalanceService;

final class InvoiceModule extends Security_Controller
{
    public function index()
    {
        $this->guardAny(['fiscal.invoices.view','fiscal_invoices_view']);
        $db=db_connect();
        $companyId=function_exists('get_default_company_id')?(int)get_default_company_id():null;
        $issuer=(new FiscalIssuerResolver($db))->resolve($companyId?:null);
        $stampBalance=$issuer?(new FiscalStampBalanceService($db))->forIssuer((int)$issuer->id):['available'=>0,'reserved'=>0,'usable'=>0];
        return $this->template->rander('fiscal/invoices/module_index', [
            'advanced_view' => $this->allowed('fiscal.advanced.view'),
            'stamp_balance' => $stampBalance,
            'can_view_stamp_balance' => $this->allowed('fiscal.stamps.view_balance'),
            'can_regenerate_pdf' => $this->allowed('fiscal_pdf_generate') && $this->allowed('fiscal.advanced.regenerate_pdf'),
            'configure_pdf_allowed' => $this->allowed('fiscal_pdf_templates_manage'),
        ]);
    }

    public function show($documentId = 0)
    {
        $this->guardAny(['fiscal.invoices.view','fiscal_invoice_view']);
        $data = (new FiscalInvoiceCenterQueryService())->detail((int) $documentId);
        if (!$data || !$this->canAccess()) {
            throw PageNotFoundException::forPageNotFound();
        }
        if ((int)($data['document']->is_test_fixture ?? 0) === 1
            && !$this->allowed('fiscal.advanced.view')) {
            throw PageNotFoundException::forPageNotFound();
        }
        return $this->template->rander('fiscal/invoices/show', $data + [
            'permissions' => [
                'xml_download' => $this->allowed('fiscal_xml_download'),
                'pdf_generate' => $this->allowed('fiscal_pdf_generate'),
                'pdf_view' => $this->allowed('fiscal_pdf_view'),
                'pdf_download' => $this->allowed('fiscal_pdf_download'),
                'pdf_regenerate' => $this->allowed('fiscal_pdf_generate') && $this->allowed('fiscal.advanced.regenerate_pdf'),
                'pdf_templates_manage' => $this->allowed('fiscal_pdf_templates_manage'),
                'receipt_view' => $this->allowed('fiscal_cancellation_receipt_view'),
                'cancel' => $this->allowed('fiscal_cancel_request'),
                'status_query' => $this->allowed('fiscal_status_query'),
                'advanced_view' => $this->allowed('fiscal.advanced.view'),
            ],
        ]);
    }

    public function listData(): void
    {
        $this->guardAny(['fiscal.invoices.view','fiscal_invoices_view']);
        $filters = [];
        foreach (['search','series','folio','uuid','client','rfc','date_from','date_to','type','status','pdf_status','cancellation_status'] as $key) {
            $filters[$key] = $this->request->getPost($key);
        }
        $data = [];
        $advanced = $this->allowed('fiscal.advanced.view');
        foreach ((new FiscalInvoiceCenterQueryService())->search($filters,250,0,$advanced) as $row) {
            if (!$this->canAccess()) continue;
            if (!$advanced) {
                $data[] = [
                    esc(trim($row->series.' '.$row->folio)),
                    esc($this->dateEs((string)$row->issue_date)),
                    esc($row->receiver_name ?: '-'),
                    esc($row->related_sales ?: 'Sin venta relacionada'),
                    esc($this->shortUuid((string)($row->uuid??''))),
                    to_currency($row->total),
                    $this->normalInvoiceBadge((string)$row->visible_status, (string)$row->cancellation_status)
                        .($row->environment==='development'?'<br><span class="badge bg-warning text-dark">CFDI de prueba</span>':''),
                    $this->actions($row, false),
                ];
                continue;
            }
            $data[] = [
                esc($row->series).((int)$row->is_imported_fixture===1?' <span class="badge bg-info">Prueba importada</span>':''),
                esc($row->folio), esc($this->typeLabel((string) $row->document_type)),
                esc($this->dateEs((string)$row->issue_date)), esc($row->receiver_name ?: '-'), esc($row->receiver_rfc ?: '-'),
                to_currency($row->total), esc($this->shortUuid((string)($row->uuid??''))),
                $this->badge('fiscal', (string) $row->visible_status),
                $this->badge('pdf', (string) ($row->pdf_status ?: 'pending'))
                    .($row->pdf_available ? '<br><small>'.esc($this->pdfArtifactLabel((string) ($row->pdf_provider ?? ''))).'</small>' : ''),
                $this->badge('cancellation', (string) $row->cancellation_status),
                $this->actions($row, true),
            ];
        }
        echo json_encode(['data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function actions(object $row, bool $advanced): string
    {
        $items = [];
        if ($this->allowed('fiscal_invoice_view')) $items[] = anchor(get_uri('fiscal/invoices/'.$row->id), 'Ver factura');
        if ($row->xml_available && $this->allowed('fiscal_xml_download')) $items[] = anchor(get_uri('fiscal/stamping/xml/download/'.$row->id), 'Descargar XML');
        if (!$advanced) {
            if ($row->pdf_available && $this->allowed('fiscal_pdf_download')) $items[] = anchor(get_uri('fiscal/documents/'.$row->id.'/pdf/download'), 'Descargar PDF');
            if ($row->xml_available && !empty($row->uuid) && $this->allowed('fiscal_pdf_generate') && $this->allowed('fiscal.advanced.regenerate_pdf')) {
                try { $template = (new FiscalPdfTemplateResolver())->resolve((int)$row->issuer_profile_id, (string)config('FiscalPdfProvider')->provider, $this->typeCode((string)$row->document_type))->templateCode; } catch (Throwable) { $template = '-'; }
                $items[] = js_anchor('Regenerar PDF', ['class'=>'fiscal-regenerate-pdf','data-document-id'=>$row->id,'data-document-label'=>trim($row->series.' '.$row->folio),'data-uuid'=>$row->uuid,'data-template'=>$template]);
            }
            $items[] = '<span class="text-muted" title="Disponible en un incremento posterior">Enviar</span>';
            if ($this->allowed('fiscal_cancel_request')&&$this->canCancel($row)) $items[] = modal_anchor(get_uri('fiscal/invoices/cancel/form'),'Cancelar',['data-post-document_id'=>$row->id]);
            if ($this->allowed('fiscal_status_query')&&$row->cancellation_request_id&&$row->cancellation_status!=='cancelled') $items[] = modal_anchor(get_uri('fiscal/invoices/cancellation/status/form'),'Consultar estatus',['data-post-document_id'=>$row->id]);
            if ($row->cancellation_ack_available&&$this->allowed('fiscal_cancellation_receipt_view')) $items[] = anchor(get_uri('fiscal/invoices/cancellation/ack/'.$row->cancellation_request_id),'Ver acuse');
            if ($row->related_sales) $items[] = anchor(get_uri('fiscal/invoices/'.$row->id), 'Ver venta o ventas relacionadas');
            return '<div class="dropdown"><button class="btn btn-default btn-sm dropdown-toggle" data-bs-toggle="dropdown">Acciones</button><div class="dropdown-menu dropdown-menu-end">'
                .implode('', array_map(static fn($item) => '<div class="dropdown-item">'.$item.'</div>', $items)).'</div></div>';
        }
        $pdfConfig = config('FiscalPdfProvider');
        $provider = (string) $pdfConfig->provider;
        if ($this->allowed('fiscal_pdf_generate')
            && (!$row->pdf_available || $this->allowed('fiscal.advanced.regenerate_pdf'))) {
            $providerLabel = $this->pdfProviderLabel($provider);
            $actionLabel = $row->pdf_available ? 'Regenerar PDF' : 'Generar PDF';
            try {
                $template = (new FiscalPdfTemplateResolver())->resolve(
                    (int) $row->issuer_profile_id,
                    $provider,
                    $this->typeCode((string) $row->document_type)
                )->templateCode;
            } catch (Throwable) { $template = '-'; }
            $items[] = js_anchor($actionLabel, [
                'class'=>'fiscal-generate-pdf','data-document-id'=>$row->id,'data-series'=>$row->series,
                'data-folio'=>$row->folio,'data-uuid'=>$row->uuid,'data-template'=>$template,
                'data-provider-label'=>$providerLabel,'data-action-label'=>$actionLabel,
                'data-regenerate'=>$row->pdf_available?1:0,
            ]);
        }
        if ($row->pdf_available && $this->allowed('fiscal_pdf_view')) $items[] = anchor(get_uri('fiscal/documents/'.$row->id.'/pdf/preview'), 'Ver PDF', ['target'=>'_blank']);
        if ($row->pdf_available && $this->allowed('fiscal_pdf_download')) $items[] = anchor(get_uri('fiscal/documents/'.$row->id.'/pdf/download'), 'Descargar PDF');
        if ($this->allowed('fiscal_cancel_request')&&$this->canCancel($row)) $items[] = modal_anchor(get_uri('fiscal/invoices/cancel/form'),'Cancelar',['data-post-document_id'=>$row->id]);
        if ($row->cancellation_ack_available && $this->allowed('fiscal_cancellation_receipt_view')) $items[] = anchor(get_uri('fiscal/invoices/cancellation/ack/'.$row->cancellation_request_id), 'Ver acuse');
        if ($this->allowed('fiscal_status_query')&&$row->cancellation_request_id&&$row->cancellation_status!=='cancelled') $items[] = modal_anchor(get_uri('fiscal/invoices/cancellation/status/form'),'Consultar estatus',['data-post-document_id'=>$row->id]);
        return '<div class="dropdown"><button class="btn btn-default btn-sm dropdown-toggle" data-bs-toggle="dropdown">Acciones</button><div class="dropdown-menu dropdown-menu-end">'
            .implode('', array_map(static fn($item) => '<div class="dropdown-item">'.$item.'</div>', $items)).'</div></div>';
    }

    private function badge(string $group, string $status): string
    {
        $maps = [
            'fiscal'=>['draft'=>['Borrador','secondary'],'signed'=>['Listo para timbrar','info'],'processing'=>['Enviando','warning'],'stamped'=>['Timbrado','success'],'stamped_pdf_pending'=>['Timbrado','success'],'stamped_pdf_error'=>['Timbrado','success'],'stamped_pdf_processing'=>['Timbrado','success'],'stamped_pdf_unknown'=>['Timbrado','success'],'correctable_error'=>['Error','danger'],'unknown'=>['Resultado desconocido','dark'],'cancelled'=>['Cancelado','secondary']],
            'pdf'=>['pending'=>['Pendiente','secondary'],'processing'=>['Procesando','warning'],'valid'=>['Disponible','success'],'error'=>['Error','danger'],'unknown'=>['Desconocido','dark']],
            'cancellation'=>['none'=>['No solicitada','secondary'],'requested'=>['Pendiente','warning'],'pending'=>['Pendiente','warning'],'accepted'=>['Cancelada','secondary'],'rejected'=>['Rechazada','danger'],'cancelled'=>['Cancelada','secondary'],'unknown'=>['Verificando','info']],
        ];
        [$label,$color] = $maps[$group][$status] ?? [ucfirst(str_replace('_',' ',$status)),'secondary'];
        return '<span class="badge bg-'.$color.'">'.esc($label).'</span>';
    }

    private function canAccess(): bool { return $this->allowed('fiscal_invoices_view') || $this->allowed('fiscal_invoice_view'); }
    private function allowed(string $permission): bool { if ($this->login_user->is_admin) return true; $all=is_array($this->login_user->permissions)?$this->login_user->permissions:(@unserialize((string)$this->login_user->permissions)?:[]); return (bool)get_array_value($all,$permission); }
    private function guard(string $permission): void { if (!$this->allowed($permission)) app_redirect('forbidden'); }
    private function guardAny(array $permissions): void { foreach($permissions as$permission)if($this->allowed($permission))return;app_redirect('forbidden'); }
    private function typeCode(string $type): string { return match(strtolower($type)){'income','i'=>'I','expense','e'=>'E','payment','p'=>'P','transfer','t'=>'T','payroll','n'=>'N',default=>strtoupper(substr($type,0,1))}; }
    private function typeLabel(string $type): string { return match($this->typeCode($type)){'I'=>'Ingreso','E'=>'Egreso','P'=>'Pago','T'=>'Traslado','N'=>'Nómina',default=>$type}; }
    private function dateEs(string $date): string { $time=strtotime($date); return $time?date('d/m/Y',$time):'-'; }
    private function shortUuid(string $uuid): string { return $uuid===''?'-':substr($uuid,0,8).'…'.substr($uuid,-4); }
    private function pdfProviderLabel(string $provider): string { return $provider === 'timbradorxpress-tools' ? 'WSTools33 / PAC' : 'Servicio PDF deshabilitado (fake sólo para pruebas)'; }
    private function pdfArtifactLabel(string $provider): string { return $provider === 'timbradorxpress-tools' ? 'PDF generado por PAC' : 'PDF de prueba local'; }
    private function normalInvoiceBadge(string $status, string $cancellation): string
    {
        [$label,$color] = $cancellation === 'pending' ? ['Cancelación pendiente','warning']
            : ($cancellation === 'cancelled' ? ['Cancelada','secondary']
                : (str_contains($status,'error') ? ['Error fiscal','danger'] : ['Vigente','success']));
        return '<span class="badge bg-'.$color.'">'.esc($label).'</span>';
    }
    private function canCancel(object$row):bool{return !empty($row->uuid)&&in_array($row->visible_status,['stamped','stamped_pdf_pending','stamped_pdf_error'],true)&&in_array($row->cancellation_status,['none','rejected'],true);}
}
