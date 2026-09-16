<?php
declare(strict_types=1);
namespace App\Services\Fiscal;
final class FiscalDraftStatusPresenter
{
    public function present(string $status): array
    {
        return [
            'draft' => ['Incompleto','secondary'],
            'ready' => ['Listo para facturar','success'],
            'stamping' => ['En preparación','warning'],
            'stamped' => ['Facturado','primary'],
            'discarded' => ['Descartado','dark'],
            'expired' => ['Expirado','secondary'],
            'error' => ['Error','danger'],
        ][$status] ?? ['Incompleto','secondary'];
    }
}
