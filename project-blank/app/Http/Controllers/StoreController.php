<?php

namespace App\Http\Controllers;

use Log;
use App\Store;
use App\Traits\AuthTrait;
use App\Traits\OrderHelper;
use Illuminate\Http\Request;
use App\Helpers\PrintService\PrintServiceHelper;
use App\Traits\ReportHelperTrait;

class StoreController extends Controller
{
  use AuthTrait, ReportHelperTrait, OrderHelper;

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

  public function getVirtualStores()
  {
    $store = $this->authStore;

    $stores = Store::where('id', $store->id)
      ->orWhere('virtual_of', $store->id)
      ->get();

    return response()->json([
      'results' => $stores
    ]);
  }
}
