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

// Jobs
use App\Jobs\MenuMypos\EmptyJob;
use App\Jobs\DynamicPricing\ChangePriceMenuJob;

// Helpers
use App\Traits\Logs\Logging;

class EnableDynamicPricingJob implements ShouldQueue
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
        $inactiveRules = DynamicPricingRule::where('active_promotion', false)
            ->with('timelines')
            ->get();
        Logging::printLogFile(
            "EnableDynamicPricing checkRules, rules#:  " . count($inactiveRules),
            'dynamic_pricing_enable'
        );
        $jobs = [];
        foreach ($inactiveRules as $inactiveRule) {
            $promotionRule = $inactiveRule->rule;
            $deliveries = $promotionRule['enabledDeliveries'];
            $activeDeliveriesPromotion = is_null($inactiveRule->active_deliveries) ? [] : $inactiveRule->active_deliveries;
            $pendingDeliveries = array_diff($deliveries, $activeDeliveriesPromotion);
            Logging::printLogFile(
                "Deliveries Pendientes: ",
                'dynamic_pricing_enable'
            );
            Logging::printLogFile(
                $pendingDeliveries,
                'dynamic_pricing_enable'
            );
            if (count($pendingDeliveries) === 0) {
                $inactiveRule->active_promotion = true;
                $inactiveRule->save();
            } else {
                foreach ($pendingDeliveries as $delivery) {
                    // Saltar regla delivery si es que ya fue aplicada recientemente
                    $now = Carbon::now();
                    $type = $promotionRule['typeWithinTime'];
                    $dateRule = null;
                    if ($type === 'minute') {
                        $dateRule = Carbon::now()->subMinutes($promotionRule['withinTime']);
                    } elseif ($type === 'hour') {
                        $dateRule = Carbon::now()->subHours($promotionRule['withinTime']);
                    }
                    if (is_null($dateRule)) {
                        continue;
                    }

                    $ordersIgnore = [];
                    $timelines = DynamicPricingRuleTimeline::where('rule_id', $inactiveRule->id)
                        ->whereBetween('created_at', [$dateRule, $now])
                        ->where('rule->enabledDeliveries', $delivery)
                        ->get();
                    if (count($timelines) > 0) {
                        foreach ($timelines as $timeline) {
                            $affectedOrderIds = $timeline->order_ids;
                            $triggerOrderIds = $timeline->trigger_order_ids;
                            $ignoreIdsTimeline = array_merge($affectedOrderIds, $triggerOrderIds);
                            $ordersIgnore = array_merge($ordersIgnore, $ignoreIdsTimeline);
                        }
                    }

                    Logging::printLogFile(
                        "Fecha inicio: " . $dateRule,
                        'dynamic_pricing_enable'
                    );
                    Logging::printLogFile(
                        "Fecha fin: " . $now,
                        'dynamic_pricing_enable'
                    );
                    $orderIds = Order::select('id')
                        ->where('store_id', $inactiveRule->store_id)
                        ->whereBetween('created_at', [$dateRule, $now])
                        ->whereNotIn('id', $ordersIgnore)
                        ->whereHas(
                            'orderIntegrationDetail',
                            function ($integrations) use ($delivery) {
                                $integrations->where('integration_name', $delivery)
                                    ->whereNotNull('external_order_id');
                            }
                        )
                        ->get();
                    Logging::printLogFile(
                        "Ordernes cumplen regla: " . $orderIds,
                        'dynamic_pricing_enable'
                    );
                    if (count($orderIds) >= $promotionRule['quantity']) {
                        Logging::printLogFile(
                            "EnableDynamicPricing, orders#:  " . count($orderIds) . ', min#: ' . $promotionRule['quantity'],
                            'dynamic_pricing_enable'
                        );
                        Logging::printLogFile(
                            "EnableDynamicPricing, orderIds:  " . $orderIds,
                            'dynamic_pricing_enable'
                        );
                        $newRule = $promotionRule;
                        $newRule['enabledDeliveries'] = $delivery;
                        $integrationData = AvailableMyposIntegration::where('code_name', $delivery)
                            ->first();
                        Logging::printLogFile(
                            "new Rule:",
                            'dynamic_pricing_enable'
                        );
                        Logging::printLogFile(
                            $newRule,
                            'dynamic_pricing_enable'
                        );
                        $type;
                        if ($promotionRule['action'] == "increase") {
                            $type = "add";
                        } else {
                            $type = "subtract";
                        }
                        array_push(
                            $jobs,
                            (new ChangePriceMenuJob(
                                $inactiveRule->id,
                                $newRule,
                                $inactiveRule->store_id,
                                $integrationData,
                                $type,
                                $orderIds
                            ))
                        );
                    }
                }
            }
        }
        EmptyJob::withChain($jobs)->dispatch();
    }

    public function failed($exception)
    {
        Log::info("EnableDynamicPricingJob falló");
        Log::info($exception);
    }
}
