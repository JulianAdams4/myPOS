<?php

namespace App\Http\Middleware;

use App\Employee;
use Closure;
use Illuminate\Support\Facades\Auth;
use Log;
use Illuminate\Auth\AuthenticationException;

class EmployeeMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        if (Auth::guard('employee-api')->check()) {
            return $next($request);
        }
        throw new AuthenticationException('No autorizado, recurso solo permitido para empleados');
    }
}
