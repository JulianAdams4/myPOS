<?php

namespace App\Http\Controllers\API\JobControllers\DynamicPricing;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

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

class EnableDynamicPricingJob extends Controller
{
    public function __construct()
    {
    }

    /**
     * Funcion principal
     * @return void
     */
    public function enableDynamicPricing()
    {
        try {
            $inactiveRules = DynamicPricingRule::where('active_promotion', false);
            if (config('app.env') == 'production') { // ***
                $inactiveRules = $inactiveRules->whereNotNull('store_id');
            } else {
                $inactiveRules = $inactiveRules->where('store_id', 3);
            }
            $inactiveRules = $inactiveRules->with('timelines')->get();
            $this->log("CheckRules, rules#: " . count($inactiveRules));

            $jobs = [];
            foreach ($inactiveRules as $inactiveRule) {
                $promotionRule = $inactiveRule->rule;
                $deliveries = $promotionRule['enabledDeliveries'];
                $activeDeliveriesPromotion = is_null($inactiveRule->active_deliveries) ? [] : $inactiveRule->active_deliveries;
                $pendingDeliveries = array_diff($deliveries, $activeDeliveriesPromotion);
                $this->log("Deliveries Pendientes: ");
                $this->log($pendingDeliveries);

                if (count($pendingDeliveries) === 0) {
                    $inactiveRule->active_promotion = true;
                    $inactiveRule->save();
                } else {
                    foreach ($pendingDeliveries as $delivery) {
                        // Saltar regla delivery si es que ya fue aplicada recientemente
                        $dateRule = null;
                        $now = Carbon::now();
                        $type = $promotionRule['typeWithinTime'];
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
                            // ->where('rule->enabledDeliveries', $delivery) // *** 'enabledDeliveries' no es una columna
                            ->get();
                        if (count($timelines) > 0) {
                            foreach ($timelines as $timeline) {
                                $affectedOrderIds = $timeline->order_ids;
                                $triggerOrderIds = $timeline->trigger_order_ids;
                                $ignoreIdsTimeline = array_merge($affectedOrderIds, $triggerOrderIds);
                                $ordersIgnore = array_merge($ordersIgnore, $ignoreIdsTimeline);
                            }
                        }
                        $this->log("Fecha inicio: " . $dateRule);
                        $this->log("Fecha fin   : " . $now);

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
                        $this->log("Ordernes cumplen regla: ");
                        $this->log($orderIds);

                        if (count($orderIds) >= $promotionRule['quantity']) {
                            $this->log("EnableDynamicPricing, orders#: ".count($orderIds).', min#: '.$promotionRule['quantity']);
                            $this->log("EnableDynamicPricing, orderIds: ");
                            $this->log($orderIds);

                            $newRule = $promotionRule;
                            $newRule['enabledDeliveries'] = $delivery;
                            $integrationData = AvailableMyposIntegration::where('code_name', $delivery)->first();
                            $this->log("new Rule: ");
                            $this->log($newRule);

                            $type = $promotionRule['action'] == "increase" ? "add" : "subtract";
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

            return response()->json(['status' => "OK"], 200);
        } catch (\Exception $e) {
            Log::info($e);
            $errorMsg = "Fallo al habilitar DynamicPricing";
            $this->log($errorMsg.": ".$e);
            return response()->json(['status' => $errorMsg], 500);
        }
    }

    public function log($message)
    {
        $str = gettype($message) == 'array' ? "[".implode(",", $message)."]" : $message;
        Logging::printLogFile("EnableDynamicPricing: ".$str, 'dynamic_pricing_enable');
    }
}
