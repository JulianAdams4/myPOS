<?php

namespace App\Jobs\IFood;


use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Log;

// Models
use App\SectionIntegration;

class FinishIfoodUploadMenu implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $sectionIntegration;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($sectionIntegration)
    {
        $this->sectionIntegration = $sectionIntegration;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        if ($this->attempts() < 3) {
            $sectionInt = SectionIntegration::where('id', $this->sectionIntegration->id)->first();
            if (!is_null($sectionInt)) {
                $statusSync = $sectionInt->status_sync;
                $statusSync['finished'] = true;
                $sectionInt->status_sync = $statusSync;
                $sectionInt->save();
            }
        }
    }

    public function failed($exception)
    {
        Log::info("FinishIfoodUploadMenu falló");
        Log::info($exception);
    }
}
