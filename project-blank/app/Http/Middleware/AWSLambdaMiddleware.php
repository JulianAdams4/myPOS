<?php

namespace App\Http\Middleware;

use Closure;
use Log;

class AWSLambdaMiddleware
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
        $myposjobsecret = config('app.myposjobsecret');
        $requestSecret = $request->header('MyposJobSecret');
        if ($requestSecret === $requestSecret) {
            return $next($request);
        }
        return response()->json(['status' => 'No autorizado'], 401);
    }
}
