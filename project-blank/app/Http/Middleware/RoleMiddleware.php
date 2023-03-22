<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Auth\AuthenticationException;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next, ...$roles)
    {
        if (Auth::guard('api')->check()) {
            if (in_array(Auth::user()->role->name, $roles) && Auth::user()->active) {
                return $next($request);
            }
        }
        throw new AuthenticationException('Usuario no autorizado.');
    }
}
