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
use App\Section;
use App\StoreIntegrationToken;
use App\StoreConfig;

// Jobs
use App\Jobs\MenuMypos\EmptyJob;
use App\Jobs\DynamicPricing\ChangePriceProductJob;

// Helpers
use App\Traits\Logs\Logging;

class ChangePriceMenuJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private $ruleId;
    private $rule;
    private $storeId;
    private $integrationData;
    private $type;
    private $orderIds;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($ruleId, $rule, $storeId, $integrationData, $type, $orderIds)
    {
        $this->ruleId = $ruleId;
        $this->rule = $rule;
        $this->storeId = $storeId;
        $this->integrationData = $integrationData;
        $this->type = $type;
        $this->orderIds = $orderIds;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $timelines = DynamicPricingRuleTimeline::where('rule_id', $this->ruleId)
            ->whereNotNull('disabled_date')
            ->get();
        $exist = false;
        $deliveryName = $this->rule['enabledDeliveries'];
        foreach ($timelines as $timeline) {
            if ($timeline['enabledDeliveries'] == $deliveryName) {
                $exist = true;
            }
        }
        if (!$exist) {
            Logging::printLogFile(
                "received new Rule:",
                'dynamic_pricing_enable'
            );
            Logging::printLogFile(
                $this->rule,
                'dynamic_pricing_enable'
            );
            $triggerOrderIds = [];
            foreach ($this->orderIds as $orderId) {
                array_push($triggerOrderIds, $orderId->id);
            }
            $timeline = new DynamicPricingRuleTimeline();
            $timeline->rule_id = $this->ruleId;
            $timeline->rule = $this->rule;
            $timeline->enabled_date = Carbon::now();
            $type = $this->rule['typeDurationTime'];
            $dateRule = null;
            if ($type === 'minute') {
                $dateRule = Carbon::now()->addMinutes($this->rule['durationTime']);
            } elseif ($type === 'hour') {
                $dateRule = Carbon::now()->addHours($this->rule['durationTime']);
            }
            $timeline->approximate_disabled_date = $dateRule;
            $timeline->trigger_order_ids = $triggerOrderIds;
            $timeline->save();

            $pricingRule = DynamicPricingRule::where('id', $this->ruleId)->first();
            $activeDeliveries = $pricingRule->active_deliveries;
            if ($activeDeliveries === null) {
                $activeDeliveries = [];
            }
            array_push($activeDeliveries, $deliveryName);
            $pricingRule->active_deliveries = $activeDeliveries;
            $pricingRule->save();

            // Generando los jobs para cambiar los precios de cada producto del menú
            $integrationId = $this->integrationData->id;
            $menus = Section::where('store_id', $this->storeId)
                ->whereHas(
                    'integrations',
                    function ($integration) use ($integrationId) {
                        $integration->where('integration_id', $integrationId);
                    }
                )
                ->with('categories.products.integrations')
                ->get();
            if (count($menus) > 0) {
                $jobs = [];
                $integrationToken = StoreIntegrationToken::where('store_id', $this->storeId)
                    ->where('integration_name', $deliveryName)
                    ->first();
                $deliveryStoreId = null;
                if ($this->integrationData->id == 1) {
                    $config = StoreConfig::where('store_id', $this->storeId)->first();
                    if (!is_null($config)) {
                        $deliveryStoreId = $config->eats_store_id;
                    }
                }
                if (!is_null($integrationToken) && !is_null($deliveryStoreId)) {
                    foreach ($menus as $menu) {
                        $categories = $menu->categories;
                        foreach ($categories as $category) {
                            if ($category->products->count() === 0) {
                                continue;
                            }
                            $products = $category->products;
                            foreach ($products as $product) {
                                $productIntegrations = $product->integrations;
                                $dataIntegration = null;
                                foreach ($productIntegrations as $productIntegration) {
                                    if ($productIntegration->integration_name == $deliveryName) {
                                        $dataIntegration = $productIntegration;
                                    }
                                }
                                if ($dataIntegration == null) {
                                    continue;
                                }
                                $newPrice;
                                if ($type == "subtract") {
                                    $newPrice = $dataIntegration->price - ($dataIntegration->price * $this->rule['percentage'] / 100);
                                } else {
                                    $newPrice = $dataIntegration->price + ($dataIntegration->price * $this->rule['percentage'] / 100);
                                }
                                array_push(
                                    $jobs,
                                    (new ChangePriceProductJob(
                                        $timeline->id,
                                        $product->id,
                                        (int) $newPrice,
                                        $this->integrationData,
                                        $integrationToken->token,
                                        $deliveryStoreId
                                    ))
                                );
                            }
                        }
                    }
                    Logging::printLogFile(
                        "EnableDynamicPricing, ruleId:  " . $this->ruleId . ', delivery: ' . $deliveryName,
                        'dynamic_pricing_enable'
                    );
                    EmptyJob::withChain($jobs)->dispatch();
                }
            }
        }
    }

    public function failed($exception)
    {
        Log::info("ChangePriceMenuJob falló");
        Log::info($exception);
    }
}
