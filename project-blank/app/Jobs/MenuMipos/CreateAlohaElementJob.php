<?php

namespace App\Jobs\MenuMypos;


use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Log;

// Models
use App\Store;
use App\AvailableMyposIntegration;
use App\ProductExternalId;
use App\ProductCategoryExternalId;
use App\SpecificationExternalId;
use App\SpecificationCategoryExternalId;
use App\Specification;

// Helpers
use App\Traits\Aloha\AlohaRequests;
use App\Traits\myPOSMenu\MyposMenu;
use App\Helper;
use App\Traits\Logs\Logging;

class CreateAlohaElementJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, AlohaRequests, MyposMenu;

    public $categoryAloha;
    public $store;
    public $isAloha;
    public $alohaData;
    public $sectionId;
    private $channelLogCAEJ = "aloha_logs";

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(
        $categoryAloha,
        Store $store,
        $isAloha,
        AvailableMyposIntegration $alohaData,
        $sectionId
    ) {
        $this->categoryAloha = $categoryAloha;
        $this->store = $store;
        $this->isAloha = $isAloha;
        $this->alohaData = $alohaData;
        $this->sectionId = $sectionId;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        if ($this->attempts() < 3) {
            // Status Sync
            // 0: No creado
            // 1: Creado
            // 2: Por actualizar
            $isAllSync = true;
            $indexCategory = 0;
            $customAlohaMenu = [];

            $prodCatStatusSync = 1;
            $idProductCategory = null;
            $prodCatAloha = ProductCategoryExternalId::where('external_id', $this->categoryAloha->ID)
                ->where('integration_id', $this->alohaData->id)
                ->first();
            if (is_null($prodCatAloha)) {
                $prodCatStatusSync = 0;
                $isAllSync = false;
            } else {
                $idProductCategory = $prodCatAloha->product_category_id;
            }
            $prodCatData = [
                "status" => $prodCatStatusSync,
                "external_id" => $this->categoryAloha->ID,
                "id" => $idProductCategory,
                "name" => $this->categoryAloha->NAME,
                "position" => $indexCategory,
                "products" => []
            ];
            $indexProduct = 0;
            foreach ($this->categoryAloha->PRODUCTOS as $product) {
                $productStatusSync = 1;
                $idProduct = null;
                $productAloha = ProductExternalId::where('external_id', $product->ID)
                    ->where('integration_id', $this->alohaData->id)
                    ->first();
                if (is_null($productAloha)) {
                    $productStatusSync = 0;
                    $isAllSync = false;
                } else {
                    $idProduct = $productAloha->product_id;
                }
                $price = (float) $product->PRICE;
                $valueWithTax = $price * 100;
                $valueRound = Helper::bankersRounding($valueWithTax, 4);
                $productData = [
                    "status" => $productStatusSync,
                    "external_id" => $product->ID,
                    "id" => $idProduct,
                    "name" => $product->LONGNAME,
                    "description" => $product->DESCRIPCION,
                    "image" => $product->IMG,
                    "position" => $indexProduct,
                    "price" => $valueRound,
                    "taxName" => $product->TAXNAME,
                    "taxRate" => $product->TAXRATE,
                    "modifiers" => []
                ];
                $indexCategorySpec = 0;
                foreach ($product->MODIFICADORES as $specificationCategory) {
                    $specCatStatusSync = true;
                    $idSpecCat = null;
                    if (!isset($specificationCategory->ID)) {
                        continue;
                    }
                    $specCatAloha = SpecificationCategoryExternalId::where('external_id', $specificationCategory->ID)
                        ->where('integration_id', $this->alohaData->id)
                        ->first();
                    if (is_null($specCatAloha)) {
                        $specCatStatusSync = false;
                        $isAllSync = false;
                    } else {
                        $idSpecCat = $specCatAloha->spec_category_id;
                    }
                    $specCatData = [
                        "status" => $specCatStatusSync,
                        "external_id" => $specificationCategory->ID,
                        "id" => $idSpecCat,
                        "name" => $specificationCategory->LONGNAME,
                        "min" => $specificationCategory->MINUMUM,
                        "max" => $specificationCategory->MAXIMUM,
                        "position" => $indexCategorySpec,
                        "added_options" => [],
                        "options" => []
                    ];
                    $indexSpecification = 0;
                    if (gettype($specificationCategory->ITEMS) == "string") {
                        continue;
                    }
                    foreach ($specificationCategory->ITEMS as $specification) {
                        if (in_array($specification->ITEM->ID, $specCatData['added_options'])) {
                            continue;
                        }
                        $specStatusSync = true;
                        $idSpec = null;
                        $specAloha = SpecificationExternalId::where('external_id', $specification->ITEM->ID)
                            ->where('integration_id', $this->alohaData->id)
                            ->first();
                        if (is_null($specAloha) || $specCatStatusSync == false) {
                            $specStatusSync = false;
                            $isAllSync = false;
                        } else {
                            // Esto es para cuando la opción se encuentra en otra categoría,
                            // pero igual se necesita para esta categoría
                            $specMyposAloha = Specification::where('id', $specAloha->specification_id)
                                ->where('specification_category_id', $idSpecCat)
                                ->first();
                            if (is_null($specMyposAloha)) {
                                $specStatusSync = false;
                                $isAllSync = false;
                            } else {
                                $idSpec = $specAloha->specification_id;
                            }
                        }
                        $price = (float) $specification->ITEM->PRICE;
                        $valueWithTax = $price * 100;
                        $value = Helper::bankersRounding($valueWithTax, 4);
                        $specData = [
                            "status" => $specStatusSync,
                            "external_id" => $specification->ITEM->ID,
                            "id" => $idSpec,
                            "name" => $specification->ITEM->LONGNAME,
                            "position" => $indexSpecification,
                            "price" => $value,
                            "spec_prod_price" => $specification->PRICE,
                        ];
                        $indexSpecification++;
                        array_push($specCatData['options'], $specData);
                        array_push($specCatData['added_options'], $specification->ITEM->ID);
                    }
                    $indexCategorySpec++;
                    array_push($productData['modifiers'], $specCatData);
                }
                $indexProduct++;
                array_push($prodCatData['products'], $productData);
            }
            $indexCategory++;
            array_push($customAlohaMenu, $prodCatData);

            Logging::printLogFile(
                "Menú de Aloha generado",
                $this->channelLogCAEJ
            );
            Logging::printLogFile(
                json_encode($customAlohaMenu),
                $this->channelLogCAEJ
            );
            Logging::printLogFile(
                "******************************************************",
                $this->channelLogCAEJ
            );
            Logging::printLogFile(
                "******************************************************",
                $this->channelLogCAEJ
            );

            $this->createAllMenu(
                $customAlohaMenu,
                $this->store,
                $this->sectionId,
                $this->isAloha ? $this->alohaData->id : null
            );
        }
    }

    public function failed($exception)
    {
        Log::info("CreateAlohaElementJob falló");
        Log::info($exception);
    }
}
