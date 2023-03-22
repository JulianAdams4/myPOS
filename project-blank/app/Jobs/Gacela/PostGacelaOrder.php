<?php

namespace App\Jobs\Gacela;

use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Traits\GacelaIntegration;
use App\Order;
use Log;

class PostGacelaOrder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use GacelaIntegration;

    public $order;

    //public $tries = 5;
    //public $timeout = 90;
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
      $bundle = $this->createOrderBundle($this->order);
      Log::info('bundle');
      Log::info($bundle);
      $this->postOrderToGacela($bundle['order'],$bundle['customer'],$bundle['address']);
    }

}
