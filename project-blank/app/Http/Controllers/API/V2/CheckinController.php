<?php

namespace App\Http\Controllers\API\V2;

use App\Employee;
use App\Checkin;
use App\CheckinType;
use App\Helper;
use App\Http\Controllers\Controller;
use App\Traits\TimezoneHelper;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use App\Traits\LoggingHelper;
use App\Traits\AuthTrait;
use App\Helpers\PrintService\PrintServiceHelper;
use Log;
use App\StoreIntegrationToken;

class CheckinController extends Controller
{

  use AuthTrait, LoggingHelper,TimezoneHelper;
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
  public function checkin(Request $request)
  {
    $pin = $request->pin;
    if (!$pin) $pin = '';

    $now = Carbon::now()->toDateTimeString();
    $store_id = $this->authStore->id;

    $employee = Employee::where('store_id', $store_id)
      ->where('pin_code', $pin)
      ->first();

    if (!$employee) {
      return response()->json(
        [
          "success" => false,
          "status" => "PIN no válido"
        ],
        404
      );
    }

    $lastCheckin = Checkin::where('employee_id', $employee->id)
      ->whereNull('checkout')
      ->first();

    if ($lastCheckin == null) {
      $lastCheckin = Checkin::create([
        'employee_id' => $employee->id,
        'checkin' => $now
      ]);
    } else {
      $lastCheckin->checkout = $now;
    }

    $lastCheckin->save();

    $isEntry = $lastCheckin->checkout == null;

    PrintServiceHelper::printCheckin($isEntry, $employee);

    $printJob = null;
    $request->print_browser = isset($request->print_browser)?$request->print_browser:false;
    if($request->print_browser){
        $printJob = PrintServiceHelper::getCheckinJobs($isEntry, $employee);
    }else{
      PrintServiceHelper::printCheckin($isEntry, $employee);
    }

    return response()->json(
      [
        "success" => true,
        "status" => "Exito",
        "results" => [
          'checkin' => $lastCheckin,
          'employee' => $employee
        ],
        'printerJob'=>$printJob
      ],
      200
    );
  }

  public function checkinOffline(Request $request)
  {
    $pin = $request->pin;
    $employee_id = $request->employee_id;
    if (!$pin) $pin = '';

    $store_id = $this->authStore->id;

    
    $createDate = TimezoneHelper::convertToServerDateTime($request->created_at, $this->authStore);

    $employee = Employee::where('store_id', $store_id)
      ->where('id', $employee_id)
      ->where('pin_code', $pin)
      ->first();

    if (!$employee) {
      return response()->json(
        [
          "success" => false,
          "status" => "PIN no válido"
        ],
        404
      );
    }

    $lastCheckin = Checkin::where('employee_id', $employee->id)
      ->whereNull('checkout')
      ->first();

    if ($lastCheckin == null) {
      $lastCheckin = Checkin::create([
        'employee_id' => $employee->id,
        'created_at' => $createDate,
        'updated_at' => $createDate,
        'checkin' => $createDate
      ]);
    } else {
      $lastCheckin->checkout = $createDate;
    }

    $lastCheckin->save();

    $isEntry = $lastCheckin->checkout == null;

    // Verificar si hay inconsistencias por offline

    PrintServiceHelper::printCheckin($isEntry, $employee);

    return response()->json(
      [
        "success" => true,
        "status" => "Exito",
        "results" => [
          'checkin' => $lastCheckin,
          'employee' => $employee
        ]
      ],
      200
    );
  }
}
