<?php

namespace App\Http\Middleware;
use Closure;
use App\Jobs\SimpleLoggerJob;

class SimpleLogger
{
    public function handle($request, Closure $next)
    {   
        $rounting = $request->route();
        $obj = new \stdClass();
        if($rounting->controller){
            if(property_exists($rounting->controller,'authUser'))
                $obj->auth = $rounting->controller->authUser;
            else
                $obj->auth = null;
        }
        $obj->url = $rounting->uri;
        $obj->body = $request->all();
        SimpleLoggerJob::dispatch(json_encode($obj));   
        return $next($request);
    }
}
