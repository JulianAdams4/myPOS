<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use App\Customer;
use App\User;
use Log;
use Auth;

class CustomerMiddleware
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
		Log::info("CustomerMiddleware");
		Log::info(Auth::check());
		if(Auth::check()){
			return $next($request);
		}
		throw new AuthenticationException('BLALVALVBLUnauthenticated');
	}
}
