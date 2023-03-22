<?php

namespace App\Jobs\DynamicPricing;


use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

// Models
use App\DynamicPricingRuleTimeline;
use App\ProductIntegrationDetail;

// Helpers
use App\Traits\Uber\UberRequests;
use App\Traits\Logs\Logging;

class ChangePriceProductJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private $timelineId;
    private $productId;
    private $newPrice;
    private $integrationData;
    private $token;
    private $deliveryStoreId;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($timelineId, $productId, $newPrice, $integrationData, $token, $deliveryStoreId)
    {
        $this->timelineId = $timelineId;
        $this->productId = $productId;
        $this->newPrice = $newPrice;
        $this->integrationData = $integrationData;
        $this->token = $token;
        $this->deliveryStoreId = $deliveryStoreId;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        if ($this->attempts() < 3) {
            $result = null;
            if ($this->integrationData->id == 1) {
                $result = UberRequests::updateItem(
                    $this->token,
                    $this->deliveryStoreId,
                    $this->productId,
                    $this->newPrice
                );
            }

            if ($result['success']) {
                $productIntegration = ProductIntegrationDetail::where('product_id', $this->productId)
                    ->where('integration_name', $this->integrationData->code_name)
                    ->first();
                if (!is_null($productIntegration)) {
                    $productId = $this->productId;
                    $newPrice = $this->newPrice;
                    $timelineId = $this->timelineId;
                    try {
                        $dataJSON = DB::transaction(
                            function () use ($productIntegration, $newPrice, $productId, $timelineId) {
                                $oldPrice = $productIntegration->price;
                                $productIntegration->price = $this->newPrice;
                                $productIntegration->save();

                                $timeline = DynamicPricingRuleTimeline::where('id', $timelineId)
                                    ->sharedLock()
                                    ->first();
                                if (is_null($timeline->disabled_date)) {
                                    $productIds = $timeline->product_ids;
                                    if ($productIds === null) {
                                        $productIds = [];
                                    }
                                    array_push(
                                        $productIds,
                                        [
                                            'id' => $productId,
                                            'price' => $oldPrice
                                        ]
                                    );
                                    $timeline->product_ids = $productIds;
                                }
                                $timeline->save();
                                return true;
                            }
                        );
                        return $dataJSON;
                    } catch (\Exception $e) {
                        Logging::logError(
                            "ChangePriceProductJob updateItemPrice, productId: " . $this->productId,
                            $e->getMessage(),
                            $e->getFile(),
                            $e->getLine(),
                            json_encode([
                                'timeline_id' => $this->timelineId,
                                'product_id' => $this->productId,
                                'new_price' => $this->newPrice,
                                'external_store_id' => $this->deliveryStoreId
                            ])
                        );
                        throw new \Exception('No se pudo actualizar el precio del item 1');
                    }
                }
            } else {
                throw new \Exception('No se pudo actualizar el precio del item 2');
            }
        }
    }

    public function failed($exception)
    {
        Log::info("ChangePriceProductJob falló");
        Log::info($exception);
    }
}
