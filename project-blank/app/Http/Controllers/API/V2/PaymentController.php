<?php

namespace App\Http\Controllers\API\V2;

use App\Http\Controllers\Controller;
use App\Employee;
use App\CashierBalance;
use App\StoreConfig;
use App\Traits\AuthTrait;
use App\PaymentType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Payment;
use App\Order;

class PaymentController extends Controller
{
    use AuthTrait;

    public $authUser;
    public $authEmployee;
    public $authStore;
    public $cashierBalance;

    public function __construct()
    {
        $this->middleware('api');
        [$this->authUser, $this->authEmployee, $this->authStore] = $this->getAuth();
        if (!$this->authUser || !$this->authEmployee || !$this->authStore) {
            return response()->json([
                'status' => 'Usuario no autorizado',
            ], 401);
        }
        $this->authStore->load('currentCashierBalance');
        $cashierBalance = $this->authStore->currentCashierBalance;

        if (!$cashierBalance) {
            return response()->json(
                [
                    "status" => "No se ha abierto caja",
                    "results" => null
                ],
                409
            );
        }

        $this->cashierBalance = $cashierBalance;
    }

    public function getCards()
    {
        $store = $this->authStore;
        $store->load('cards');

        return response()->json(
            [
                'status' => 'Tarjetas',
                'results' => $store->cards
            ],
            200
        );
    }

    /**
     * Returns array with suggested payments based on amount received
     */
    public function getPaymentSuggestions(Request $request)
    {
        $store = $this->authStore;

        if (!$store) {
            return response()->json(
                [
                    'status' => 'Error, no existe tienda',
                    'results' => "null"
                ],
                409
            );
        }

        $storeConfig = StoreConfig::where('store_id', $store->id)->first();

        if ($storeConfig->common_bills == null) {
            return response()->json(
                [
                    'status' => 'No existe configuración para esta tienda',
                    'results' => []
                ],
                200
            );
        }

        $commonBills = json_decode($storeConfig->common_bills);

        $suggestedAmounts = array();

        if ($request->format == "cents") {
            $request->amount = $request->amount / 100;
        }

        foreach ($commonBills as $bill) {
            $suggestedAmount = ceil($request->amount / $bill) * $bill;
            if ($suggestedAmount == $request->amount) {
                $suggestedAmount += $bill;
            }

            if ($request->format == "cents") {
                $suggestedAmount = $suggestedAmount * 100;
            }
            array_push($suggestedAmounts, $suggestedAmount);
        }

        return response()->json(
            [
                'status' => 'Sugerencias de Pago',
                'results' => $suggestedAmounts
            ],
            200
        );
    }

    /**
     * Returns array with payment types
     */
    public function getPaymentTypes()
    {
        $user = \Request::get('employee');
        if (!$user) {
            return response()->json([
                'status' => 'No permitido',
                'results' => 'null'
            ], 403);
        }
        try {
            $types = [
                [ 'type' => PaymentType::CASH, 'name' => 'Efectivo' ],
                [ 'type' => PaymentType::DEBIT, 'name' => 'Tarjeta de débito' ],
                [ 'type' => PaymentType::CREDIT, 'name' => 'Tarjeta de crédito' ],
                [ 'type' => PaymentType::RAPPI_PAY, 'name' => 'RappiPay' ],
                [ 'type' => PaymentType::TRANSFER, 'name' => 'Transferencia' ],
                [ 'type' => PaymentType::OTHER, 'name' => 'Otro' ],
                [ 'type' => PaymentType::SODEXHO_PASS, 'name' => 'Sodexho_pass' ],
                [ 'type' => PaymentType::QPASS_PRODUCTO, 'name' => 'Qpass_producto' ],
                [ 'type' => PaymentType::QPASS_VALOR, 'name' => 'Qpass_valor' ],
                [ 'type' => PaymentType::QPASS_LOCAL, 'name' => 'Qpass_local' ],
                [ 'type' => PaymentType::BIG_PASS, 'name' => 'Big_pass' ],
                [ 'type' => PaymentType::CREDITO_EMPLEADOS, 'name' => 'Credito_empleados' ],
                [ 'type' => PaymentType::CREDITO_FRANQUICIA, 'name' => 'Credito_franquicia' ],
                [ 'type' => PaymentType::CREDITO_CLIENTES, 'name' => 'Credito_clientes' ],
                [ 'type' => PaymentType::BONOS_CENTROS, 'name' => 'Bonos_centros' ],
                [ 'type' => PaymentType::CUPONES, 'name' => 'Cupones' ],
                [ 'type' => PaymentType::DEVOLUCION_EFECTIVO, 'name' => 'Devolucion_efectivo' ],
                [ 'type' => PaymentType::CHEQUE, 'name' => 'Cheque' ],
                [ 'type' => PaymentType::DESCUENTOS, 'name' => 'Descuentos' ],
                [ 'type' => PaymentType::QPASS_FALABELLA, 'name' => 'Qpass_falabella' ],
                [ 'type' => PaymentType::PLAZES, 'name' => 'Plazes' ],
                [ 'type' => PaymentType::VISA_PANAMA_US, 'name' => 'Visa_panama_us' ],
                [ 'type' => PaymentType::MASTERCARD_PANAMA_USD, 'name' => 'Mastercard_panama_usd' ],
                [ 'type' => PaymentType::SISTEMA_CLAVE_PANAMA_USD, 'name' => 'Sistema_clave_panama_usd' ],
                [ 'type' => PaymentType::QPASS_CAMPO_SANTO, 'name' => 'Qpass_campo_santo' ],
                [ 'type' => PaymentType::BONO_PEPE_GANGA, 'name' => 'Bono_pepe_ganga' ],
                [ 'type' => PaymentType::QPASS_POLUX, 'name' => 'Qpass_polux' ],
                [ 'type' => PaymentType::BONO_COOMEVA, 'name' => 'Bono_coomeva' ],
                [ 'type' => PaymentType::QPASS_ELECTRONICO, 'name' => 'Qpass_electronico' ],
                [ 'type' => PaymentType::NORMAL_QPASS, 'name' => 'Normal_qpass' ],
                [ 'type' => PaymentType::BONO_REDEBAN_50, 'name' => 'Bono_redeban_50' ],
                [ 'type' => PaymentType::BONO_REDEBAN_20, 'name' => 'Bono_redeban_20' ],
                [ 'type' => PaymentType::BONO_PROMOCION, 'name' => 'Bono_promocion' ],
                [ 'type' => PaymentType::CREDITO_RAPPI, 'name' => 'Credito_rappi' ],
                [ 'type' => PaymentType::BONO_DOMICILIO, 'name' => 'Bono_domicilio' ],
                [ 'type' => PaymentType::PAGOS_ONLINE, 'name' => 'Pagos_online' ],
                [ 'type' => PaymentType::RAPICREDITO, 'name' => 'Rapicredito' ],
                [ 'type' => PaymentType::ONLINE_IFOOD, 'name' => 'Online_ifood' ],
                [ 'type' => PaymentType::TARJETAS_AUTO, 'name' => 'Tarjetas_auto' ],
                [ 'type' => PaymentType::BONO_QUANTUM, 'name' => 'Bono_quantum' ],
                [ 'type' => PaymentType::MUSIQ, 'name' => 'Musiq' ],
                [ 'type' => PaymentType::CREDITO_UBEREATS, 'name' => 'Credito_ubereats' ],
                [ 'type' => PaymentType::UBER_EATS, 'name' => 'Uber_eats' ],
                [ 'type' => PaymentType::FUERZAS_MILITARES, 'name' => 'Fuerzas_militares' ],
                [ 'type' => PaymentType::PROMOCION_BILLETE, 'name' => 'Promocion_billete' ],
                [ 'type' => PaymentType::CUPON_IFOOD, 'name' => 'Cupon_ifood' ],
                [ 'type' => PaymentType::WOMPI, 'name' => 'Wompi' ],
                [ 'type' => PaymentType::CLIENTES_CORPORATIVOS, 'name' => 'Clientes_corporativos' ],
                [ 'type' => PaymentType::MERCADO_PAGO, 'name' => 'Mercado_pago' ]

            ];
            return response()->json([
                'status' => 'Exito',
                'results' => $types
            ], 200);
        } catch (\Exception $e) {
            Log::info("OrderController@getPaymentTypes: No se pudo obtener los tipos de pago");
            Log::info($e);
            return response()->json([
                'status' => 'Fallo al obtener los tipos de pago',
                'results' => []
            ], 500);
        }
    }

    /**
     * Updates order's payment
     */
    public function updatePayment(Request $request)
    {
        try {
            $user = \Request::get('employee');
            DB::transaction(
                function () use ($request, $user) {
                    $orderId = $request['orderId'];
                    $now = Carbon::now()->toDateTimeString();
                    foreach ($request['changes'] as $update) {
                        $payment = Payment::where('order_id', $orderId)
                            ->where('type', $update['old_type'])
                            ->where('card_id', $update['old_card_id'])
                            ->where('card_last_digits', $update['old_card_last_digits'])
                            ->first();
                        if ($payment) {
                            $payment->type = $update['new_type'];
                            $payment->card_id = $update['new_card_id'];
                            $payment->card_last_digits = $update['new_card_last_digits'];
                            $payment->updated_at = $now;
                            $payment->save();

                            $isCashToOther = $update['old_type'] === PaymentType::CASH && $update['new_type'] !== PaymentType::CASH;
                            $isOtherToCash = $update['old_type'] !== PaymentType::CASH && $update['new_type'] === PaymentType::CASH;
                            // Changes in Cash payment
                            if ($isCashToOther) {
                                $orderCash = Order::where('id', $orderId)->first();
                                if ($orderCash) {
                                    $orderCash->cash = 0; // Dejo de ser cash
                                    $orderCash->save();
                                }
                            }
                            if ($isOtherToCash) {
                                $orderNoCash = Order::where('id', $orderId)->first();
                                if ($orderNoCash) {
                                    $orderNoCash->cash = 1; // Ahora es cash
                                    $orderNoCash->save();
                                }
                            }
                        }
                    }
                    return response()->json([
                        'status' => 'Exito',
                        'results' => 'null'
                    ], 200);
                }
            );
        } catch (\Exception $e) {
            Log::info("OrderController@updatePayment: No se pudo actualizar el pago de la orden");
            Log::info($e);
            return response()->json([
                'status' => 'Falló al actualizar el pago',
                'results' => 'null'
            ], 500);
        }
    }
}
