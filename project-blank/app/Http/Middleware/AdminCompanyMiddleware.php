<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Log;
use Auth;

class AdminCompanyMiddleware
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
		if(Auth::guard('company-api')->check()){
			if(Auth::guard('company-api')->user()->active){
				return $next($request);
			}
			throw new AuthenticationException('Activa tu cuenta para continuar');			
		}
		throw new AuthenticationException('No autorizado, recurso solo permitido para compañias');
	}
}
