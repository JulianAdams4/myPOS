<?php

namespace App\Http\Controllers\Api\V2;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\PromotionTypes;
use App\Traits\AuthTrait;
use App\Traits\LoggingHelper;
use App\Traits\ValidateToken;

class PromotionTypeController extends Controller
{
    use AuthTrait, ValidateToken, LoggingHelper;
    public $authUser;
    public $authEmployee;
    public $authStore;
    public function __construct()
    {
        $this->middleware('api');
        [$this->authUser, $this->authEmployee, $this->authStore] = $this->getAuth();
        if (!$this->authUser || !$this->authEmployee || !$this->authStore) {
            return response()->json([
                'status' => 'Usuario no autorizado',
            ], 401);
        }
    }
    public function getPromotionTypes(){
        //obtienel los tipos de promociones
        $tipos_promociones= PromotionTypes::get();
        return response()->json(
            [
                'status' => 'Exito',
                'results' => [
                    'data' => $tipos_promociones
                ]
            ],
            200
        );
    }
    public function getDiscountTypes(){
        //obtienel los tipos de descuento
        $tipos_descuentos= PromotionTypes::where('is_discount_type',1)->get();
        return response()->json(
            [
                'status' => 'Exito',
                'results' => [
                    'data' => $tipos_descuentos,
                ]
            ],
            200
        );
    }

}
