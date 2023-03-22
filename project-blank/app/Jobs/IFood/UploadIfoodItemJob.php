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
use App\SectionIntegration;

// Helpers
use App\Traits\iFood\IfoodRequests;

class UploadIfoodItemJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $store;
    public $product;
    public $ifoodStoreId;
    public $storeName;
    public $categoryId;
    public $isModifier;
    public $channel;
    public $slack;
    public $baseUrl;
    public $browser;
    public $sectionIntegration;
    public $image;
    public $productId;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(
        $store,
        $ifoodStoreId,
        $storeName,
        $product,
        $categoryId,
        $isModifier,
        $channel,
        $slack,
        $baseUrl,
        $browser,
        $sectionIntegration = null,
        $image = null,
        $productId = null
    ) {
        $this->store = $store;
        $this->product = $product;
        $this->ifoodStoreId = $ifoodStoreId;
        $this->storeName = $storeName;
        $this->categoryId = $categoryId;
        $this->isModifier = $isModifier;
        $this->channel = $channel;
        $this->slack = $slack;
        $this->baseUrl = $baseUrl;
        $this->browser = $browser;
        $this->sectionIntegration = $sectionIntegration;
        $this->image = $image;
        $this->productId = $productId;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        if ($this->attempts() < 3) {
            IfoodRequests::initVarsIfoodRequests(
                $this->channel,
                $this->slack,
                $this->baseUrl,
                $this->browser
            );
            $dataConfig = IfoodRequests::checkIfoodConfiguration($this->store);
            if ($dataConfig["code"] == 200) {
                $filename = null;
                if (!is_null($this->image) && !is_null($this->productId)) {
                    $filename = 'producto_ifood_' . $this->productId;
                    if (!file_exists(public_path().'/products_ifood_images')) {
                        mkdir(public_path().'/products_ifood_images', 0777, true);
                    }
                    try {
                        $contents = file_get_contents($this->image);
                        $imageStr = str_replace('data:image/png;base64,', '', $contents);
                        $file = public_path() . '/products_ifood_images/' . $filename . '.jpg';
                        file_put_contents($file, $imageStr);
                        
                        array_push(
                            $this->product,
                            [
                                "name" => "file",
                                "contents" => fopen($file, 'r')
                            ]
                        );
                    } catch (\Exception $e) {
                        // No se pudo obtener la imagen
                    }
                }
                $status = IfoodRequests::uploadItem(
                    $dataConfig["data"]["integrationToken"]->token,
                    $this->ifoodStoreId,
                    $this->storeName,
                    $this->product,
                    $this->categoryId,
                    $this->isModifier
                );
                if ($status["status"] == 1 && !is_null($this->sectionIntegration)) {
                    $newCount = $this->sectionIntegration->status_sync["items_current"] + 1;
                    $sectionInt = SectionIntegration::where('id', $this->sectionIntegration->id)->first();
                    if (!is_null($sectionInt)) {
                        $statusSync = $sectionInt->status_sync;
                        $statusSync['items_current'] = $newCount;
                        $sectionInt->status_sync = $statusSync;
                        $sectionInt->save();
                    }
                }
                if (!is_null($filename)) {
                    try {
                        unlink(public_path() . '/products_ifood_images/' . $filename . '.jpg');
                    } catch (\Exception $e) {
                        // No se pudo eliminar la imagen
                    }
                }
            }
        }
    }

    public function failed($exception)
    {
        Log::info("UploadIfoodProductJob falló");
        Log::info($exception);
    }
}
