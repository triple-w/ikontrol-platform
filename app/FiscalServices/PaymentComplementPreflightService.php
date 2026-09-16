<?php
declare(strict_types=1);
namespace App\FiscalServices;

use App\Services\Fiscal\Cfdi40\CfdiXsdValidator;
use App\Services\PaymentComplementFiscalSnapshotService;
use App\Services\PaymentComplementReadinessService;
use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

final class PaymentComplementPreflightService
{
    public function __construct(private $db = null) { $this->db ??= db_connect(); }

    public function inspect(int $id): array
    {
        $review = (new PaymentComplementReadinessService($this->db))->check($id);
        $errors = $review['blockers'];
        $snapshot = null;
        $xsd = null;
        if (!$errors) {
            try {
                $snapshot = (new PaymentComplementFiscalSnapshotService($this->db))->compose($id);
                $dom = new DOMDocument();
                if (!$dom->loadXML($snapshot['xml'], LIBXML_NONET | LIBXML_NOBLANKS)) throw new RuntimeException('El XML preliminar no está bien formado.');
                $errors = array_merge($errors, $this->decimalScaleErrors($dom));
                $xsd = (new CfdiXsdValidator())->validate($snapshot['xml']);
                if (!in_array($xsd['status'], ['valid', 'schema_pending_signature'], true)) $errors[] = 'El XML no supera la validación XSD de CFDI 4.0/Pagos 2.0.';
            } catch (\Throwable $e) { $errors[] = $e->getMessage(); }
        }
        return ['ready'=>$errors===[],'blockers'=>array_values(array_unique($errors)),'warnings'=>$review['warnings'],'review'=>$review,'snapshot'=>$snapshot,'xsd'=>$xsd];
    }

    public function requireReady(int $id): array
    {
        $result = $this->inspect($id);
        if (!$result['ready']) throw new RuntimeException('El Complemento no está listo: '.implode(' ', array_slice($result['blockers'], 0, 3)));
        return $result;
    }

    private function decimalScaleErrors(DOMDocument $dom): array
    {
        $errors=[];$xpath=new DOMXPath($dom);$xpath->registerNamespace('pago20','http://www.sat.gob.mx/Pagos20');
        foreach ($xpath->query('//pago20:Pago') ?: [] as $payment) {
            if (!$payment instanceof DOMElement || strtoupper($payment->getAttribute('MonedaP')) !== 'MXN') continue;
            if (!preg_match('/^\d+\.\d{2}$/', $payment->getAttribute('Monto'))) $errors[]='Pago@Monto debe serializarse con exactamente 2 decimales cuando MonedaP es MXN.';
            if ($payment->hasAttribute('TipoCambioP') && $payment->getAttribute('TipoCambioP') !== '1') $errors[]='Pago@TipoCambioP debe ser 1 cuando MonedaP es MXN.';
        }
        foreach ($xpath->query('//pago20:DoctoRelacionado[@MonedaDR="MXN"]') ?: [] as $document) {
            if (!$document instanceof DOMElement) continue;
            $paymentCurrency = $document->parentNode instanceof DOMElement ? strtoupper($document->parentNode->getAttribute('MonedaP')) : '';
            if ($paymentCurrency === 'MXN' && $document->getAttribute('EquivalenciaDR') !== '1') $errors[]='DoctoRelacionado@EquivalenciaDR debe ser exactamente 1 cuando MonedaDR es igual a MonedaP.';
            foreach (['ImpSaldoAnt','ImpPagado','ImpSaldoInsoluto'] as $attribute) if (!preg_match('/^\d+\.\d{2}$/', $document->getAttribute($attribute))) $errors[]="DoctoRelacionado@{$attribute} debe serializarse con exactamente 2 decimales cuando MonedaDR es MXN.";
        }
        foreach ($xpath->query('//pago20:Totales/@*') ?: [] as $attribute) if (!preg_match('/^\d+\.\d{2}$/', (string)$attribute->nodeValue)) $errors[]="Totales@{$attribute->nodeName} debe serializarse con exactamente 2 decimales.";
        return array_values(array_unique($errors));
    }
}
