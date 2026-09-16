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
$routes->get('payment_complements/review/(:num)', 'Payment_complements::review/$1');
$routes->post('payment_complements/(:num)/fiscal-snapshot', 'Payment_complements::fiscalSnapshot/$1', ['filter' => 'csrf']);
$routes->get('payment_complements/preview/(:num)', 'Payment_complements::preview/$1');
$routes->post('payment_complements/(:num)/stamp', 'Payment_complements::stamp/$1', ['filter' => 'csrf']);
$routes->post('payment_complements/cancel/form', 'Payment_complement_cancellations::form');
$routes->post('payment_complements/(:num)/cancel/request', 'Payment_complement_cancellations::request/$1', ['filter' => 'csrf']);
$routes->post('payment_complements/cancel/status/form', 'Payment_complement_cancellations::statusForm');
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
$routes->post('credit_notes/(:num)/review', 'Credit_notes::review/$1');
$routes->post('credit_notes/(:num)/preview', 'Credit_notes::preview/$1');
$routes->post('credit_notes/(:num)/stamp', 'Credit_notes::stamp/$1', ['filter'=>'csrf']);

// DOLD Fase 1: proveedores y memoria comercial de costos.
$routes->get('suppliers', 'Suppliers::index');
$routes->post('suppliers/list_data', 'Suppliers::list_data', ['filter'=>'csrf']);
$routes->post('suppliers/modal_form', 'Suppliers::modal_form', ['filter'=>'csrf']);
$routes->post('suppliers/save', 'Suppliers::save', ['filter'=>'csrf']);
$routes->get('suppliers/view/(:num)', 'Suppliers::view/$1');
$routes->post('suppliers/toggle_status', 'Suppliers::toggle_status', ['filter'=>'csrf']);
$routes->post('proposals/products/(:num)/supplier-comparison', 'Proposals::supplier_comparison/$1', ['filter'=>'csrf']);
$routes->post('proposals/products/(:num)/suppliers/(:num)/cost-reference', 'Proposals::supplier_cost_reference/$1/$2', ['filter'=>'csrf']);
$routes->post('proposals/items/(:num)/supplier-quotes/save', 'Proposals::save_supplier_quote/$1', ['filter'=>'csrf']);
$routes->post('proposals/items/(:num)/supplier-quotes/(:num)/select', 'Proposals::select_supplier_quote/$1/$2', ['filter'=>'csrf']);
$routes->post('proposals/items/(:num)/supplier-quotes/(:num)/delete', 'Proposals::delete_supplier_quote/$1/$2', ['filter'=>'csrf']);

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

foreach ($controller_dropdown as $controller) {
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
