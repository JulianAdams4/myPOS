<?php

namespace App\Jobs\Gacela;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Jobs\Gacela\PostGacelaOrder;
use App\Order;
use Log;

class SyncPendingOrdersToGacela
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        Log::info('SyncPendingOrdersToGacela');
        $orders = Order::with('customer.user','address','billing')->whereNull('order_token')->get();
        Log::info($orders);
        foreach ($orders as $order) {
          Log::info($order);
          dispatch(new PostGacelaOrder($order));
        }

    }
}
