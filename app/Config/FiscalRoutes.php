<?php

/**
 * Explicit route registration boundary for the future fiscal domain.
 *
 * Increment 0 intentionally registers no route. Future routes must be added
 * here with their HTTP verb, controller, filters, and permissions stated
 * explicitly. They must never rely on RISE's dynamic controller discovery.
 *
 * @var \CodeIgniter\Router\RouteCollection $routes
 */
$routes->get('fiscal/client-profiles/(:num)', 'Fiscal\ClientProfiles::index/$1');
$routes->post('fiscal/client-profiles/list/(:num)', 'Fiscal\ClientProfiles::list_data/$1', ['filter' => 'csrf']);
$routes->post('fiscal/client-profiles/form', 'Fiscal\ClientProfiles::form', ['filter' => 'csrf']);
$routes->post('fiscal/client-profiles/save', 'Fiscal\ClientProfiles::save', ['filter' => 'csrf']);
$routes->post('fiscal/client-profiles/default', 'Fiscal\ClientProfiles::set_default', ['filter' => 'csrf']);
$routes->post('fiscal/client-profiles/deactivate', 'Fiscal\ClientProfiles::deactivate', ['filter' => 'csrf']);
$routes->post('fiscal/items/form', 'Fiscal\ItemSettings::form', ['filter' => 'csrf']);
$routes->post('fiscal/items/save', 'Fiscal\ItemSettings::save', ['filter' => 'csrf']);
$routes->get('fiscal/items/readiness/(:num)', 'Fiscal\ItemSettings::readiness/$1');
$routes->post('fiscal/catalogs/product-service/search', 'Fiscal\ItemSettings::search_products', ['filter' => 'csrf']);
$routes->post('fiscal/catalogs/units/search', 'Fiscal\ItemSettings::search_units', ['filter' => 'csrf']);
$routes->post('fiscal/items/deactivate', 'Fiscal\ItemSettings::deactivate', ['filter' => 'csrf']);
$routes->post('fiscal/items/activate', 'Fiscal\ItemSettings::activate', ['filter' => 'csrf']);
$routes->get('fiscal/invoices/review/(:num)', 'Fiscal\InvoiceReview::show/$1');
$routes->post('fiscal/invoices/review/(:num)', 'Fiscal\InvoiceReview::show/$1', ['filter' => 'csrf']);
$routes->post('fiscal/invoices/pricing/apply', 'Fiscal\InvoiceReview::apply', ['filter' => 'csrf']);
$routes->post('fiscal/invoices/drafts/create', 'Fiscal\InvoiceReview::create_draft', ['filter' => 'csrf']);
$routes->post(
    'fiscal/invoices/(:num)/generate',
    'Fiscal\InvoiceReview::generate/$1',
    ['filter' => 'csrf']
);
$routes->get('fiscal/invoices/drafts/(:num)/view', 'Fiscal\InvoiceReview::draft/$1', ['as'=>'fiscal_invoice_draft_view']);
$routes->post('fiscal/invoices/drafts/action', 'Fiscal\InvoiceReview::draft_action', ['filter' => 'csrf']);
$routes->post('fiscal/invoices/prexml/generate', 'Fiscal\InvoiceReview::generate_prexml', ['filter' => 'csrf']);
$routes->get('fiscal/invoices/prexml/view/(:num)', 'Fiscal\InvoiceReview::view_prexml/$1');
$routes->get('fiscal/invoices/prexml/download/(:num)', 'Fiscal\InvoiceReview::download_prexml/$1');
$routes->post('fiscal/invoices/prexml/validate', 'Fiscal\InvoiceReview::validate_prexml', ['filter' => 'csrf']);
$routes->post('fiscal/invoices/sign', 'Fiscal\InvoiceReview::sign_xml', ['filter' => 'csrf']);
$routes->get('fiscal/invoices/signed/view/(:num)', 'Fiscal\InvoiceReview::view_signed_xml/$1', ['as'=>'fiscal_signed_xml_view']);
$routes->get('fiscal/invoices/signed/download/(:num)', 'Fiscal\InvoiceReview::download_signed_xml/$1', ['as'=>'fiscal_signed_xml_download']);
$routes->get('fiscal/issuers', 'Fiscal\Issuers::index');
$routes->post('fiscal/issuers/list', 'Fiscal\Issuers::list_data', ['filter' => 'csrf']);
$routes->post('fiscal/issuers/form', 'Fiscal\Issuers::form', ['filter' => 'csrf']);
$routes->post('fiscal/issuers/save', 'Fiscal\Issuers::save', ['filter' => 'csrf']);
$routes->post('fiscal/issuers/default', 'Fiscal\Issuers::set_default', ['filter' => 'csrf']);
$routes->post('fiscal/issuers/deactivate', 'Fiscal\Issuers::deactivate', ['filter' => 'csrf']);
$routes->get('fiscal/issuers/(:num)/certificates', 'Fiscal\Certificates::index/$1');
$routes->post('fiscal/certificates/list/(:num)', 'Fiscal\Certificates::list_data/$1', ['filter' => 'csrf']);
$routes->post('fiscal/certificates/form', 'Fiscal\Certificates::form', ['filter' => 'csrf']);
$routes->post('fiscal/certificates/upload', 'Fiscal\Certificates::upload', ['filter' => 'csrf']);
$routes->post('fiscal/certificates/secret/form', 'Fiscal\Certificates::secret_form', ['filter' => 'csrf']);
$routes->post(
    'fiscal/certificates/secret/configure',
    'Fiscal\Certificates::configure_secret',
    ['filter' => 'csrf']
);
$routes->post('fiscal/certificates/deactivate', 'Fiscal\Certificates::deactivate', ['filter' => 'csrf']);
$routes->get('fiscal/pac/status', 'Fiscal\Stamping::pacStatus');
$routes->get('fiscal/onboarding', 'Fiscal\Onboarding::index');
$routes->get('fiscal/onboarding/status', 'Fiscal\Onboarding::status');
$routes->post('fiscal/stamping/stamp', 'Fiscal\Stamping::stamp', ['filter' => 'csrf']);
$routes->post('fiscal/stamping/verify-signed', 'Fiscal\Stamping::verifySigned', ['filter' => 'csrf']);
$routes->post('fiscal/stamping/result/(:num)', 'Fiscal\Stamping::result/$1', ['filter' => 'csrf']);
$routes->post('fiscal/stamping/status', 'Fiscal\Stamping::satStatus', ['filter' => 'csrf']);
$routes->post('fiscal/stamping/reconcile', 'Fiscal\Stamping::reconcile', ['filter' => 'csrf']);
$routes->get('fiscal/stamping/xml/view/(:num)', 'Fiscal\Stamping::viewXml/$1');
$routes->get('fiscal/stamping/xml/download/(:num)', 'Fiscal\Stamping::downloadXml/$1');
$routes->get('fiscal/documents/(:num)/pdf/preview', 'Fiscal\Stamping::viewPdf/$1');
$routes->get('fiscal/documents/(:num)/pdf/download', 'Fiscal\Stamping::downloadPdf/$1');
$routes->post('fiscal/documents/(:num)/pdf/generate', 'Fiscal\Stamping::generatePdf/$1', ['filter' => 'csrf']);
$routes->post('fiscal/documents/(:num)/pdf/regenerate', 'Fiscal\Stamping::regeneratePdf/$1', ['filter' => 'csrf']);
$routes->get('fiscal/pdf-templates', 'Fiscal\PdfTemplates::index');
$routes->post('fiscal/pdf-templates/save', 'Fiscal\PdfTemplates::save', ['filter' => 'csrf']);
$routes->get('fiscal/invoices', 'Fiscal\InvoiceModule::index');
$routes->post('fiscal/invoices/list', 'Fiscal\InvoiceModule::listData', ['filter' => 'csrf']);
$routes->get('fiscal/invoices/(:num)', 'Fiscal\InvoiceModule::show/$1');
$routes->get('fiscal/drafts', 'Fiscal\Drafts::index');
$routes->post('fiscal/drafts/list', 'Fiscal\Drafts::listData', ['filter'=>'csrf']);
$routes->get('fiscal/drafts/create/(:num)', 'Fiscal\Drafts::create/$1');
$routes->post('fiscal/drafts', 'Fiscal\Drafts::store', ['filter'=>'csrf']);
$routes->get('fiscal/drafts/(:num)', 'Fiscal\Drafts::show/$1');
$routes->get('fiscal/drafts/(:num)/edit', 'Fiscal\Drafts::edit/$1');
$routes->post('fiscal/drafts/(:num)', 'Fiscal\Drafts::update/$1', ['filter'=>'csrf']);
$routes->post('fiscal/drafts/(:num)/discard', 'Fiscal\Drafts::discard/$1', ['filter'=>'csrf']);
$routes->post('fiscal/drafts/(:num)/ready', 'Fiscal\Drafts::ready/$1', ['filter'=>'csrf']);
$routes->post('fiscal/drafts/(:num)/stamp', 'Fiscal\Drafts::stamp/$1', ['filter'=>'csrf']);
$routes->post('fiscal/drafts/(:num)/invoice-flow', 'Fiscal\Drafts::invoiceFlow/$1', ['filter'=>'csrf']);
$routes->post('fiscal/sales/(:num)/invoice-flow', 'Fiscal\Drafts::invoiceSale/$1', ['filter'=>'csrf']);
$routes->get('fiscal/drafts/(:num)/preinvoice', 'Fiscal\Drafts::preinvoice/$1');
$routes->post('fiscal/receivers/(:num)', 'Fiscal\Drafts::updateReceiver/$1', ['filter'=>'csrf']);
$routes->post('fiscal/invoices/cancel/form', 'Fiscal\Invoices::cancelForm', ['filter' => 'csrf']);
$routes->post('fiscal/invoices/cancel', 'Fiscal\Invoices::cancel', ['filter' => 'csrf']);
$routes->post('fiscal/invoices/cancellation/status', 'Fiscal\Invoices::cancellationStatus', ['filter' => 'csrf']);
$routes->post('fiscal/invoices/cancellation/status/form', 'Fiscal\Invoices::cancellationStatusForm', ['filter' => 'csrf']);
$routes->get('fiscal/invoices/cancellation/ack/(:num)', 'Fiscal\Invoices::ack/$1');
$routes->get('fiscal/series', 'Fiscal\Series::index');
$routes->post('fiscal/series/list', 'Fiscal\Series::list_data', ['filter' => 'csrf']);
$routes->post('fiscal/series/form', 'Fiscal\Series::form', ['filter' => 'csrf']);
$routes->post('fiscal/series/save', 'Fiscal\Series::save', ['filter' => 'csrf']);
$routes->post('fiscal/series/deactivate', 'Fiscal\Series::deactivate', ['filter' => 'csrf']);
$routes->get('fiscal/stamps/balance', 'Fiscal\StampBalance::index');
