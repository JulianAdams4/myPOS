<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Traits\DidiFood\DidiFoodOrder;

use Log;


class DidiFoodController extends Controller
{

    use DidiFoodOrder;

    public function webhookOrder(Request $request)
    {
        $this->logIntegration(
            "DidiFoodOrder receiveWeebhookOrder",
            "info"
        );
        
        $bodyRequest = $request->all();
        $this->logIntegration(
            "DidiFoodOrder object",
            "info"
        );
        $this->logIntegration(
            $bodyRequest,
            "info"
        );
        $typeEvent = $bodyRequest["type"];
        $result = null;
 
        switch ($typeEvent) {
            case "orderNew":
                $result = $this->storeOrder($bodyRequest);
                break;
            case "orderCancel":
                $result = $this->cancelOrder($bodyRequest);
                break;
            case "orderRefund":
                // Pendiente
                $result = [
                    "message" => "Funcionalidad pendiente",
                    "code" => 0
                ];
                break;
            case "orderFinish":
                // Pendiente
                $result = [
                    "message" => "Funcionalidad pendiente",
                    "code" => 0
                ];
                break;
            case "deliveryStatus":
                // Pendiente
                $result = [
                    "message" => "Funcionalidad pendiente",
                    "code" => 0
                ];
                break;
            default:
                break;
        }
        $this->logIntegration(
            "Result Trait DidiOrder",
            "info"
        );
        $this->logIntegration(
            json_encode($result),
            "info"
        );
        if ($result == null) {
            $responseJSON = [
                "errno" => 1,
                "errmsg" => "Error interno",
                "timestamp" => time()
            ];
        } else {
            $responseJSON = [
                "errno" => $result["code"],
                "errmsg" => $result["message"],
                "timestamp" => time()
            ];
        }
        $this->logIntegration(
            "Response JSON Webhook",
            "info"
        );
        $this->logIntegration(
            json_encode($responseJSON),
            "info"
        );
        return response()->json($responseJSON, 200);
    }
}
