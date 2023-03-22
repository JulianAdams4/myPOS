<?php

namespace App\Jobs\IFood;


use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Log;

// Models
use App\Store;

// Helpers
use App\Traits\iFood\IfoodRequests;

class UpdateIfoodCategoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $category;
    public $token;
    public $storeName;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($token, $storeName, $category, $channel, $slack, $baseUrl, $browser)
    {
        $this->category = $category;
        $this->token = $token;
        $this->storeName = $storeName;

        IfoodRequests::initVarsIfoodRequests(
            $channel,
            $slack,
            $baseUrl,
            $browser
        );
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        if ($this->attempts() < 3) {
            IfoodRequests::updateCategory(
                $this->token,
                $this->storeName,
                $this->category
            );
        }
    }

    public function failed(Exception $exception)
    {
        Log::info("UploadIfoodCategoryJob falló");
        Log::info($exception);
    }
}
