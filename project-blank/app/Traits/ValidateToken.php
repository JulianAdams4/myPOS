<?php

namespace App\Traits;

use Log;
use Illuminate\Http\Request;
use DateTime;

trait ValidateToken
{
    public function validateToken($date = null)
    {
        if (!is_null($date)) {
            $currentDate = new DateTime();
            $tokenExpireDate = new DateTime($date);
            $isAuthenticated = $tokenExpireDate > $currentDate ? true : false;
            if ($isAuthenticated) {
                return true;
            }
        }
        
        return false;
    }

    public function validateRequestToken(Request $request = null)
    {
        $headerAuth = $request->header('Authorization');
        if (!is_null($headerAuth)) {
            $tokenAuthArr = explode(" ", $headerAuth);
            if (count($tokenAuthArr) == 2) {
                $tokenAuth = $tokenAuthArr[1];
                if (gettype($tokenAuth) == "string") {
                    $payloadArr = explode(".", $headerAuth);
                    if (count($payloadArr) == 3) {
                        $payload = $payloadArr[1];
                        if (gettype($payload) == "string") {
                            return [
                                "validToken" => true,
                                "objectToken" => json_decode(base64_decode($payload), true)
                            ];
                        }
                    }
                }
            }
        }
        return [
            "validToken" => false,
            "objectToken" => null
        ];
    }
}
