<?php

use App\Order;
use App\Helper;
use Illuminate\Http\Request;
use App\Jobs\DarkKitchen\CheckMenuSchedules;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:api')->get('/user', function (Request $request) {
    return $request->user();
});

Route::prefix('v1')->group(function () {
    Route::get('employee/integrations', function(){
      return response([], 200);
    });
    Route::group(['middleware' => ['roles:employee,plaza', 'throttle:200,1']], function () {
        // API: Employees
        Route::get('store/products', 'API\V1\ProductCategoryController@getProductsStore');
        // Main Screen
        Route::get('categories/store', 'API\V1\ProductCategoryController@categoriesStore');
        Route::get('products/category/{id}', 'API\V1\ProductController@productsCategory');
        Route::get('spots', 'API\V1\SpotsController@spots');
        // Cashier Balance Screen
        Route::get('cashier/balance', 'API\V1\CashierBalanceController@getLastOpenCashierBalance');
        Route::get('has/open/cashier/balance', 'API\V1\CashierBalanceController@hasOpenCashierBalance');

        // Cashier Balance Module
        Route::group(['middleware' => ['permissions:cashier-balance']], function () {
            // Open Cashier Balance
            Route::post('cashier/balance/open/day', 'API\V1\CashierBalanceController@openDay')
                ->middleware('permissions:open-cashier-balance');
            // Close Cashier Balance
            Route::post('cashier/balance/close/day', 'API\V1\CashierBalanceController@closeDay')
                ->middleware('permissions:close-cashier-balance');
        });
        // Corte X/Z
        Route::post('cashier/balance/x_report', 'API\V1\CashierBalanceController@printXReport');

        Route::get('inventory/products', 'API\V1\ProductController@inventoryProducts');
        // Remote Logs
        Route::post('remote/logs', 'API\V1\RemoteLogsController@storeFileLog');

        // RappiPay
        Route::post('rappipay/purchase', 'RappiPayController@postPurchase');

        // OFFLINE
        Route::get('offline/company', 'OfflineController@getCompany');
    });

    Route::get('store_categories', 'API\V1\ProductCategoryController@getCategoriesByStore');
    Route::get('categories/{company}', 'API\V1\ProductCategoryController@getCategoriesByCompany');
    Route::get('products/{category}', 'API\V1\ProductController@getProductsByCategory');
    Route::get('billings/get_first_billing_user/{customer}', 'API\V1\BillingController@getFirstBillingUser');
    Route::post('billings/search_by_document', 'API\V1\BillingController@searchBillingByDocument');
    Route::post('products_details/get_products_details', 'API\V1\ProductDetailController@getProductsDetails');
    Route::post('products/get_product_details', 'API\V1\ProductController@getProductDetails');
    Route::resource('billings', 'API\V1\BillingController');
    Route::resource('orders', 'API\V1\OrderController');
    Route::post('customer/update_profile', 'API\V1\CustomerController@updateProfile');
    Route::post('forgot/password', 'API\V1\Auth\ForgotPasswordController')->name('forgot.password');
    Route::post('customer/resend-activation', 'API\V1\CustomerController@resendCustomerActivationEmail');
    Route::get('orders/get_past_orders/{customer}', 'API\V1\OrderController@getPastOrders');
    Route::get('orders/get_current_orders/{customer}', 'API\V1\OrderController@getCurrentOrders');
    Route::get('orders/get_quantity_orders/{customer}', 'API\V1\OrderController@getQuantityOrders');
    Route::post('orders/cancel_order', 'API\V1\OrderController@cancelOrder');
    Route::get('orders/get_order/{order}', 'API\V1\OrderController@getOrder');
    Route::post('orders/update_order_status', 'API\V1\OrderController@updateOrderStatus');

    // Integrations
    Route::get('order/eats/{id}', 'API\V1\UberEatsController@getOrderDetails');

    // RoutingController
    Route::post('routing/order', 'API\V1\RoutingController@makeRouteRequest');
});

Route::group(
    ['prefix' => 'v2', 'middleware' => ['cors', 'throttle:200,1']],
    function () {
        Route::post('order/accept', 'API\V2\OrderController@acceptOrder');
        Route::post('order/reject', 'API\V2\OrderController@rejectOrder');
        //popsy
        Route::get('popsy/products', 'API\V2\PopsyReportController@getPopsyProductsReport');
        Route::get('popsy/categories', 'API\V2\PopsyReportController@getPopsyCategoriesReport');
        Route::get('popsy/stores', 'API\V2\PopsyReportController@getPopsyStoresReport');
        Route::get('popsy/integrations', 'API\V2\PopsyReportController@getPopsyIntegrationsReport');
        //PROMOTIONS
        Route::get('promotion/types', 'API\V2\PromotionTypeController@getPromotionTypes');
        Route::get('promotion/discount/types', 'API\V2\PromotionTypeController@getDiscountTypes');
        Route::post('promotion/create', 'API\V2\PromotionController@createPromotion');
        Route::get('promotion/all', 'API\V2\PromotionController@getPromotions');
        Route::get('promotion/cupons/all', 'API\V2\PromotionController@getCupons');
        Route::get('promotion/products/category', 'API\V2\PromotionController@getProductsForPromotion');
        Route::get('promotion/shops/byproducts', 'API\V2\PromotionController@getShopsByProducts');
        Route::post('promotion/delete', 'API\V2\PromotionController@deletePromotion');
        // delivery
        Route::post('delivery/checkin', 'API\V2\DeliveryController@checkin');
        Route::post('delivery/ready', 'API\V2\OrderController@distpatchOrder');
        Route::post('delivery/complete', 'API\V2\DeliveryController@markAsDelivered');
        Route::post('delivery/hub/orders', 'API\V2\DeliveryController@getHubOrders');
        Route::post('delivery/orders', 'API\V2\DeliveryController@getOrders');
        Route::post('kitchen/orders', 'API\V2\DeliveryController@getKitchenOrders');
        // customer
        Route::post('customer/create', 'API\V2\CustomerController@create');
        Route::post('customer/search', 'API\V2\CustomerController@search');
        Route::post('customer/address/create', 'API\V2\CustomerController@createAddress');
        Route::post('customer/address/delete', 'API\V2\CustomerController@deleteAddress');
        // checkin
        Route::post('checkin', 'API\V2\CheckinController@checkin');
        Route::post('checkin_offline', 'API\V2\CheckinController@checkinOffline');
        // Visualizar órdenes (module)
        Route::group(['middleware' => 'permissions:view-orders'], function () {
            Route::resource('orders', 'API\V2\OrderController');
            Route::post('orders', 'API\V2\OrderController@index');


            // Reimprimir factura o comanda (action)
            Route::post('order/reprint', 'API\V2\OrderController@reprint')
                ->middleware('permissions:reprint-order');
            Route::post('order/employee', 'API\V2\OrderController@getEmployeeOrders');
        });
        // Manejo de órdenes (module)
        Route::group(['middleware' => 'permissions:manage-orders'], function () {
            // Crear preorden
            Route::post('preorder', 'API\V2\OrderController@createPreorder');
            // Actualizar preorden
            Route::post('preorder/update', 'API\V2\OrderController@changeContentPreorder');
            // Terminar orden (action)
            Route::post('preorder/order', 'API\V2\OrderController@convertPreorderToOrder')
                ->middleware('permissions:finish-order');
            // Borrar preorden (action)
            Route::post('preorder/delete', 'API\V2\OrderController@deletePreorder')
                ->middleware('permissions:delete-order');
            // Imprimir comanda (action)
            Route::post('comanda/print', 'API\V2\OrderController@processPrintComanda')
                ->middleware('permissions:print-command');
            // Split Account
            Route::post('create/order/split', 'API\V2\OrderController@createOrderFromSplitAccount')
                ->middleware('permissions:finish-order');
        });
        // Relevo (module)
        Route::group(['middleware' => 'permissions:change-employee'], function () {
            Route::post('preorder/employee/change', 'API\V2\OrderController@changeOrderSpotEmployee');
        });
        // Mesa
        Route::post('spot/transfer', 'API\V2\SpotController@transferSpotContents');
        Route::post('spot/transfer/details', 'API\V2\SpotController@transferItemsBetweenSpots');
        Route::get('spot/kiosk', 'API\V2\SpotController@createKioskSpot');
        Route::get('spots/active', 'API\V2\SpotController@activeSpots');
        // Preorder
        Route::get('preorder/spot/{idSpot}', 'API\V2\SpotController@getPreorderSpot');
        Route::get('preorder/split/spot/{idSpot}', 'API\V2\SpotController@getPreorderSplitSpot');
        Route::post('preorder/info', 'API\V2\OrderController@getPrintDataPreorder');
        Route::post('order/delivery/info', 'API\V2\OrderController@getPrintDataOrderById');
        Route::post('preorder/printed', 'API\V2\OrderController@changeOrderDetailAsPrinted');

        Route::post('invoice/draft/print', 'API\V2\OrderController@processPrintPreInvoice');
        Route::post('preorder/order_detail_comment', 'API\V2\OrderController@updateInstruction');
        // Comanda Digital (module)
        Route::group(['middleware' => 'permissions:commands'], function () {
            Route::post('orders/pending_old', 'API\V2\OrderController@getOrdersInfoComandaDigital'); // old - delete in future
            Route::post('orders/pending', 'API\V2\OrderController@getOrdersInfoComandaDigitalSQL'); // refactoring
            Route::post('order/detail/dispatch', 'API\V2\OrderController@distpatchProductDetail')
                ->middleware('permissions:dispatch-detail');
            Route::post('order/dispatch', 'API\V2\OrderController@distpatchOrder')
                ->middleware('permissions:dispatch-order');
        });

        Route::post('orders/food_cost', 'API\V2\OrderController@foodCost');
        Route::post('orders/food_cost_xls', 'API\V2\OrderController@exportExcel');

        // Slave server
        Route::post('slave/create/order', 'API\V2\SlaveServerController@createOrderFromSlave');
        Route::post('slave/create/component/category', 'API\V2\SlaveServerController@createComponentCategoryFromSlave');
        Route::post(
            'slave/delete/component/category',
            'API\V2\SlaveServerController@deleteComponentCategoryRequestedSlave'
        );
        Route::post('slave/create/balance', 'API\V2\SlaveServerController@openCashierBalanceSlave');
        Route::post('slave/close/balance', 'API\V2\SlaveServerController@closeCashierBalanceSlave');
        Route::post('slave/create/inventory', 'API\V2\SlaveServerController@createHistoricalInventorySlave');
        Route::get('slave/products/get', 'API\V2\SlaveServerController@getProductsFromStore');
        // Prod Server
        Route::get('prod/products/get', 'API\V2\ProdServerController@getProductsFromProd');
        // Pre Billing Info
        Route::get('next_billing_number', 'API\V2\OrderController@getNextBillingNumber');

        Route::post('order/revoke', 'API\V2\OrderController@revoke');

        Route::group(['middleware' => 'roles:employee,plaza'], function () {
            // Payment
            Route::post('payment/suggestion', 'API\V2\PaymentController@getPaymentSuggestions');
            Route::get('payment/types', 'API\V2\PaymentController@getPaymentTypes');
            Route::post('payment/update', 'API\V2\PaymentController@updatePayment');

            // Store
            Route::get('store/cards', 'API\V2\PaymentController@getCards');
        });
        
    }
);

/* LOGIN ROUTES */
Route::post('login/app', 'Auth\LoginController@loginApp');
Route::post('{provider}/login', 'Auth\SocialAuthController@tokenLogin');
/* ------------ */

/* ADMIN STORE ROUTES */
// simpleLogger -> middleware para guardar en bitacora el request de forma async

Route::group(['middleware' => ['cors']], function () {
    Route::post('login/external', 'Auth\LoginController@loginToken');
    Route::post('login', 'Auth\LoginController@loginUser');
    Route::post('login2', 'Auth\LoginController@loginUser');
    Route::post('login_store', 'Auth\LoginController@loginUser');
    // Route::post('login_store', 'Auth\LoginController@loginStore');
    Route::get('admin_store/get_user_store', 'AdminStoreController@getUserStore');
    Route::post('admin/authorize', 'AdminStoreController@getAdminAuthorization');
    Route::post('admin/passcode', 'AdminStoreController@setAdminPasscode');

    Route::post('orders/dian_transactions', 'ReportController@transactionDetailsReportColombiaPDF');
    Route::post('pdfreport/zcut', 'ReportController@downloadZCutReport');
    Route::post('pdfreport/pendingstoretransfers', 'ReportController@downloadPendingStoreTransfers');

    Route::group(['middleware' => 'roles:admin_store,admin_franchise'], function () {
        // Stock Transfers (module)
        Route::group(['middleware' => 'permissions:stock-transfers'], function () {
            // Ver y aplicar movimientos pendientes (actions)
            Route::group(['middleware' => 'permissions:pending-transfers'], function () {
                Route::get('stock_transfers/get_pending_transfers', 'StockTransferController@getPendingStockTransfers');
                Route::post('stock_transfers/apply', 'StockTransferController@applyStockTransfer');
            });
            // Ver tiendas y realizar transferencias (actions)
            Route::group(['middleware' => 'permissions:create-transfers'], function () {
                Route::get('stock_transfers/get_transfer_stores', 'StockTransferController@getTransferStores');
                Route::get('stock_transfers/get_transfer_store_data/{id}', 'StockTransferController@getTransferStoreData');
                Route::post('stock_transfers/send_item', 'StockTransferController@sendItemStockTransfer');
            });
        });

        Route::get('stores/virtual_stores', 'StoreController@getVirtualStores');

        Route::post('orders/get_orders_paginate', 'OrderController@getOrdersPaginate');
        Route::post('orders/reports', 'OrderController@getReportOrders');
        Route::post('orders/add_order_gacela', 'OrderController@postGacelaOrder');
        Route::post('orders/invoices', 'OrderController@getReportInvoices');
        Route::post('orders/invoices/multi_store', 'OrderController@getReportInvoicesMultiStore');
        Route::post('orders/inventory', 'OrderController@getReportInventory');
        Route::post('orders/inventory_excel', 'OrderController@getReportInventoryExcel');
        Route::post('orders/transactions', 'OrderController@getReportTransactionsSQL');

        Route::post('orders/revoke_order', 'OrderController@revokeOrder');
        Route::post('orders/by_employee', 'OrderController@getReportOrdersByEmployee');
        Route::post('orders/hourly_orders', 'OrderController@getReportHourly');
        Route::post('orders/week_day_orders', 'OrderController@getReportWeekDay');
        Route::post('orders/category_sales', 'OrderController@getReportCategorySales');
        Route::get('orders/payment/types', 'OrderController@getPaymentTypes');
        Route::post('orders/update_payment', 'OrderController@updatePayment');
        Route::post('payment/cards', 'OrderController@getCardsByOrderId');

        // Reporte Porcentaje de Ventas por Empleado por Categoria
        Route::post('reports/employee_percent_sales_category', 'ReportePorcentajeVentasXEmpleado@getReportePorcentajeXEmpleadoXCategoria');
        Route::get('reports/employee_percent_sales_category/employees', 'ReportePorcentajeVentasXEmpleado@getEmpleadosXTienda');

        // Reporte Porcentaje de Ventas por Categoria (solo Comidas y Bebidas)
        Route::get('reporte-porcentaje-por-categoria/{obj_fechas}', 'ReportePorcentajeVentasController@getReportePorcentajeXCategoria');
        Route::post('reporte-porcentaje-por-categoria/exportar-excel', 'ReportePorcentajeVentasController@exportarExcelReportePorcentajeXCategoria');

        // Reporte de Movimientos de Inventario
        Route::post('stock_movements/report/table', 'ReporteMovimientosDeInventario@getTableData');
        Route::post('stock_movements/report/excel', 'ReporteMovimientosDeInventario@exportExcel');

        //Excel Reports
        Route::post('reports/invoice_report', 'ReportController@invoiceDataReport');
        Route::post('reports/invoice_report/multi_store', 'ReportController@invoiceDataReportMultiStore');
        Route::post('reports/transaction_details', 'ReportController@transactionDetailsReport');
        Route::post('reports/inventory_report', 'ReportController@inventoryReport');
        Route::post('reports/week_sales', 'ReportController@weekSalesReport');
        Route::post('reports/top_products', 'ReportController@topProducts');
        Route::post('reports/top_products_paginate', 'ReportController@topProductsPaginate');
        Route::post('reports/orders/by_employee', 'ReportController@reportOrdersByEmployee');
        Route::post('reports/hourly_details', 'ReportController@hourlyDetailsReport');
        Route::post('reports/week_day_details', 'ReportController@weekDayDetailsReport');
        Route::post('reports/category_sales_details', 'ReportController@categorySalesDetailsReport');
        Route::post('reports/cashier/expenses', 'ReportController@reportCashierExpenses');
        Route::post('reports/checkin', 'ReportCheckinController@reportCheckin');
        Route::post('reports/percent-per-employee-by-category', 'ReportePorcentajeVentasXEmpleado@exportarExcelReportePorcentajeXEmpleadoXCategoria');

        // Components
        // Inventario (module)
        Route::group(['middleware' => 'permissions:inventory'], function () {
            // Crear Categoría (action)
            Route::post('component/category', 'API\Store\ComponentCategoryController@create')
                ->middleware('permissions:create-component-category');
            // CRUD ítems (actions)
            Route::group(['middleware' => 'permissions:crud-items'], function () {
                Route::post('item', 'API\Store\ComponentController@create');
                Route::post('item/update', 'API\Store\ComponentController@update');
                Route::post('item/delete', 'API\Store\ComponentController@delete');
            });
            // Movimientos de inventario (actions)
            Route::group(['middleware' => 'permissions:inventory-movements'], function () {
                Route::get('inventory/actions', 'API\Store\ComponentController@inventoryActions');
                Route::post('inventory/group/upload', 'API\Store\ComponentController@inventoryGroupUpload');
                Route::get('inventory/info/item/{id}', 'API\Store\ComponentController@inventoryPreviousInfoItem');
            });
        });
        Route::get('component/categories', 'API\Store\ComponentCategoryController@getCategoriesByCompany');
        Route::get('metric/units', 'API\Store\MetricUnitController@getMetricUnits');
        Route::post('items', 'API\Store\ComponentController@getComponentsByCompany');
        Route::post('search/items', 'API\Store\ComponentController@search');
        Route::delete('category/{categoryId}', 'API\Store\ComponentController@deleteCategory');
        Route::get('item/{id}', 'API\Store\ComponentController@infoItem');
        Route::get('blind_counts', 'API\Store\ComponentController@getListOfInventories');
        Route::post('update_blind_count', 'API\Store\ComponentController@updateByBlindInventory');
        Route::post('download_blind_count', 'API\Store\ComponentController@BlindInventoryXLS');
        Route::post('items_alert', 'API\Store\ComponentController@getComponentsByCompanywithAlert');
        Route::post('items_normal', 'API\Store\ComponentController@getComponentsByCompanyNormal');
        Route::post('download_inventory_pdf', 'API\Store\ComponentController@DownloadPDFInventory');
        Route::post('download_inventory_csv', 'API\Store\ComponentController@DownloadCSVInventory');
        Route::post('items_normal', 'API\Store\ComponentController@getComponentsByCompanyNormal');
        Route::get('inventory/sync', 'API\Store\ComponentController@inventorySync');

        // Products
        Route::post('product/category', 'API\Store\ProductCategoryController@create');
        Route::put('product/category', 'API\Store\ProductCategoryController@update');
        Route::delete('product/category/{categoryId}', 'API\Store\ProductCategoryController@delete');
        Route::get(
            'product/categories/section/{id}/{deleted}',
            'API\Store\ProductCategoryController@getProductCategoriesBySection'
        );
        Route::post('product/categories/reorder', 'API\Store\ProductCategoryController@reorderPrioritiesProductCategories');
        Route::post('product/specification', 'API\Store\SpecificationController@create');
        Route::post('product', 'API\Store\ProductController@create');
        Route::post('products', 'API\Store\ProductController@getProductsByCompany');
        Route::post('product/delete', 'API\Store\ProductController@delete');
        Route::get('product/{id}', 'API\Store\ProductController@infoProduct');
        Route::post('product/update', 'API\Store\ProductController@update');
        Route::post('products/ask/instruction', 'API\Store\ProductController@allProductsAskInstructions');
        Route::post('products/import', 'API\Store\ProductController@importExcelMenu');
        Route::post('products/section', 'API\Store\ProductController@getProductsBySection');
        Route::get('product/info/{id}', 'API\Store\ProductController@infoProductMenu');
        // Specifications
        Route::post('search/specifications', 'API\Store\SpecificationCategoryController@search');
        Route::post('specification/categories', 'API\Store\SpecificationCategoryController@getSpecificationsBySection');
        Route::post('specification/category/delete', 'API\Store\SpecificationCategoryController@delete');
        Route::post('specification/category/enable', 'API\Store\SpecificationCategoryController@enable');
        Route::get('specification/{id}', 'API\Store\SpecificationCategoryController@infoSpecification');
        Route::post('specification/update', 'API\Store\SpecificationController@update');
        Route::post(
            'specification/categories/reorder',
            'API\Store\SpecificationCategoryController@reorderPrioritiesSpecCategory'
        );
        // Taxes
        Route::post('search/store_taxes', 'API\Store\StoreTaxController@search');
        Route::resource('store_taxes', 'API\Store\StoreTaxController')->except(['create', 'edit']);
        // Integrations
        Route::get('integrations/delivery', 'API\Store\AvailableMyposIntegrationController@getDeliveryIntegrations');
        Route::get('integrations/product/{id}', 'API\Store\AvailableMyposIntegrationController@getProductIntegrationsById');
        Route::get('integrations', 'API\Store\StoreIntegrationController@getStoreIntegrations');
        Route::get('eats/store/menu', 'API\Store\UberEatsController@getUberEatsStoreMenu');
        Route::post('eats/menu/import', 'API\Store\UberEatsController@createMenuFromUber');
        Route::get('eats/menu/names', 'API\Store\UberEatsController@getNamesFromUberMenus');
        Route::post('eats/store/match/menu', 'API\Store\UberEatsController@saveMatchUberEatsStoreMenu');
        Route::post('eats/store/build_menu', 'API\Store\UberEatsController@buildUberEatsMenu');
        Route::post('eats/sync/menu/all', 'API\Store\UberEatsController@syncAllMenu');
        Route::post('integrations/token_integration_update', 'API\Store\AvailableMyposIntegrationController@updateIntegrationToken');
        Route::post('integrations/tp_integration_update', 'API\Store\AvailableMyposIntegrationController@tpIntegrationConfig');
        Route::get('integrations/create_anton_store', 'API\Store\AvailableMyposIntegrationController@createAntonStore');
        Route::post('integrations/tp_integration_delete', 'API\Store\AvailableMyposIntegrationController@tpIntegrationDelete');

        //Store Controll
        Route::post('stores/employees_paginate', 'SuperAdmin\StoreController@getEmployeesPaginate');
        Route::post('stores/{storeId}/store_employees', 'SuperAdmin\StoreController@createEmployee');
        Route::put('stores/{storeId}/store_employees/{employeeId}', 'SuperAdmin\StoreController@updateEmployee');
        Route::delete('stores/{id}/store_employees/{employeeId}', 'SuperAdmin\StoreController@deleteEmployee');

        // SIIGO
        Route::post('integrations/siigo/set/integration', 'API\Integrations\Siigo\SiigoController@setIntegration');
        Route::get('integrations/siigo/set/actual', 'API\Integrations\Siigo\SiigoController@integration');

        Route::get('integrations/siigo/set/products', 'API\Integrations\Siigo\SiigoController@getProductsToSetIntegration');
        Route::get('integrations/siigo/set/account_groups', 'API\Integrations\Siigo\SiigoController@getAccountGroupsToSetIntegration');
        Route::get('integrations/siigo/set/erp_type', 'API\Integrations\Siigo\SiigoController@getErpDocumentTypesToSetIntegration');

        Route::get('integrations/siigo/getAllProducts', 'API\Integrations\Siigo\SiigoController@getAllProducts');
        Route::post('integrations/siigo/deleteAllProducts', 'API\Integrations\Siigo\SiigoController@deleteAllProducts');
        Route::get('integrations/siigo/get_all_taxes', 'API\Integrations\Siigo\SiigoController@getAllTaxes');
        Route::get('integrations/siigo/get_all_taxes_formatted', 'API\Integrations\Siigo\SiigoController@getAllTaxesFormatted');
        Route::post('integrations/siigo/update_tax_relation', 'API\Integrations\Siigo\SiigoController@updateTaxRelation');

        Route::post('integrations/siigo/upload_products', 'API\Integrations\Siigo\SiigoController@uploadProducts');
        Route::get('integrations/siigo/sync_cashier', 'API\Integrations\Siigo\SiigoController@syncCashier');

        Route::get('integrations/siigo/syncInventories', 'API\Integrations\Siigo\SiigoController@syncInventories');
        Route::get('integrations/siigo/syncTaxes', 'API\Integrations\Siigo\SiigoController@syncTaxes');
        Route::get('integrations/siigo/syncProducts', 'API\Integrations\Siigo\SiigoController@syncProducts');
        Route::post('integrations/siigo/saveInvoice', 'API\Integrations\Siigo\SiigoController@syncNewInvoice');
        Route::get('integrations/siigo/getToken', 'API\Integrations\Siigo\SiigoController@getToken');
        Route::post('integrations/siigo/syncAll', 'API\Integrations\Siigo\SiigoController@syncAll');

        // Cashier Balances
        Route::post('cashier/balance/report', 'API\Store\CashierBalanceController@getReportCashierBalances');
        Route::post('cashier/expenses/report', 'API\Store\CashierBalanceController@getReportCashierExpenses');

        // Sections
        Route::post('sections', 'API\Store\SectionController@getSectionsStore');
        Route::post('section/hide', 'API\Store\SectionController@hideSection');
        Route::post('section/show', 'API\Store\SectionController@showSection');
        Route::post('section/assign', 'API\Store\SectionController@assign');
        Route::post('duplicate/menu', 'API\Store\SectionController@duplicateMenu');
        Route::post('assingn/menu', 'API\Store\SectionController@assignMenu');
        Route::post('section/create', 'API\Store\SectionController@create');
        Route::get('section/{id}', 'API\Store\SectionController@getSection');
        Route::post('section/update', 'API\Store\SectionController@update');
        Route::post('section/target', 'API\Store\SectionController@changeSectionTarget');
        Route::get('upload/uber/menus/{isTesting}', 'API\Store\SectionController@uploadUberMenu');
        Route::get('section/integrations/{id}', 'API\Store\SectionController@getIntegrations');
        Route::get('stores/company', 'API\Store\SectionController@getStoresCompany');
        Route::get('section/discounts/{id}', 'API\Store\SectionController@getMenuDiscounts');
        Route::post('section/discounts/update', 'API\Store\SectionController@updateMenuDiscounts');
        Route::post('upload/mely/menu', 'API\Store\SectionController@uploadMelyMenu');



        // Production
        Route::get('search/elaborate_consumable/{text}', 'API\Store\ProductionController@searchElaborateConsumable');
        Route::post('production_order', 'API\Store\ProductionController@create');
        Route::get('production_order/finished', 'API\Store\ProductionController@listFinishedProductionOrders');
        Route::get('production_order/in_process', 'API\Store\ProductionController@listInProccessProductionOrders');
        Route::get('production_order/needed', 'API\Store\ProductionController@listNeededProductionOrders');
        Route::post('production_order/finish', 'API\Store\ProductionController@finish');
        Route::post('production_order/cancel', 'API\Store\ProductionController@cancel');
        Route::get('production_order/cancel_reasons', 'API\Store\ProductionController@getCancelReasons');
        Route::post('production_order/tirilla', 'API\Store\ProductionController@getTirillaProductionPDF');

        // Conversión de medidas
        Route::get('unit_conversions', 'API\Store\UnitConversionController@getUnitConversions');
        Route::post('unit_conversion', 'API\Store\UnitConversionController@create');

        // Importar stores, Tokens, Ids externos microservicio de deliveries
        Route::get('import/integrations', 'API\Store\AvailableMyposIntegrationController@getIntegrationsOnlyDelivery');
        Route::get('import/stores', 'API\Store\ConfigsStoreController@getDataStores');
        Route::get('import/store/tokens', 'API\Store\StoreIntegrationController@getStoresTokens');
        Route::get('import/store/external_ids', 'API\Store\StoreIntegrationController@getExternalStoreIds');

        // Precios dinámicos
        Route::get('dynamicpricing/rules', 'API\Store\StoreIntegrationController@getDynamicPricingRules');
        Route::post('dynamicpricing/rules', 'API\Store\StoreIntegrationController@storeDynamicPricingRules');
    });

    //Local config Stores
    Route::get('store/configs', 'API\Store\ConfigsStoreController@getConfig');
    Route::get('store/inventory_stores', 'API\Store\ConfigsStoreController@getInventoryStores');
    Route::post('store/configs/switch/is_dk', 'API\Store\ConfigsStoreController@switchIsDK');
    Route::post('store/configs/switch/auto_cashier', 'API\Store\ConfigsStoreController@switchAutoCashier');
    Route::post('store/configs/set/time_zone', 'API\Store\ConfigsStoreController@setTimeZone');
    Route::post('store/configs/set/inventory_store', 'API\Store\ConfigsStoreController@setInventoryStore');
    Route::post('store/configs/auto_cashier/set_times', 'API\Store\ConfigsStoreController@SetTimesAutoCashier');
    Route::post('store/configs/conversion_dollar/update', 'API\Store\ConfigsStoreController@updateDollarConversion');

    Route::post('store/configs/switch/employee_edit', 'API\Store\ConfigsStoreController@switchEmployeesModifyOrders');
    Route::post('store/configs/store_money_format/update', 'API\Store\ConfigsStoreController@updateStoreMoneyFormat');
    Route::group(['middleware' => ['roles:admin,admin_franchise', 'throttle:200,1']], function () {
        /**
         * Super Admin Routes
         * CRUD for Stores and Companies and all related resources
         */
        Route::get('cards', 'SuperAdmin\CompanyController@getCards');
        Route::post('cards', 'SuperAdmin\CompanyController@createCard');
        Route::put('cards/{id}', 'SuperAdmin\CompanyController@updateCard');
        Route::delete('cards/{id}', 'SuperAdmin\CompanyController@deleteCard');
        Route::post('assign_card_to_store', 'SuperAdmin\CompanyController@assignCardToStore');
        Route::post('delete_assign_card_store', 'SuperAdmin\CompanyController@deleteCardStoreAssign');
        Route::get('country_cities', 'SuperAdmin\StoreController@getCountryCitiesData');
        Route::get('cities', 'SuperAdmin\StoreController@getCities');
        Route::post('companies/billing', 'SuperAdmin\CompanyController@getAllWithBilling');
        Route::get('companies', 'SuperAdmin\CompanyController@getAll');
        Route::post('company', 'SuperAdmin\CompanyController@create');
        Route::get('company/search/{text}', 'SuperAdmin\CompanyController@searchCompanies');
        Route::put('company/{id}', 'SuperAdmin\CompanyController@update');
        Route::get('company/{id}/invoices', 'SuperAdmin\CompanyController@getInvoices');
        Route::get('company/{id}/metric-units', 'SuperAdmin\CompanyController@getMetriucUnits');
        Route::post('company/{id}/metric-units', 'SuperAdmin\CompanyController@createMetricUnit');
        Route::put('company/{companyId}/metric-units/{metricId}', 'SuperAdmin\CompanyController@updateMetricUnit');
        Route::delete('company/{companyId}/metric-units/{metricId}', 'SuperAdmin\CompanyController@deleteMetricUnit');
        Route::get('company/{companyId}/billing-details', 'SuperAdmin\CompanyController@getCompanyBillingDetails');
        Route::post('company/{companyId}/billing-details', 'SuperAdmin\CompanyController@updateBillingDetails');
        Route::get('company/{id}/stores', 'SuperAdmin\StoreController@getStoresOfCompany');
        Route::post('company/{id}/stores', 'SuperAdmin\StoreController@createStore');
        Route::put('company/{companyId}/stores/{storeId}', 'SuperAdmin\StoreController@updateCompanyStore');
        Route::delete('company/{companyId}/stores/{storeId}', 'SuperAdmin\StoreController@deleteCompanyStore');
        Route::get('stores/{id}/printers', 'SuperAdmin\StoreController@getPrinters');
        Route::post('stores/{id}/printers', 'SuperAdmin\StoreController@createPrinter');
        Route::put('stores/{storeId}/printers/{printerId}', 'SuperAdmin\StoreController@updatePrinter');
        Route::delete('stores/{storeId}/printers/{printerId}', 'SuperAdmin\StoreController@deletePrinter');
        Route::get('stores/{storeId}/employees', 'SuperAdmin\StoreController@getEmployees');
        Route::post('stores/{storeId}/employees', 'SuperAdmin\StoreController@createEmployee');
        Route::put('stores/{storeId}/employees/{employeeId}', 'SuperAdmin\StoreController@updateEmployee');
        Route::delete('stores/{id}/employees/{employeeId}', 'SuperAdmin\StoreController@deleteEmployee');
        Route::get('stores/{storeId}/admin', 'SuperAdmin\StoreController@getAdminStores');
        Route::post('stores/{storeId}/admin', 'SuperAdmin\StoreController@createAdminStore');
        Route::put('stores/{storeId}/admin/{userId}', 'SuperAdmin\StoreController@updateAdminStore');
        Route::delete('stores/{storeId}/admin/{userId}', 'SuperAdmin\StoreController@deleteAdminStore');
        Route::get('stores/{id}/locations', 'SuperAdmin\StoreController@getLocations');
        Route::post('stores/{id}/locations', 'SuperAdmin\StoreController@createLocation');
        Route::put('stores/{storeId}/locations/{locationId}', 'SuperAdmin\StoreController@updateLocation');
        Route::delete('stores/{storeId}/locations/{locationId}', 'SuperAdmin\StoreController@deleteLocation');
        Route::get('stores/{storeId}/taxes', 'SuperAdmin\StoreController@getTaxes');
        Route::post('stores/{storeId}/taxes', 'SuperAdmin\StoreController@createTax');
        Route::put('stores/{storeId}/taxes/{taxId}', 'SuperAdmin\StoreController@updateTax');
        Route::delete('stores/{storeId}/taxes/{taxId}', 'SuperAdmin\StoreController@deleteTax');
        Route::get('stores/{storeId}/spots', 'SuperAdmin\StoreController@getSpots');
        Route::get('store/spot_types', 'SuperAdmin\StoreController@getSpotTypes');
        Route::post('stores/{storeId}/spots', 'SuperAdmin\StoreController@createSpot');
        Route::put('stores/{storeId}/spots/{spotId}', 'SuperAdmin\StoreController@updateSpot');
        Route::delete('stores/{storeId}/spots/{spotId}', 'SuperAdmin\StoreController@deleteSpot');
        Route::get('stores/{storeId}/cards', 'SuperAdmin\StoreController@getCards');
        Route::get('stores/{storeId}/config', 'SuperAdmin\StoreController@getStoreConfig');
        Route::post('stores/{storeId}/config', 'SuperAdmin\StoreController@updateStoreConfig');
        // Route::post('store/configs/set/time_zone', 'API\Store\ConfigsStoreController@setTimeZone');
        Route::get('stores/printer/actions', 'SuperAdmin\StoreController@getPrinterActions');
        // Billing (module)
        Route::group(['middleware' => 'permissions:billing'], function () {
            Route::get('subscriptions/products', 'SuperAdmin\SubscriptionController@getProducts');
            Route::post('subscriptions/products', 'SuperAdmin\SubscriptionController@createProduct');
            Route::get('discounts', 'SuperAdmin\SubscriptionController@getDiscounts');
            Route::post('discounts', 'SuperAdmin\SubscriptionController@createDiscount');
        });
        Route::get('subscriptions/plans', 'SuperAdmin\SubscriptionController@getPlans');
        Route::get('company/search/{text}', 'SuperAdmin\CompanyController@searchCompanies');
        Route::get('company/{id}', 'SuperAdmin\CompanyController@get');
    });

    /**
     *  Admin Franchises
     */

    Route::group(['middleware' => 'roles:admin_franchise'], function () {
        Route::group(['middleware' => 'permissions:manage-franchises'], function () {
            Route::get('franchises', 'FranchiseController@getAll');
            Route::get('franchises/{id}', 'FranchiseController@getById');
            Route::post('franchises/store', 'FranchiseController@createFranchiseWithStore');
        });
    });

    // Company Dashboard
    Route::get('company_dashboard/company', 'CompanyDashboardController@getCompany');
    
    Route::post('company_dashboard/top_products', 'CompanyDashboardController@getTopProducts');
    Route::post('company_dashboard/sales_by_payment', 'CompanyDashboardController@getSalesByPayment');
    Route::post('company_dashboard/active_spots', 'CompanyDashboardController@getActiveSpots');
    Route::post('company_dashboard/food_count_type', 'CompanyDashboardController@getFoodCountType');
    Route::post('company_dashboard/reports/products/chart', 'ReporteDeProductos@reportePorDia');
    Route::post('company_dashboard/reports/products/table', 'ReporteDeProductos@reportePorProducto');
    Route::post('company_dashboard/reports/products/excel', 'ReporteDeProductos@exportarReporte');
    Route::post('company_dashboard/reports/toppings/table', 'ReporteDeEspecificaciones@getTableData');
    Route::post('company_dashboard/reports/toppings/excel', 'ReporteDeEspecificaciones@exportExcel');
    Route::post('company_dashboard/reports/toppings/product/table', 'ReporteDeEspecificaciones@getToppingsByProduct');
    Route::post('company_dashboard/reports/toppings/product/excel', 'ReporteDeEspecificaciones@getToppingsByProductExcel');

    Route::post('company_dashboard/reports/providers/invoices', 'InvoiceProviderReport@getInvoices');
    Route::post('company_dashboard/reports/providers/invoices/excel', 'InvoiceProviderReport@getInvoicesExcel');
    Route::post('company_dashboard/reports/providers/invoices/details_cost', 'InvoiceProviderReport@getDetailsCost');
    Route::post('company_dashboard/reports/providers/invoices/data_categories', 'InvoiceProviderReport@getDataCategories');
    Route::post('company_dashboard/reports/providers/invoices/data_providers', 'InvoiceProviderReport@getDataProveedores');
    Route::post('company_dashboard/reports/providers/invoices/data_product', 'InvoiceProviderReport@getConsumeProvidersOrProduct');
    Route::post('company_dashboard/reports/providers/invoices/components', 'InvoiceProviderReport@getComponents');

    Route::put('company_dashboard/configs/inventory/lower_limit', 'API\Store\ConfigsStoreController@toggleZeroLowerLimit');
    Route::put('company_dashboard/configs/inventory/restrictive', 'API\Store\ConfigsStoreController@toggleRestrictiveStock');
    /**
     * LOGS AUDIT REPORT ROUTES
     */
    Route::get('logs-change-payment/{page}/{startDate}/{endDate}', 'LogsReportController@getPaymentChangeLogs');
    Route::get('logs-revoke-orders/{page}/{startDate}/{endDate}', 'LogsReportController@getOrderRevokeLogs');
    Route::get('logs-products-orders/{page}/{startDate}/{endDate}', 'LogsReportController@getProductsOrderLogs');
    Route::get('logs-changes-products-orders/{modelId}', 'LogsReportController@getChangesProductOrderLogs');
    Route::get('logs-products-track/{modelId}/{productDetailId}/{instruction?}', 'LogsReportController@getTrackProductLogs');
    Route::get('logs-reprint-orders/{page}/{startDate}/{endDate}', 'LogsReportController@getReprintOrderLogs');

    // Didi
    Route::get('upload/didi/menu/{isTesting}', 'API\Store\SectionController@uploadDidiMenu');
    Route::get('didi/setup', 'API\Store\StoreIntegrationController@setUpDidi');

    // Uber
    Route::post('uber/setup', 'API\Store\StoreIntegrationController@setUpUber');

    // iFood
    Route::get('upload/ifood/menu/{isTesting}/{id}', 'API\Store\SectionController@uploadIFoodMenu');
    Route::get('import/ifood/menu/{id}', 'API\Store\SectionController@importIFoodMenu');

    // Goals
    Route::post('goals/create', 'GoalController@createGoal');
    Route::get('goals/employees', 'GoalController@getEmployeesGoals');
    Route::get('goals/stores/{storeId}', 'GoalController@getStoresGoals');
    Route::get('goals/get_types', 'GoalController@getGoalTypes');
    Route::get('goals/get_stores/{onlySelfStore}', 'GoalController@getStores');
    Route::get('goals/get_employees/{storeId}', 'GoalController@getEmployeesByStore');
    Route::get('goals/get_categories/{storeId}', 'GoalController@getCategoriesByStore');
    Route::get('goals/get_products/{categoryId}', 'GoalController@getProductsByCategory');
    Route::post('goals/update', 'GoalController@updateGoal');
    Route::delete('goals/{id}', 'GoalController@deleteGoal');

    

    /**
     * Aloha
     */

    Route::get('aloha/sync/menu', 'AlohaController@syncAlohaMenu');
    Route::post('aloha/sync/post_menu', 'AlohaController@syncAlohaMenu');
    /**
     *  Components
     */

    Route::get('components_with_stock', 'ComponentController@getComponentsWithStock');

    /**
     *  Providers
     */

    Route::post('providers/create', 'ProviderController@createProvider');
    Route::get('providers', 'ProviderController@getProviders');


    /**
     *  Invoice Provider
     */

    Route::post('invoice_provider', 'InvoiceProviderController@createInvoiceProvider');

    /*
     * Billing
     */

    Route::get('billing/company/invoices', 'BillingCompanyController@getInvoices');

    /**
     *  Stripe
     */

    Route::post('stripe/company/autobilling', 'StripeCompanyController@switchAutoBilling');
    Route::get('stripe/company', 'StripeCompanyController@getInfo');
    Route::post('stripe/company/cards', 'StripeCompanyController@createCard');
    Route::delete('stripe/company/cards', 'StripeCompanyController@removeCard');
    Route::post('stripe/company/cards/default', 'StripeCompanyController@setDefaultCard');
    Route::post('stripe/company/invoice/payment', 'StripeCompanyController@registerInvoicePayment');

    Route::post('stripe/hooks', 'StripeHookController@hooks');

    /**
     *  PayU
     */

    // Route::post('payu/company/autobilling', 'StripeCompanyController@switchAutoBilling');
    Route::get('payu/company', 'PayUCompanyController@getInfo');
    // Route::post('payu/company/cards', 'StripeCompanyController@createCard');
    // Route::delete('payu/company/cards', 'StripeCompanyController@removeCard');
    // Route::post('payu/company/cards/default', 'StripeCompanyController@setDefaultCard');

    /**
     * Billing
     */
    
    Route::post('facturama/global_invoice', 'API\Integrations\Facturama\FacturamaController@getGlobalInvoice');
});

/* RESOURCES ROUTES */
Route::resource('orders', 'OrderController');
Route::resource('customers', 'CustomerController');

/* EMPLOYEES ROUTES */
Route::get('employee/get_user_employee', 'EmployeeController@getUserEmployee');
Route::get('employee/get_coworkers', 'EmployeeController@getCoworkers');
Route::get('employee/getIntegrations', 'EmployeeController@getIntegrations');
Route::get('employee/taxes-categories', 'EmployeeController@getTaxesCategories');
Route::get('employee/getDocumentTypes/{integration_name}', 'EmployeeController@getDocumentTypes');
Route::get('employee/getCities/{integration_name}', 'EmployeeController@getCities');
Route::post('employee/check_pin_code', 'EmployeeController@checkPinCode');

/* INTEGRATIONS */
Route::post('rappi/employee/getPassword', 'IntegrationsController@getRappiPassEmployee');
Route::post('rappi/employee/getOrders', 'IntegrationsController@getRappiOrders');
Route::post('rappi/v1/set_order', 'IntegrationsController@setRappiOrderEmitted');
Route::get('rappi/store/get_menu', 'IntegrationsController@getRappiMenu');
Route::post('mely/orders/post', 'IntegrationsController@postMelyOrder');

// Facturama
Route::get('facturama/get_fiscal_regimens', 'API\Integrations\Facturama\FacturamaController@listFiscalRegimens');
Route::get('facturama/get_products_and_services', 'API\Integrations\Facturama\FacturamaController@listProductsAndServices');
Route::post('facturama/upload_csd', 'API\Integrations\Facturama\FacturamaController@uploadCSD');
Route::post('facturama/search_folio', 'API\Integrations\Facturama\FacturamaController@searchFolio');
Route::post('facturama/send_folio', 'API\Integrations\Facturama\FacturamaController@sendFolio');
Route::post('facturama/get_stores_by_subdomain', 'API\Integrations\Facturama\FacturamaController@getStoresBySubdomain');

// Rappipay
Route::get('test', 'Auth\LoginController@testJob');
Route::post('send_datil_force/invoices', 'InvoiceController@forceSendInvoiceToDatil');

Route::post('rappi_pay_kiosko/webhook', 'RappiPayKioskoController@webhook');
Route::post('rappi_pay_kiosko/get_qr', 'RappiPayKioskoController@getQR');
Route::post('rappi_pay_kiosko/cancel_order', 'RappiPayKioskoController@cancelOrderInRappi');
Route::post('rappi_pay_kiosko/check_order_status', 'RappiPayKioskoController@checkOrderStatus');
Route::post('rappi_pay_kiosko/set_integration', 'RappiPayKioskoController@setRappiPayKioskoIntegration');
Route::get('rappi_pay_kiosko/integration_details', 'RappiPayKioskoController@getDetailsRappiPayKioskoIntegration');
Route::delete('rappi_pay_kiosko/disable_integration', 'RappiPayKioskoController@disableIntegration');

/* SUPPORT */
Route::post('send_datil_force/invoices', 'InvoiceController@forceSendInvoiceToDatil');
Route::get('get_rappi_orders_aws', 'IntegrationsController@getRappiOrdersJob');

Route::post('report/drink_food/{id_store}', function (Request $request) {
    return $idStore = $request->id_store;
    $from = date('2020-02-01');
    $to = date('2020-02-29');
    $orders = Order::where('store_id', $idStore)->whereBetween('created_at', [$from, $to])->get();

    $foodSutotal = 0;
    $foodTotal = 0;
    $foodTax = 0;

    $drinkSutotal = 0;
    $drinkTotal = 0;
    $drinkTax = 0;

    foreach ($orders as $order) {
        $productDetail = Helper::getDetailsUniqueGroupedByCompoundKey($order->orderDetails);

        foreach ($productDetail as $product) {

            if ($product['product_detail']['product']['type_product'] === "food") {
                $beforeTax  = (int) $product['tax_values']['no_tax'];
                $withTax    = (int) $product['tax_values']['with_tax'];
                $valTax     = $withTax - $beforeTax;

                $foodSutotal += $beforeTax;
                $foodTotal += $beforeTax + $valTax;
                $foodTax += $valTax;
            }

            if ($product['product_detail']['product']['type_product'] === "drink") {
                $beforeTax  = (int) $product['tax_values']['no_tax'];
                $withTax    = (int) $product['tax_values']['with_tax'];
                $valTax    = $withTax - $beforeTax;

                $drinkSutotal += $beforeTax;
                $drinkTotal += $beforeTax + $valTax;
                $drinkTax += $valTax;
            }
        }
    }

    return [
        "foodSutotal" => Helper::bankersRounding($foodSutotal / 100, 2),
        "foodTotal" => Helper::bankersRounding($foodTotal / 100, 2),
        "foodTax" => Helper::bankersRounding($foodTax / 100, 2),
        "" => "",
        "drinkSutotal" => Helper::bankersRounding($drinkSutotal / 100, 2),
        "drinkTotal" => Helper::bankersRounding($drinkTotal / 100, 2),
        "drinkTax" => Helper::bankersRounding($drinkTax / 100, 2)
    ];
});

Route::get('dispatch_autocashier', function () {
    dispatch(new CheckMenuSchedules());
});

Route::get('test_autocashier', 'API\Store\ConfigsStoreController@unitTestingAutoCashier');
Route::get('ping', 'API\Store\ConfigsStoreController@pingTest');

Route::post('fix-rappi-orders', 'Probes@index');

Route::post('mp-webhook', 'API\Integrations\MercadoPago\MercadoPagoWebHook@hook');
Route::get('mp-check-abandoned-orders', 'API\Integrations\MercadoPago\MercadoPagoWebHook@changeStatusForAbandonedOrders');

Route::post('mercado-pago/order/create', 'API\Integrations\MercadoPago\MercadoPagoController@createOrder');
Route::post('mercado-pago/order/check', 'API\Integrations\MercadoPago\MercadoPagoController@checkOrderStatus');
Route::post('mercado-pago/merchant_order/check', 'API\Integrations\MercadoPago\MercadoPagoController@checkMerchantOrderStatus');
Route::post('mercado-pago/order/delete', 'API\Integrations\MercadoPago\MercadoPagoController@deleteOrder');
Route::post('mercado-pago/payment/refund-complete', 'API\Integrations\MercadoPago\MercadoPagoController@refundCompletePayment');
Route::post('mercado-pago/integration/set', 'API\Integrations\MercadoPago\MercadoPagoController@setIntegration');
Route::get('mercado-pago/cashiers', 'API\Integrations\MercadoPago\MercadoPagoController@getAllCashiers');

Route::get('billing-subs/all-companies', 'API\Store\SubscriptionBillingController@checkPlansByCompany');

Route::group(['prefix' => 'job', 'middleware' => ['awsJobOrigin', 'throttle:200,1']], function () {
    Route::get('dark_kitchen/check_menus', 'API\JobControllers\DarkKitchen\CheckMenuSchedules@checkMenus');

    Route::get('dynamic_pricing/enable', 'API\JobControllers\DynamicPricing\EnableDynamicPricingJob@enableDynamicPricing');
    Route::get('dynamic_pricing/disable', 'API\JobControllers\DynamicPricing\DisableDynamicPricingJob@disableDynamicPricing');

    Route::get('ifood/get_stores_orders', 'API\JobControllers\iFood\GetStoresOrders@getOrders');

    Route::get('integrations/refresh_uber', 'API\JobControllers\Integrations\UberRefresh@refreshToken');
    Route::get('integrations/refresh_didi', 'API\JobControllers\Integrations\DidiRefresh@refreshToken');

    Route::get('mails/send_summary_mails', 'API\JobControllers\Mails\SendSalesHIPOSummaryMailJob@sendSummaryMails');

    Route::get('production/needed_by_store', 'API\JobControllers\Production\SearchStoresProductionNeeded@searchNeeded');

    Route::get('orders/cancel', 'API\JobControllers\Orders\OrderCancel@cancelOrder');
});
