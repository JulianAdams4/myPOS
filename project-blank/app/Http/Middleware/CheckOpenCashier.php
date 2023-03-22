<?php

namespace App\Http\Middleware;

use Auth;
use Closure;
use App\CashierBalance;

class CheckOpenCashier
{
    public function handle($request, Closure $next)
    {
        $employee = Auth::guard('employee-api')->user();

        if (!$employee->store) {
            return response()->json(
                [
                    'status' => 'Error al obtener el recurso',
                    'results' => null
                ],
                409
            );
        }

        $employee->store->load('currentCashierBalance');
        $cashierBalance = $employee->store->currentCashierBalance;

        if (!$cashierBalance) {
            return response()->json(
                [
                    "status" => "No se ha abierto caja",
                    "results" => null
                ],
                409
            );
        }

        $request->attributes->add(['employee' => $employee]);
        $request->attributes->add(['cashierBalance' => $cashierBalance]);

        return $next($request);
    }
}
