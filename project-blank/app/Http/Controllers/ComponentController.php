<?php

namespace App\Http\Controllers;

use App\Component;
use App\Traits\AuthTrait;
use App\Traits\LoggingHelper;
use Illuminate\Http\Request;

class ComponentController extends Controller
{
  use AuthTrait, LoggingHelper;

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

  public function getComponentsWithStock(Request $request)
  {
    $store = $this->authStore;

    $components = Component::with('unit')
      ->has('componentStocks')
      ->whereHas('componentStocks', function ($componentStocks) use ($store) {
        $componentStocks->where('store_id', $store->id);
      })
      ->where('status',1)
      ->get();

    return response()->json([
      'status' => 'Exito',
      'results' => $components
    ], 200);
  }
}
