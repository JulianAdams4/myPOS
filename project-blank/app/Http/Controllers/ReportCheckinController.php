<?php

namespace App\Http\Controllers;

use App\Helper;
use App\Checkin;
use Carbon\Carbon;
use App\Traits\AuthTrait;
use App\Traits\LoggingHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Log;

class ReportCheckinController extends Controller
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

  public function reportCheckin(Request $request)
  {

    $store = $this->authStore;

    if (!$request->startDate) {
      $startDate = Carbon::now()->startOfDay();
    } else {
      $startDate = Carbon::parse($request->startDate)->startOfDay();
    }

    if (!$request->endDate) {
      $endDate = Carbon::now()->endOfDay();
    } else {
      $endDate = Carbon::parse($request->endDate)->endOfDay();
    }

    $checkins = Checkin::whereBetween('created_at', [$startDate, $endDate])
      ->whereHas('employee', function ($employee) use ($store) {
        return $employee->where('store_id', $store->id);
      })
      ->orderBy('created_at', 'DESC')
      ->with(['employee'])
      ->get();


    return response()->json(
      [
        "success" => true,
        "status" => "Exito",
        "results" => $checkins
      ],
      200
    );
  }
}
