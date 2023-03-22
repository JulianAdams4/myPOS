<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Buzz\Browser;
use Buzz\Client\FileGetContents;
use Nyholm\Psr7\Factory\Psr17Factory;

// Helpers
use App\Traits\AuthTrait;
use App\Traits\Aloha\AlohaMenu;

class AlohaController extends Controller
{
    use AuthTrait, AlohaMenu;
    public $authUser;
    public $authStore;
    public $authEmployee;
    public $channelLog = null;
    public $channelSlackDev = null;
    public $baseUrl = null;
    public $client = null;
    public $browser = null;

    public function __construct()
    {
        $this->middleware('api');
        [$this->authUser, $this->authEmployee, $this->authStore] = $this->getAuth();
        if (!$this->authUser || !$this->authEmployee || !$this->authStore) {
            return response()->json([
                'status' => 'Usuario no autorizado',
            ], 401);
        }

        $this->client = new FileGetContents(new Psr17Factory());
        $this->browser = new Browser($this->client, new Psr17Factory());
        $this->channelLog = "aloha_logs";
        $this->channelSlackDev = "#integration_logs_details";
        $this->initVarsAlohaMenu(
            "aloha_logs",
            "#integration_logs_details",
            config('app.aloha_url_api'),
            $this->browser
        );
    }

    public function syncAlohaMenu(Request $request)
    {
        $this->logIntegration(
            "AlohaController syncAlohaMenu",
            "info"
        );
        
        $store = $this->authStore;
        $excel = null;
        if(isset($request->menu)){
            $excel = $request->menu;
        }
        $result = $this->createStoreMenu(
            $store,
            $excel 
        );

        if ($result["code"] == 0) {
            return response()->json([
                'status' => 409,
                'results' => $result
            ], 409);
        }

        return response()->json([
            'status' => 'Menú sincronizado con éxito!',
            'results' => $result
        ], 200);
    }
}
