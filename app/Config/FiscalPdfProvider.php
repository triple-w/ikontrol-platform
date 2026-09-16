<?php
declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

final class FiscalPdfProvider extends BaseConfig
{
    public bool $enabled = false;
    public string $provider = 'fake';
    public string $wsdl = '';
    public string $username = '';
    public string $password = '';
    public int $connectTimeout = 30;
    public int $requestTimeout = 60;
    public bool $allowExternalPdf = false;
    public bool $allowInsecureHttp = false;
    public array $allowedHosts = [];
    public string $defaultTemplateIncome = '';
    public string $defaultTemplateExpense = '';
    public string $defaultTemplatePayment = '';
    public string $defaultTemplateTransfer = '';
    public string $defaultTemplatePayroll = '';

    public function __construct()
    {
        parent::__construct();
        $this->enabled = filter_var(env('MULTIPAC_TOOLS_ENABLED', false), FILTER_VALIDATE_BOOL);
        $this->provider = strtolower(trim((string) env('fiscal.pdf.provider', 'fake')));
        $this->wsdl = trim((string) env('MULTIPAC_TOOLS_WSDL', ''));
        $this->username = trim((string) env('MULTIPAC_TOOLS_USER', ''));
        $this->password = (string) env('MULTIPAC_TOOLS_PASSWORD', '');
        $this->connectTimeout = max(1, min(60, (int) env('MULTIPAC_TOOLS_CONNECT_TIMEOUT', 30)));
        $this->requestTimeout = max(1, min(180, (int) env('MULTIPAC_TOOLS_REQUEST_TIMEOUT', 60)));
        $this->allowExternalPdf = filter_var(env('fiscal.pdf.allowExternalPdf', false), FILTER_VALIDATE_BOOL);
        $this->allowInsecureHttp = filter_var(
            env('MULTIPAC_TOOLS_ALLOW_INSECURE_HTTP', false),
            FILTER_VALIDATE_BOOL
        );
        $hosts = strtolower((string) env('MULTIPAC_TOOLS_ALLOWED_HOSTS', ''));
        $this->allowedHosts = array_values(array_unique(array_filter(array_map('trim', explode(',', $hosts)))));
        foreach (['Income', 'Expense', 'Payment', 'Transfer', 'Payroll'] as $type) {
            $property = 'defaultTemplate' . $type;
            $this->{$property} = trim((string) env('fiscal.pdf.' . $property, ''));
        }
    }

    public function defaultFor(string $type): string
    {
        return match (strtoupper($type)) {
            'I' => $this->defaultTemplateIncome,
            'E' => $this->defaultTemplateExpense,
            'P' => $this->defaultTemplatePayment,
            'T' => $this->defaultTemplateTransfer,
            'N' => $this->defaultTemplatePayroll,
            default => '',
        };
    }
}
