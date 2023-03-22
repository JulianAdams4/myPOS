<?php

namespace App\Http\Middleware;

use App\User;
use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Log;

class PermissionMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next, ...$identifiers)
    {
        $permissions = Auth::user()->permissions->pluck('identifier')->toArray();
        return array_intersect($identifiers, $permissions) !== $identifiers ?
            response()->json(['status' => 'No tiene permisos para realizar esta acción'], 403) :
            $next($request);
    }
}
