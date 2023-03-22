<?php

namespace App\Jobs\IFood;


use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Log;

// Models
use App\StoreTax;
use App\Specification;

// Helpers
use App\Traits\iFood\IfoodRequests;
use App\Helper;
use App\Traits\myPOSMenu\MyposMenu;

class CreateIfoodCategoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, MyposMenu;

    public $categoryData;
    public $ifoodStoreId;
    public $token;
    public $store;
    public $storeConfig;
    public $channel;
    public $integrationData;
    public $sectionId;
    public $slack;
    public $baseUrl;
    public $browser;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(
        $token,
        $ifoodStoreId,
        $store,
        $categoryData,
        $integrationData,
        $sectionId,
        $channel,
        $slack,
        $baseUrl,
        $browser
    ) {
        $this->token = $token;
        $this->ifoodStoreId = $ifoodStoreId;
        $this->store = $store;
        $this->categoryData = $categoryData;
        $this->channel = $channel;
        $this->integrationData = $integrationData;
        $this->sectionId = $sectionId;
        $this->slack = $slack;
        $this->baseUrl = $baseUrl;
        $this->browser = $browser;
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
            $results = IfoodRequests::getProductsCategory(
                $this->token,
                $this->store->name,
                $this->ifoodStoreId,
                $this->categoryData["external_id"]
            );
            if ($results["status"] != 1) {
                return;
            }

            // Para el caso cuando iFood maneje taxes
            // $taxName = null;
            // $taxRate = null;
            // $storeTax = StoreTax::where('store_id', $this->store->id)
            //     ->where('type', "included")
            //     ->where('is_main', 1)
            //     ->first();
            // if (!is_null($storeTax)) {
            //     $taxName = $storeTax->name;
            //     $taxRate = $storeTax->percentage;
            // }

            // Status Sync
            // 0: No creado
            // 1: Creado
            // 2: Por actualizar
            // Procediendo a crear los datos de los productos
            if (isset($results["data"]) && isset($results["data"]["skus"])) {
                foreach ($results["data"]["skus"] as $productIFood) {
                    if ($productIFood["availability"] != "AVAILABLE") {
                        continue;
                    }
                    $productStatusSync = 1;
                    $idProduct = null;
                    if (!isset($productIFood["externalCode"])) {
                        $productStatusSync = 0;
                    } elseif ($productIFood["externalCode"] == "") {
                        $productStatusSync = 0;
                    } else {
                        $idProduct = $productIFood["externalCode"];
                    }
    
                    $price = (float) $productIFood["price"]["value"];
                    $valueWithTax = $price * 100;
                    $valueRound = Helper::bankersRounding($valueWithTax, 4);

                    // Removiendo caracteres especiales
                    $nameDecoded = utf8_decode($productIFood["name"]);
                    $name = utf8_encode($nameDecoded);

                    $description = "";
                    if (isset($productIFood["description"])) {
                        $descriptionDecoded = utf8_decode(substr($productIFood["description"], 0, 189));
                        $description = utf8_encode($descriptionDecoded);
                    }
                    
    
                    $productData = [
                        "status" => $productStatusSync,
                        "external_id" => $productIFood["id"],
                        "id" => $idProduct,
                        "name" => $name,
                        "description" => $description,
                        "image" => "",
                        "position" => $productIFood["sequence"],
                        "price" => $valueRound,
                        "taxName" => null,
                        "taxRate" => null,
                        "modifiers" => []
                    ];
    
                    $modifiersResults = IfoodRequests::getModifiersProduct(
                        $this->token,
                        $this->store->name,
                        $this->ifoodStoreId,
                        $productIFood["id"]
                    );
                    if ($modifiersResults["status"] != 1) {
                        return;
                    }
                    // Procediendo a crear los datos para los grupos de modificadores
                    foreach ($modifiersResults["data"] as $groupModifierIFood) {
                        if ($groupModifierIFood["availability"] != "AVAILABLE") {
                            continue;
                        }
                        $specCatStatusSync = true;
                        $idSpecCat = null;
                        if (!isset($groupModifierIFood["externalCode"])) {
                            $specCatStatusSync = false;
                        } elseif ($groupModifierIFood["externalCode"] == "") {
                            $specCatStatusSync = false;
                        } else {
                            $idSpecCat = $groupModifierIFood["externalCode"];
                        }
                        // Removiendo caracteres especiales
                        $nameDecoded = utf8_decode($groupModifierIFood["name"]);
                        $name = utf8_encode($nameDecoded);

                        $groupModifierData = [
                            "status" => $specCatStatusSync,
                            "external_id" => $groupModifierIFood["id"],
                            "id" => $idSpecCat,
                            "name" => $name,
                            "min" => $groupModifierIFood["minQuantity"],
                            "max" => $groupModifierIFood["maxQuantity"],
                            "position" => $groupModifierIFood["sequence"],
                            "options" => []
                        ];
                        // Procediendo a crear los datos para los modificadores
                        foreach ($groupModifierIFood["options"] as $modifierIFood) {
                            if ($modifierIFood["availability"] != "AVAILABLE") {
                                continue;
                            }
                            $specStatusSync = true;
                            $idSpec = null;
                            if (!isset($modifierIFood["externalCode"])) {
                                $specStatusSync = false;
                            } elseif ($modifierIFood["externalCode"] == "") {
                                $specStatusSync = false;
                            } else {
                                // Esto es para cuando la opción se encuentra en otra categoría,
                                // pero igual se necesita para esta categoría
                                $modifierMypos = Specification::where('id', $modifierIFood["externalCode"])
                                    ->where('specification_category_id', $idSpecCat)
                                    ->first();
                                if (is_null($modifierMypos)) {
                                    $specStatusSync = false;
                                } else {
                                    $idSpec = $modifierIFood["externalCode"];
                                }
                            }
                            $price = (float) $modifierIFood["price"]["value"];
                            $valueWithTax = $price * 100;
                            $value = Helper::bankersRounding($valueWithTax, 4);
                            // Removiendo caracteres especiales
                            $nameDecoded = utf8_decode($modifierIFood["name"]);
                            $name = utf8_encode($nameDecoded);
                            $modifierData = [
                                "status" => $specStatusSync,
                                "external_id" => $modifierIFood["id"],
                                "id" => $idSpec,
                                "name" => $name,
                                "position" => $modifierIFood["sequence"],
                                "price" => $value,
                                "spec_prod_price" => $value,
                            ];
    
                            array_push($groupModifierData['options'], $modifierData);
                        }
                        array_push($productData['modifiers'], $groupModifierData);
                    }
                    array_push($this->categoryData["products"], $productData);
                }
            }

            
            $menuCategory = [];
            array_push($menuCategory, $this->categoryData);

            $this->createAllMenu(
                $menuCategory,
                $this->store,
                $this->sectionId
            );
        }
    }

    public function failed($exception)
    {
        Log::info("CreateIfoodCategoryJob falló");
        Log::info($exception);
    }
}
