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

class ActionLoggerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use LoggerTrait;

    protected $data;

    public function __construct($options)
    {        
        $this->data = $options;
    }

    public function handle()
    {
        $this->saveInteractionLog(
            $this->data['action'],
            $this->data['model'],
            $this->data['user_id'],
            $this->data['model_id'],
            $this->data['model_data']
        );
    }

    public function failed(\Exception $e)
    {
        error_log("[Action Logger Failed]");
        error_log($e->getMessage());
    }
}
