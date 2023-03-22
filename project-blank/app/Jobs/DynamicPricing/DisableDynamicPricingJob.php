<?php

namespace App\Jobs\DynamicPricing;


use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Log;
use Carbon\Carbon;

// Models
use App\DynamicPricingRule;
use App\DynamicPricingRuleTimeline;
use App\Order;
use App\AvailableMyposIntegration;
use App\StoreIntegrationToken;
use App\ProductIntegrationDetail;
use App\StoreConfig;

// Jobs
use App\Jobs\MenuMypos\EmptyJob;
use App\Jobs\DynamicPricing\ChangePriceProductJob;

// Helpers
use App\Traits\Logs\Logging;

class DisableDynamicPricingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct()
    {
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $activeRules = DynamicPricingRuleTimeline::whereNull('disabled_date')
            ->with('dynamicPricingRule')
            ->get();
        Logging::printLogFile(
            "DisableDynamicPricingJob checkRules, rules#:  " . count($activeRules),
            'dynamic_pricing_disable'
        );
        $jobs = [];
        foreach ($activeRules as $activeRule) {
            $now = Carbon::now();
            $endDate = new Carbon($activeRule->approximate_disabled_date);
            if ($now->lt($endDate)) {
                continue;
            }

            $promotionRule = $activeRule->rule;
            $integrationData = AvailableMyposIntegration::where('code_name', $promotionRule['enabledDeliveries'])
                ->first();
            if (is_null($integrationData)) {
                continue;
            }

            $integrationToken = StoreIntegrationToken::where('store_id', $activeRule->dynamicPricingRule->store_id)
                ->where('integration_name', $promotionRule['enabledDeliveries'])
                ->first();
            if (is_null($integrationToken)) {
                continue;
            }

            $deliveryStoreId = null;
            if ($integrationData->id == 1) {
                $config = StoreConfig::where('store_id', $activeRule->dynamicPricingRule->store_id)->first();
                if (!is_null($config)) {
                    $deliveryStoreId = $config->eats_store_id;
                }
            }
            if (is_null($deliveryStoreId)) {
                continue;
            }

            foreach ($activeRule->product_ids as $productData) {
                $prodInt = ProductIntegrationDetail::where('product_id', $productData['id'])
                    ->where('integration_name', $promotionRule['enabledDeliveries'])
                    ->first();
                if (is_null($prodInt)) {
                    continue;
                }

                $newPrice;
                if ($promotionRule['action'] == "increase") {
                    $newPrice = $prodInt->price - ($productData['price'] * $promotionRule['percentage'] / 100);
                } else {
                    $newPrice = $prodInt->price + ($productData['price'] * $promotionRule['percentage'] / 100);
                }

                array_push(
                    $jobs,
                    (new ChangePriceProductJob(
                        $activeRule->id,
                        $productData['id'],
                        (int) $newPrice,
                        $integrationData,
                        $integrationToken->token,
                        $deliveryStoreId
                    ))
                );
            }

            $now = Carbon::now();
            $orderIds = Order::select('id')
                ->where('store_id', $activeRule->dynamicPricingRule->store_id)
                ->whereBetween('created_at', [$activeRule->enabled_date, $now])
                ->whereHas(
                    'orderIntegrationDetail',
                    function ($integrations) use ($promotionRule) {
                        $integrations->where('integration_name', $promotionRule['enabledDeliveries'])
                            ->whereNotNull('external_order_id');
                    }
                )
                ->get();
            $ids = [];
            foreach ($orderIds as $orderId) {
                array_push($ids, $orderId->id);
            }

            $activeRule->disabled_date = $now;
            $activeRule->order_ids = $ids;
            $activeRule->save();

            $pricingRule = DynamicPricingRule::where('id', $activeRule->rule_id)->withTrashed()->first();
            $activeDeliveries = $pricingRule->active_deliveries;
            $index = array_search($promotionRule['enabledDeliveries'], $activeDeliveries);
            if (!is_bool($index)) {
                array_splice($activeDeliveries, $index, 1);
                $pricingRule->active_deliveries = $activeDeliveries;
                $pricingRule->active_promotion = false;
                $pricingRule->save();
            }
        }
        EmptyJob::withChain($jobs)->dispatch();
    }

    public function failed($exception)
    {
        Log::info("DisableDynamicPricingJob falló");
        Log::info($exception);
    }
}
