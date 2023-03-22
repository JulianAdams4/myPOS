<?php

namespace App\Http\Middleware;

use Illuminate\Auth\AuthenticationException;
use Closure;
use Auth;

class CheckAuth
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
        if (Auth::guard('company-api')->check() || 
            Auth::guard('store-api')->check() || 
            Auth::check()) 
        {
            return $next($request);
        }
        throw new AuthenticationException('No autorizado');
    }
}
