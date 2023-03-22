<?php

namespace App\Http\Controllers;

use App\SubscriptionInvoices;
use Carbon\Carbon;
use App\Traits\AuthTrait;
use App\Traits\LoggingHelper;
use Illuminate\Http\Request;
use App\Traits\LocalImageHelper;
use App\Traits\AWSHelper;

class BillingCompanyController extends Controller
{
  use AuthTrait, LoggingHelper, LocalImageHelper, AWSHelper;

  public $authUser;
  public $authEmployee;
  public $authStore;

  public function __construct()
  {
    $this->middleware('api');
    [$this->authUser, $this->authEmployee, $this->authStore] = $this->getAuth();
    if (!$this->authUser || !$this->authEmployee || !$this->authStore) {
      return response()->json([
        'status' => 'Usuario no autorizado',
      ], 401);
    }
  }

  public function getInvoices(Request $request)
  {
    $store = $this->authStore;
    $companyId = $store->company_id;

    $to = Carbon::now()->endOfDay();
    $from = Carbon::now()->startOfDay()->subYears(1);

    $invoices = SubscriptionInvoices::whereHas(
      'subscriptionInvoiceDetails.store',
      function ($query) use ($companyId) {
        $query->where('company_id', $companyId);
      }
    )
      ->whereBetween('created_at', [$from, $to])
      ->where('status', SubscriptionInvoices::PAID)
      ->with(['subscriptionInvoiceDetails.plan'])
      ->get();

    $pendingInvoices = SubscriptionInvoices::whereHas(
      'subscriptionInvoiceDetails.store',
      function ($query) use ($companyId) {
        $query->where('company_id', $companyId);
      }
    )
      ->where('status', '<>', SubscriptionInvoices::PAID)
      ->with(['subscriptionInvoiceDetails.plan'])
      ->get();

    return response()->json([
      'status' => "",
      'pendingInvoices' => $pendingInvoices,
      'invoices' => $invoices
    ], 200);
  }
}
