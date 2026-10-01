<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */

// We get a performance increase by specifying the default
// route since we don't have to scan directories.
$routes->get('/', 'Dashboard::index');

//custom routing for custom pages
//this route will move 'about/any-text' to 'domain.com/about/index/any-text'
$routes->add('about/(:any)', 'About::index/$1');

//add routing for controllers
$excluded_controllers = array("About", "App_Controller", "Security_Controller");
$controller_dropdown = array();
$dir = "./app/Controllers/";
if (is_dir($dir)) {
    if ($dh = opendir($dir)) {
        while (($file = readdir($dh)) !== false) {
            $controller_name = substr($file, 0, -4);
            if (is_file($dir . $file) && pathinfo($file, PATHINFO_EXTENSION) === "php" && !in_array($controller_name, $excluded_controllers)) {
                $controller_dropdown[] = $controller_name;
            }
        }
        closedir($dh);
    }
}

// add route for Collect_leads controller differently with the CORS filter for AJAX requests / API calls
$routes->post('collect_leads/save', 'Collect_leads::save', ['filter' => 'cors']);
$routes->options('collect_leads/save', 'Collect_leads::save', ['filter' => 'cors']);
$routes->post('invoices/close_sale/(:num)', 'Invoices::close_sale/$1', ['filter' => 'csrf']);

// Canonical administrative payments must precede the legacy controller catch-all routes.
$routes->post('invoice_payments/allocate_payment', 'Invoice_payments::allocate_payment', ['filter' => 'csrf']);
$routes->post('invoice_payments/delete_allocation', 'Invoice_payments::delete_allocation', ['filter' => 'csrf']);
$routes->post('invoice_payments/apply_multiple', 'Invoice_payments::apply_multiple', ['filter' => 'csrf']);
$routes->post('invoice_payments/canonical_list_data', 'Invoice_payments::canonical_list_data', ['filter' => 'csrf']);
$routes->post('invoice_payments/client_invoices', 'Invoice_payments::client_invoices', ['filter' => 'csrf']);
$routes->get('invoice_payments/view/(:num)', 'Invoice_payments::view/$1');

// Payment Complement preparation (derived from canonical administrative payments).
$routes->get('payment_complements', 'Payment_complements::index');
$routes->post('payment_complements/list_data', 'Payment_complements::list_data', ['filter' => 'csrf']);
$routes->get('payment_complements/create', 'Payment_complements::create');
$routes->post('payment_complements/create', 'Payment_complements::create', ['filter' => 'csrf']);
$routes->post('payment_complements/save', 'Payment_complements::save', ['filter' => 'csrf']);
$routes->get('payment_complements/client/(:num)/payments', 'Payment_complements::clientPayments/$1');
$routes->get('payment_complements/edit/(:num)', 'Payment_complements::edit/$1');
$routes->post('payment_complements/(:num)/details', 'Payment_complements::updateDetails/$1', ['filter' => 'csrf']);
$routes->post('payment_complements/(:num)/documents', 'Payment_complements::addDocument/$1', ['filter' => 'csrf']);
$routes->post('payment_complements/(:num)/documents/(:num)/remove', 'Payment_complements::removeDocument/$1/$2', ['filter' => 'csrf']);
$routes->post('payment_complements/(:num)/external-documents', 'Payment_complements::saveExternalDocument/$1/0', ['filter' => 'csrf']);
$routes->post('payment_complements/(:num)/external-documents/(:num)', 'Payment_complements::saveExternalDocument/$1/$2', ['filter' => 'csrf']);
$routes->post('payment_complements/(:num)/external-documents/(:num)/remove', 'Payment_complements::removeExternalDocument/$1/$2', ['filter' => 'csrf']);
$routes->get('payment_complements/review/(:num)', 'Payment_complements::review/$1');
$routes->post('payment_complements/(:num)/fiscal-snapshot', 'Payment_complements::fiscalSnapshot/$1', ['filter' => 'csrf']);
$routes->get('payment_complements/preview/(:num)', 'Payment_complements::preview/$1');
$routes->post('payment_complements/(:num)/stamp', 'Payment_complements::stamp/$1', ['filter' => 'csrf']);
$routes->post('payment_complements/cancel/form', 'Payment_complement_cancellations::form', ['filter' => 'csrf']);
$routes->post('payment_complements/(:num)/cancel/request', 'Payment_complement_cancellations::request/$1', ['filter' => 'csrf']);
$routes->post('payment_complements/cancel/status/form', 'Payment_complement_cancellations::statusForm', ['filter' => 'csrf']);
$routes->post('payment_complements/(:num)/cancel/check', 'Payment_complement_cancellations::check/$1', ['filter' => 'csrf']);
$routes->get('payment_complements/(:num)/cancel/receipt/(:num)', 'Payment_complement_cancellations::receipt/$1/$2');
$routes->post('payment_complements/(:num)/discard', 'Payment_complements::discard/$1', ['filter' => 'csrf']);

// Fiscal Credit Notes (CFDI E) reuse the canonical fiscal/PAC pipeline.
$routes->get('credit_notes', 'Credit_notes::index');
$routes->post('credit_notes/list_data', 'Credit_notes::list_data', ['filter'=>'csrf']);
$routes->get('credit_notes/create', 'Credit_notes::create_form');
$routes->post('credit_notes/create/form', 'Credit_notes::create_form', ['filter'=>'csrf']);
$routes->post('credit_notes/clients/(:num)/documents', 'Credit_notes::client_documents/$1', ['filter'=>'csrf']);
$routes->post('credit_notes/create', 'Credit_notes::create', ['filter'=>'csrf']);
$routes->get('credit_notes/(:num)', 'Credit_notes::edit/$1');
$routes->post('credit_notes/(:num)/save', 'Credit_notes::save/$1', ['filter'=>'csrf']);
$routes->post('credit_notes/(:num)/items/(:num)/remove', 'Credit_notes::remove_item/$1/$2', ['filter'=>'csrf']);
$routes->post('credit_notes/(:num)/review', 'Credit_notes::review/$1', ['filter'=>'csrf']);
$routes->post('credit_notes/(:num)/preview', 'Credit_notes::preview/$1', ['filter'=>'csrf']);
$routes->post('credit_notes/(:num)/stamp', 'Credit_notes::stamp/$1', ['filter'=>'csrf']);

// DOLD Fase 1: proveedores y memoria comercial de costos.
$routes->get('suppliers', 'Suppliers::index');
$routes->post('suppliers/list_data', 'Suppliers::list_data', ['filter'=>'csrf']);
$routes->post('suppliers/modal_form', 'Suppliers::modal_form', ['filter'=>'csrf']);
$routes->post('suppliers/save', 'Suppliers::save', ['filter'=>'csrf']);
$routes->get('suppliers/view/(:num)', 'Suppliers::view/$1');
$routes->post('suppliers/toggle_status', 'Suppliers::toggle_status', ['filter'=>'csrf']);
$routes->post('suppliers/(:num)/manual-cost/modal-form', 'Suppliers::manual_cost_modal_form/$1', ['filter'=>'csrf']);
$routes->post('suppliers/manual-cost/save', 'Suppliers::save_manual_cost', ['filter'=>'csrf']);
$routes->post('suppliers/products/(:num)/cost-comparison', 'Suppliers::product_cost_comparison/$1', ['filter'=>'csrf']);
$routes->post('proposals/products/(:num)/supplier-comparison', 'Proposals::supplier_comparison/$1', ['filter'=>'csrf']);
$routes->post('proposals/products/(:num)/suppliers/(:num)/cost-reference', 'Proposals::supplier_cost_reference/$1/$2', ['filter'=>'csrf']);
$routes->post('proposals/items/(:num)/supplier-quotes/save', 'Proposals::save_supplier_quote/$1', ['filter'=>'csrf']);
$routes->post('proposals/items/(:num)/supplier-quotes/(:num)/select', 'Proposals::select_supplier_quote/$1/$2', ['filter'=>'csrf']);
$routes->post('proposals/items/(:num)/supplier-quotes/(:num)/delete', 'Proposals::delete_supplier_quote/$1/$2', ['filter'=>'csrf']);

// P13: Proposals and templates use explicit verbs so legacy controller
// discovery cannot expose mutations through GET or unfiltered POST aliases.
$routes->get('proposals', 'Proposals::index');
$routes->get('proposals/view/(:num)', 'Proposals::view/$1');
$routes->get('proposals/preview/(:num)', 'Proposals::preview/$1');
$routes->get('proposals/preview/(:num)/(:segment)', 'Proposals::preview/$1/$2');
$routes->get('proposals/preview/(:num)/(:segment)/(:segment)', 'Proposals::preview/$1/$2/$3');
$routes->get('proposals/editor/(:num)', 'Proposals::editor/$1');
$routes->get('proposals/email_view_report/(:num)', 'Proposals::email_view_report/$1');
$routes->get('proposals/download_pdf/(:num)', 'Proposals::download_pdf/$1');
$routes->get('proposals/download_pdf/(:num)/(:segment)', 'Proposals::download_pdf/$1/$2');
$routes->get('proposals/download_pdf/(:num)/(:segment)/(:segment)', 'Proposals::download_pdf/$1/$2/$3');
$routes->get('proposals/download_comment_files/(:num)', 'Proposals::download_comment_files/$1');
$routes->post('proposals/modal_form', 'Proposals::modal_form', ['filter'=>'csrf']);
$routes->post('proposals/save_view', 'Proposals::save_view', ['filter'=>'csrf']);
$routes->post('proposals/save', 'Proposals::save', ['filter'=>'csrf']);
$routes->post('proposals/update_proposal_status/(:num)/(:segment)', 'Proposals::update_proposal_status/$1/$2', ['filter'=>'csrf']);
$routes->post('proposals/delete', 'Proposals::delete', ['filter'=>'csrf']);
$routes->post('proposals/list_data', 'Proposals::list_data', ['filter'=>'csrf']);
$routes->post('proposals/proposal_list_data_of_client/(:num)', 'Proposals::proposal_list_data_of_client/$1', ['filter'=>'csrf']);
$routes->post('proposals/discount_modal_form', 'Proposals::discount_modal_form', ['filter'=>'csrf']);
$routes->post('proposals/save_discount', 'Proposals::save_discount', ['filter'=>'csrf']);
$routes->post('proposals/item_modal_form', 'Proposals::item_modal_form', ['filter'=>'csrf']);
$routes->post('proposals/save_item', 'Proposals::save_item', ['filter'=>'csrf']);
$routes->post('proposals/delete_item', 'Proposals::delete_item', ['filter'=>'csrf']);
$routes->post('proposals/item_list_data/(:num)', 'Proposals::item_list_data/$1', ['filter'=>'csrf']);
$routes->post('proposals/get_proposal_item_suggestion', 'Proposals::get_proposal_item_suggestion', ['filter'=>'csrf']);
$routes->post('proposals/get_proposal_item_info_suggestion', 'Proposals::get_proposal_item_info_suggestion', ['filter'=>'csrf']);
$routes->post('proposals/send_proposal_modal_form/(:num)', 'Proposals::send_proposal_modal_form/$1', ['filter'=>'csrf']);
$routes->post('proposals/get_send_proposal_template/(:num)/(:num)/(:segment)', 'Proposals::get_send_proposal_template/$1/$2/$3', ['filter'=>'csrf']);
$routes->post('proposals/send_proposal', 'Proposals::send_proposal', ['filter'=>'csrf']);
$routes->post('proposals/update_item_sort_values', 'Proposals::update_item_sort_values', ['filter'=>'csrf']);
$routes->post('proposals/update_item_sort_values/(:num)', 'Proposals::update_item_sort_values/$1', ['filter'=>'csrf']);
$routes->post('proposals/comment_modal_form', 'Proposals::comment_modal_form', ['filter'=>'csrf']);
$routes->post('proposals/save_comment', 'Proposals::save_comment', ['filter'=>'csrf']);
$routes->post('proposals/delete_comment/(:num)', 'Proposals::delete_comment/$1', ['filter'=>'csrf']);

$routes->get('proposal_templates', 'Proposal_templates::index');
$routes->get('proposal_templates/form', 'Proposal_templates::form');
$routes->get('proposal_templates/form/(:num)', 'Proposal_templates::form/$1');
$routes->post('proposal_templates/modal_form', 'Proposal_templates::modal_form', ['filter'=>'csrf']);
$routes->post('proposal_templates/save_template', 'Proposal_templates::save_template', ['filter'=>'csrf']);
$routes->post('proposal_templates/save', 'Proposal_templates::save', ['filter'=>'csrf']);
$routes->post('proposal_templates/delete', 'Proposal_templates::delete', ['filter'=>'csrf']);
$routes->post('proposal_templates/list_data', 'Proposal_templates::list_data', ['filter'=>'csrf']);
$routes->post('proposal_templates/list_data/(:segment)', 'Proposal_templates::list_data/$1', ['filter'=>'csrf']);
$routes->post('proposal_templates/insert_template_modal_form', 'Proposal_templates::insert_template_modal_form', ['filter'=>'csrf']);
$routes->post('proposal_templates/get_template_data/(:num)', 'Proposal_templates::get_template_data/$1', ['filter'=>'csrf']);

// Public proposal access keeps the public key contract, but all state changes
// remain POST + CSRF and cannot fall through legacy controller discovery.
$routes->get('offer/preview/(:num)/(:segment)', 'Offer::preview/$1/$2');
$routes->get('offer/download_pdf/(:num)/(:segment)', 'Offer::download_pdf/$1/$2');
$routes->post('offer/update_proposal_status/(:num)/(:segment)/(:segment)', 'Offer::update_proposal_status/$1/$2/$3', ['filter'=>'csrf']);
$routes->post('offer/print_proposal/(:num)/(:segment)', 'Offer::print_proposal/$1/$2', ['filter'=>'csrf']);
$routes->post('offer/accept_proposal_modal_form/(:num)', 'Offer::accept_proposal_modal_form/$1', ['filter'=>'csrf']);
$routes->post('offer/accept_proposal_modal_form/(:num)/(:segment)', 'Offer::accept_proposal_modal_form/$1/$2', ['filter'=>'csrf']);
$routes->post('offer/accept_proposal', 'Offer::accept_proposal', ['filter'=>'csrf']);

// DOLD Almacenes: ledger logístico independiente de productos comerciales.
  $routes->get('warehouses', 'Warehouse_logistics::index');
$routes->get('warehouses/catalog', 'Warehouses::catalog');
$routes->post('warehouses/catalog/list', 'Warehouses::warehouse_list_data', ['filter'=>'csrf']);
$routes->post('warehouses/form', 'Warehouses::warehouse_form', ['filter'=>'csrf']);
$routes->post('warehouses/save', 'Warehouses::save_warehouse', ['filter'=>'csrf']);
$routes->post('warehouses/toggle', 'Warehouses::toggle_warehouse', ['filter'=>'csrf']);
$routes->get('warehouses/view/(:num)', 'Warehouses::view/$1');
$routes->get('warehouses/products', 'Warehouses::products');
$routes->post('warehouses/products/list', 'Warehouses::product_list_data', ['filter'=>'csrf']);
$routes->post('warehouses/products/form', 'Warehouses::product_form', ['filter'=>'csrf']);
$routes->post('warehouses/products/save', 'Warehouses::save_product', ['filter'=>'csrf']);
$routes->post('warehouses/products/toggle', 'Warehouses::toggle_product', ['filter'=>'csrf']);
$routes->get('warehouses/products/view/(:num)', 'Warehouses::product_view/$1');
$routes->get('warehouses/products/lookup', 'Warehouses::lookup');
$routes->post('warehouses/products/(:num)/labels/form', 'Warehouse_labels::form/$1', ['filter'=>'csrf']);
$routes->post('warehouses/products/(:num)/labels/preview', 'Warehouse_labels::preview/$1', ['filter'=>'csrf']);
$routes->post('warehouses/products/(:num)/labels/pdf', 'Warehouse_labels::pdf/$1', ['filter'=>'csrf']);
$routes->post('warehouses/products/(:num)/label-logo', 'Warehouse_labels::upload_logo/$1', ['filter'=>'csrf']);
$routes->post('warehouses/products/(:num)/label-logo/remove', 'Warehouse_labels::remove_logo/$1', ['filter'=>'csrf']);
$routes->get('warehouses/products/(:num)/label-logo', 'Warehouse_labels::logo/$1');
  $routes->get('warehouses/entries', 'Warehouse_logistics::entries');
  $routes->get('warehouses/exits', 'Warehouse_logistics::exits');
  $routes->get('warehouses/adjustments', 'Warehouse_logistics::adjustments');
  $routes->post('warehouses/movements/(:segment)/list', 'Warehouse_logistics::list_data/$1', ['filter'=>'csrf']);
  $routes->post('warehouses/movements/(:segment)/form', 'Warehouse_logistics::form/$1', ['filter'=>'csrf']);
  $routes->post('warehouses/movements/save', 'Warehouse_logistics::save', ['filter'=>'csrf']);
  $routes->get('warehouses/movements/view/(:num)', 'Warehouse_logistics::view/$1');
  $routes->post('warehouses/movements/(:num)/confirm', 'Warehouse_logistics::confirm/$1', ['filter'=>'csrf']);
  $routes->post('warehouses/movements/(:num)/cancel', 'Warehouse_logistics::cancel/$1', ['filter'=>'csrf']);
  $routes->get('warehouses/history', 'Warehouse_logistics::history');
  $routes->get('warehouses/transfers', 'Warehouse_transfers::index');
  $routes->get('warehouses/transfers/in-transit', 'Warehouse_transfers::in_transit');
  $routes->get('warehouses/transfers/receipts', 'Warehouse_transfers::receipts');
  $routes->post('warehouses/transfers/list/(:segment)', 'Warehouse_transfers::list_data/$1', ['filter'=>'csrf']);
  $routes->post('warehouses/transfers/form', 'Warehouse_transfers::form', ['filter'=>'csrf']);
  $routes->post('warehouses/transfers/save', 'Warehouse_transfers::save', ['filter'=>'csrf']);
  $routes->get('warehouses/transfers/view/(:num)', 'Warehouse_transfers::view/$1');
  $routes->post('warehouses/transfers/(:num)/dispatch', 'Warehouse_transfers::dispatch/$1', ['filter'=>'csrf']);
  $routes->post('warehouses/transfers/(:num)/receive-form', 'Warehouse_transfers::receive_form/$1', ['filter'=>'csrf']);
  $routes->post('warehouses/transfers/(:num)/receive', 'Warehouse_transfers::receive/$1', ['filter'=>'csrf']);
  $routes->post('warehouses/transfers/(:num)/close-difference', 'Warehouse_transfers::close_difference/$1', ['filter'=>'csrf']);
  $routes->post('warehouses/transfers/(:num)/logistics', 'Warehouse_transfers::logistics/$1', ['filter'=>'csrf']);
  $routes->post('warehouses/transfers/(:num)/cancel', 'Warehouse_transfers::cancel/$1', ['filter'=>'csrf']);

// C2.3.1-R1: Estimate acceptance has an explicit read-only modal GET and a POST mutation.
// External fiscal-stamp administration. These explicit routes intentionally live outside
// the authenticated legacy controller groups; the controller validates its own env secret.
$routes->get('admin/ikontrol/timbres/manage-7f9c2a4d91', 'Admin\FiscalStampAdmin::index');
$routes->post('admin/ikontrol/timbres/manage-7f9c2a4d91/adjust', 'Admin\FiscalStampAdmin::adjust', ['filter' => 'csrf']);
$routes->get('admin/ikontrol/timbres/manage-7f9c2a4d91/history', 'Admin\FiscalStampAdmin::history');
$routes->get('admin/ikontrol/timbres/manage-7f9c2a4d91/history/(:num)', 'Admin\FiscalStampAdmin::history/$1');

// Keep these before the legacy controller catch-all routes so method arguments are unambiguous.
$routes->get('estimate/accept_estimate_modal_form/(:num)', 'Estimate::accept_estimate_modal_form/$1');
$routes->get('estimate/accept_estimate_modal_form/(:num)/(:segment)', 'Estimate::accept_estimate_modal_form/$1/$2');
$routes->post('estimate/accept_estimate', 'Estimate::accept_estimate', ['filter' => 'csrf']);
$routes->get('estimate/update_estimate_status/(:num)/(:segment)/(:segment)', 'Estimate::update_estimate_status/$1/$2/$3');
$routes->get('estimates/update_estimate_status/(:num)/(:segment)', 'Estimates::update_estimate_status/$1/$2');

$p13_explicit_controllers = array(
    'Proposals', 'Proposal_templates', 'Offer', 'Suppliers', 'Warehouses',
    'Warehouse_logistics', 'Warehouse_transfers', 'Warehouse_labels',
    'Payment_complements', 'Payment_complement_cancellations', 'Credit_notes'
);
foreach ($controller_dropdown as $controller) {
    if (in_array($controller, $p13_explicit_controllers, true)) {
        continue;
    }
    $routes->get(strtolower($controller), "$controller::index");
    $routes->get(strtolower($controller) . '/(:any)', "$controller::$1");
    $routes->post(strtolower($controller) . '/(:any)', "$controller::$1");
}

// Fiscal routes are intentionally explicit and currently register no endpoints.
// Keep this include separate from RISE's legacy controller discovery.
require APPPATH . 'Config/FiscalRoutes.php';

//add uppercase links

$routes->get("Updates", "Updates::index");
$routes->get("Updates/(:any)", "Updates::$1");
$routes->post("Updates/(:any)", "Updates::$1");

// C2.5A financial account foundation
$routes->get('financial_accounts', 'Financial_accounts::index');
$routes->post('financial_accounts/list_data', 'Financial_accounts::list_data');
$routes->get('financial_accounts/modal_form', 'Financial_accounts::modal_form');
$routes->get('financial_accounts/movements/(:num)', 'Financial_accounts::movements/$1');
$routes->post('financial_accounts/save', 'Financial_accounts::save', ['filter' => 'csrf']);
$routes->post('financial_accounts/deactivate', 'Financial_accounts::deactivate', ['filter' => 'csrf']);
$routes->post('financial_accounts/transfer', 'Financial_accounts::transfer', ['filter' => 'csrf']);
$routes->post('financial_accounts/cancel_transfer', 'Financial_accounts::cancel_transfer', ['filter' => 'csrf']);

/*
 * --------------------------------------------------------------------
 * Additional Routing
 * --------------------------------------------------------------------
 *
 * There will often be times that you need additional routing and you
 * need it to be able to override any defaults in this file. Environment
 * based routes is one such time. require() additional route files here
 * to make that happen.
 *
 * You will have access to the $routes object within that file without
 * needing to reload it.
 */
if (is_file(APPPATH . 'Config/' . ENVIRONMENT . '/Routes.php')) {
    require APPPATH . 'Config/' . ENVIRONMENT . '/Routes.php';
}
