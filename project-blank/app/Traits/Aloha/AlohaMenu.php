<?php

namespace App\Traits\Aloha;
use Log;

// Models
use App\Store;
use App\StoreIntegrationId;
use App\StoreIntegrationToken;
use App\AvailableMyposIntegration;
use App\Section;
use App\SectionIntegration;

// Helpers
use App\Traits\Aloha\AlohaRequests;
use App\Traits\myPOSMenu\MyposMenu;
use App\Helper;

// Jobs
use App\Jobs\MenuMypos\CreateAlohaElementJob;
use App\Jobs\MenuMypos\EmptyJob;

trait AlohaMenu
{
    use AlohaRequests, MyposMenu;
    public $channelLog = null;
    public $channelSlackDev = null;
    public $baseUrl = null;
    public $client = null;
    public $browser = null;

    public function initVarsAlohaMenu($channel, $slack, $baseUrl, $browser)
    {
        $this->channelLog = $channel;
        $this->channelSlackDev = $slack;
        $this->baseUrl = $baseUrl;
        $this->browser = $browser;

        $this->initVarsAlohaRequests(
            $channel,
            $slack,
            $baseUrl,
            $browser
        );
    }

    /**
     * Crear Menú en myPOS
     *
     * Crear el menú en myPOS a partir del menú de Aloha.
     *
     * @param Store $store Tienda de la cual se va a obtener el menú
     *
     * @return array Array con el estado final de la operación(0: error, 1: éxito)
     *
     */
    public function createStoreMenu(Store $store, $excel=null)
    {
        $alohaData = AvailableMyposIntegration::where(
            'code_name',
            AvailableMyposIntegration::NAME_ALOHA
        )->first();
        if (is_null($alohaData)) {
            return ([
                "message" => "No se tiene disponible la integración con Aloha",
                "code" => 0
            ]);
        }

        $storeToken = StoreIntegrationToken::where('store_id', $store->id)
            ->where('integration_name', AvailableMyposIntegration::NAME_ALOHA)
            ->where('type', 'pos')
            ->first();
        if (is_null($storeToken)) {
            return ([
                "message" => "Esta tienda no tiene token de Aloha",
                "code" => 0
            ]);
        }

        $storeIntegrationId = StoreIntegrationId::where('store_id', $store->id)
            ->where('integration_id', $alohaData->id)
            ->first();
        if (is_null($storeIntegrationId)) {
            return ([
                "message" => "Esta tienda no está configurada para usar Aloha",
                "code" => 0
            ]);
        }

        // Obteniendo del menú de Aloha
        $resultDetails = $this->getMenuStore(
            $storeToken->token,
            $storeIntegrationId,
            $store->name,
            $excel
        );
        
        if ($resultDetails["status"] == 0) {
            // Falló en obtener la info del menú
            return ([
                "message" => $resultDetails["data"],
                "code" => 0
            ]);
        }
        // Información de la orden
        $alohaMenu = json_decode(json_encode($resultDetails["data"]));

        $createMenuJobs = [];
        // Obteniendo el menú que tiene activado la integración con Aloha
        $storeSections = Section::where('store_id', $store->id)->get();
        $sectionId = null;
        foreach ($storeSections as $section) {
            $integrations = $section->integrations;
            foreach ($integrations as $integration) {
                if ($integration->integration_id == $alohaData->id) {
                    $sectionId = $section->id;
                }
            }
        }
        // Para el caso de que no exista el menú
        if ($sectionId == null) {
            $isMain = false;
            $sectionMain = Section::where('store_id', $store->id)
                ->where('is_main', 1)
                ->first();
            if (is_null($sectionMain)) {
                $isMain = true;
            }
            $section = new Section();
            $section->store_id = $store->id;
            $section->name = "Menú Aloha";
            $section->subtitle = "";
            $section->is_main = $isMain;
            $section->save();
            $integrationAloha = new SectionIntegration();
            $integrationAloha->section_id = $section->id;
            $integrationAloha->integration_id = $alohaData->id;
            $integrationAloha->save();

            $sectionId = $section->id;
        }

        // Creando Jobs para ingresar los productos al menú
        foreach ($alohaMenu as $categoryAloha) {
            if ($categoryAloha->SALES == "Y") {
                array_push(
                    $createMenuJobs,
                    new CreateAlohaElementJob($categoryAloha, $store, true, $alohaData, $sectionId)
                );
            }
        }
        EmptyJob::withChain($createMenuJobs)->dispatch();

        return ([
            "message" => "Menú creado en myPOS",
            "code" => 1
        ]);
    }
}
