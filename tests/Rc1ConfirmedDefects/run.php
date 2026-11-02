<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$routes = (string) file_get_contents($root . '/app/Config/Routes.php');
$filters = (string) file_get_contents($root . '/app/Config/Filters.php');
$invoices = (string) file_get_contents($root . '/app/Controllers/Invoices.php');
$helperJs = (string) file_get_contents($root . '/app/Views/includes/helper_js.php');
$csrfAjax = (string) file_get_contents($root . '/app/Views/includes/csrf_ajax.php');

$checks = [
    'legacy_posts_require_csrf' => str_contains(
        $routes,
        '$routes->post(strtolower($controller) . \'/(:any)\', "$controller::$1", $legacy_post_options);'
    ) && str_contains($routes, ": ['filter' => 'csrf'];"),
    'external_callback_exceptions_are_explicit' => str_contains($routes, "'Paypal_redirect', 'Paytm_redirect', 'Stripe_redirect', 'Webhooks_listener'")
        && str_contains($routes, "'Collect_leads'"),
    'uppercase_updates_post_requires_csrf' => str_contains(
        $routes,
        '$routes->post("Updates/(:any)", "Updates::$1", [\'filter\' => \'csrf\']);'
    ),
    'csrf_is_global_with_explicit_callback_exceptions' => str_contains($filters, "'csrf' => ['except' => [")
        && str_contains($filters, "'collect_leads/save'")
        && str_contains($filters, "'webhooks_listener/*'"),
    'ajax_always_receives_csrf_token' => str_contains($helperJs, '$csrf_token_name = csrf_token();')
        && !str_contains($helperJs, 'if (get_setting("csrf_protection"))')
        && str_contains($csrfAjax, 'data[AppHelper.csrfTokenName] = AppHelper.csrfHash;')
        && !str_contains($csrfAjax, 'if (get_setting("csrf_protection"))'),
    'invoice_pdf_sets_framework_media_type' => str_contains(
        $invoices,
        '$this->response->setContentType("application/pdf");'
    ),
];

$failed = false;
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $failed = $failed || !$passed;
}

exit($failed ? 1 : 0);
