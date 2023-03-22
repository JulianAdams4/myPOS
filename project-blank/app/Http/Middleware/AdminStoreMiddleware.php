<?php

namespace App\Http\Middleware;

use Closure;
use Auth;
use Illuminate\Auth\AuthenticationException;

class AdminStoreMiddleware
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
        if(Auth::guard('store-api')->check()){
            if(Auth::guard('store-api')->user()->active){
                return $next($request);
            }
            throw new AuthenticationException('Activa tu cuenta para continuar');            
        }
        throw new AuthenticationException('No autorizado, recurso solo permitido para tiendas');
    }
}
