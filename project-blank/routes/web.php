<?php

use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

// Route::get('/', function () {
//     return view('welcome');
// });

Auth::routes();

// Route::get('/home', 'HomeController@index')->name('home');

Route::get(
    '/privacy-policy', function () {
        return view('privacy-policy');
    }
);

Route::get('auth/{provider}', 'Auth\SocialAuthController@redirect');
Route::get('auth/{provider}/callback', 'Auth\SocialAuthController@callback');

Route::get('customer/verify/{token}', 'Auth\VerificationController@verifyCustomer');

Route::get('admin/company/verify/{token}', 'Auth\VerificationController@verifyAdminCompany');

Route::get('admin/store/verify/{token}', 'Auth\VerificationController@verifyStoreCompany');

// Route::view('/', 'landing');

Route::view('/{path?}', 'welcome');


// Callbacks integrations
Route::get('integration/auth/uber_eats/callback', function (Request $request) {
    Log::info("No hacer nada");
    return response()->json([], 200);
});


Route::post('mypos/eats/webhook', function (Request $request) {
    Log::info("UberEats Webhook V1(ignorado)");
    $bodyRequest = $request->getContent();
    $informationObj = json_decode($bodyRequest);
    $eatsStoreId = $informationObj->meta->user_id;
    $eatsOrderId = $informationObj->meta->resource_id;
    Log::info("Uber Eats Store and Order ids");
    Log::info($eatsStoreId);
    Log::info($eatsOrderId);
    return response()->json([], 200);
});

Route::post('mypos/eats/webhook/v2', 'UberEatsControllerV2@receiveWebhookUberOrder');

Route::post('mypos/didi/webhook', 'DidiFoodController@webhookOrder');

Route::post('mypos/stocky/webhook/type3', 'StockyController@webhookOrder');
