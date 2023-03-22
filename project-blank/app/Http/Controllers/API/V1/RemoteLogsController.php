<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Employee;
use App\Traits\AuthTrait;
use Storage;
use Carbon\Carbon;

class RemoteLogsController extends Controller
{
    use AuthTrait;
    
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

    /**
     * Store a newly log resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function storeFileLog(Request $request)
    {
        $employee = $this->authEmployee;
        $store = $employee->store;
        $dt = Carbon::now();
        $date = Carbon::createFromFormat('Y-m-d H:i:s', $dt)->format('m-d-Y--H:i:s');
        $fileContent = "";
        $fileName = "report__store_".$store->id."__employee_".$employee->id."__".$date."__.txt";
        $logs = $request->all();
        foreach ($logs as $log) {
            $fileContent = $fileContent . $log['info'] . "\r\n";
        }
        Storage::disk('local')->put($fileName, $fileContent);
        return response()->json(
            [
                "status" => "Logs guardados",
            ],
            200
        );
    }
}
