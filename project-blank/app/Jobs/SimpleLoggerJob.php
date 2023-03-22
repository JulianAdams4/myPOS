<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Traits\LoggerTrait;
use Closure;
use Carbon\Carbon;
class SimpleLoggerJob implements ShouldQueue
{
    
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use LoggerTrait;

    protected $request;

    public function __construct($requestBody){
        $this->request = json_decode($requestBody);
    }

    public function handle(){

        $this->saveLog(
            $this->request->auth,
            $this->request->url,
            $this->request->body
        );
    }
    public function failed(Exception $exception)
    {   error_log("error");
        error_log($exception->getMessage());
    }
}