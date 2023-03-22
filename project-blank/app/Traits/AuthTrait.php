<?php

namespace App\Traits;

use App\Employee;
use Auth;

trait AuthTrait
{
    public function getAuth()
    {
        $user = null;
        $employee = null;
        $store = null;
        if (Auth::check()) {
            $user = Auth::user();
            $employee = Employee::where('user_id', $user->id)->with('store')->first();
            if ($employee) {
                $store = $employee->store;
            }
        }
        return [$user, $employee, $store];
    }

    public static function getAuthData()
    {
        $user = null;
        $employee = null;
        $store = null;
        if (Auth::check()) {
            $user = Auth::user();
            $employee = Employee::where('user_id', $user->id)->with('store')->first();
            if ($employee) {
                $store = $employee->store;
            }
        }
        return [$user, $employee, $store];
    }
}
