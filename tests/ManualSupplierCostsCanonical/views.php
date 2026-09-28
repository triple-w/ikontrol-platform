<?php

declare(strict_types=1);

// Render the actual direct consumers with pure helpers; no app bootstrap or DB.
function esc($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function get_uri($v) { return '/' . $v; }
function to_currency($v) { if ($v === null) throw new RuntimeException('NULL cannot be rendered as zero.'); return '$' . number_format((float) $v, 2); }
function format_to_date($v, $unused) { if ($v === null) throw new RuntimeException('Missing date cannot be invented.'); return $v; }
function app_lang($v) { return $v; }
function modal_anchor($url, $label, $attrs = []) { return '<a href="' . esc($url) . '">' . $label . '</a>'; }
function csrf_token() { return 'fixture'; }
function csrf_hash() { return 'fixture'; }

$passed = 0;
set_error_handler(static function ($severity, $message) { throw new RuntimeException($message); });
try {
    foreach (['items', 'suppliers'] as $view) {
        $model_info = (object) ['files' => '', 'title' => 'Fixture', 'show_in_client_portal' => false, 'taxable' => false,
            'rate' => '99', 'unit_type' => '', 'description' => '', 'added_to_cart' => true];
        $login_user = (object) []; $custom_fields_list = [];
        $supplier = (object) ['id' => 1, 'name' => 'Fixture', 'status' => 'active', 'rfc' => '', 'contact_name' => '', 'phone' => '', 'email' => '', 'notes' => ''];
        $can_view_costs = $can_view_supplier_costs = true; $can_manage = false; $draft_assignments_count = 0;
        $manual = (object) ['source_type' => 'manual', 'document_folio' => 'Captura manual <script>', 'quoted_at' => null,
            'product_name' => 'Fixture', 'supplier_id' => 1, 'supplier_name' => 'Fixture', 'unit_cost' => '12.34',
            'proposal_id' => null, 'company_name' => null, 'sale_unit_price' => null];
        $formal = clone $manual; $formal->source_type = 'proposal'; $formal->proposal_id = 12;
        $formal->company_name = 'Client'; $formal->sale_unit_price = '15'; $formal->quoted_at = '2026-09-01';
        $history = $supplier_cost_history = [$manual, $formal];
        $products = $supplier_cost_summary = [(object) ['product_id' => 1, 'title' => 'Fixture', 'supplier_id' => 1,
            'supplier_name' => 'Fixture', 'last_cost' => '12.34', 'last_date' => null, 'quote_count' => 2]];
        $supplier_cost_indicators = [];
        ob_start(); require dirname(__DIR__, 2) . '/app/Views/' . $view . '/view.php'; $html = ob_get_clean();
        foreach ([str_contains($html, 'Captura manual &lt;script&gt;'), ! str_contains($html, 'proposals/view/0'),
            str_contains($html, 'proposals/view/12'), ! str_contains($html, '$0.00')] as $ok) {
            if (! $ok) throw new RuntimeException('Incorrect ' . $view . ' history rendering.');
            $passed++;
        }
    }
    echo $passed . " view assertions passed; no DB or PAC.\n";
} catch (Throwable $e) {
    if (ob_get_level()) ob_end_clean();
    fwrite(STDERR, $e->getMessage() . PHP_EOL); exit(1);
}
