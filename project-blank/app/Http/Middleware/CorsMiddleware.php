<?php

namespace App\Http\Middleware;
use Log;

use Closure;

class CorsMiddleware
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
        // // check if we have an X-Authorization header present
        // if ($auth = $request->header('M-Authorization')) {
        //     $request->headers->set('Authorization', $auth);
        // } else {
        // }
        
        $response = $next($request);
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Access-Control-Allow-Headers', 'X-PINGOTHER, Content-Type, Accept, Authorization, Content-Length, X-Requested-With, M-Authorization, X-Requested-With, Application');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
        return $response;
    }
}
