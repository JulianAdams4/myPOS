<?php

namespace App\Traits;

use App\Helper;
use App\Order;
use App\Store;
use App\PaymentType;
use App\CashierBalance;
use App\Card;
use App\Employee;
use App\Traits\OrderHelper;
use Log;
trait CashierBalanceHelper
{
    use OrderHelper;

    public function getValuesCashierBalance($cashierBalanceId)
    {
        $ordersCashierBalance = Order::where('cashier_balance_id', $cashierBalanceId)
            ->where('status', 1)
            ->where('preorder', 0)
            ->with(['orderIntegrationDetail', 'payments'])
            ->get();
        $revokedOrders = Order::where('cashier_balance_id', $cashierBalanceId)
            ->where('status', 2)
            ->where('preorder', 0)
            ->with('orderIntegrationDetail', 'invoice', 'payments')
            ->get();
        $pendingOrders = Order::where('cashier_balance_id', $cashierBalanceId)
            ->where('status', 1)
            ->where('preorder', 1)
            ->with('orderIntegrationDetail', 'invoice', 'payments')
            ->get();
            
        
        $countRevokedOrders = $revokedOrders->count();

        $totalValueClose = 0;
        $totalValueCardClose = 0;
        $totalValueTransferClose = 0;
        $totalValueRappiPayClose = 0;
        $totalValueOthersClose = 0;
        $totalValueCardTips = 0;
        $externalServicesValues = array();
        $hasRappiPay = false;
        $totalValueChange = 0;
        $totalTipCash = 0;
        $totalTipCard = 0;
        $countOrderCash = 0;
        $countOrderCard = 0;
        $countOrderTransfer = 0;
        $countOrderRappiPay = 0;
        $countOrderOther = 0;
        $countExternalServicesValues = array();
        foreach ($ordersCashierBalance as $order) {
            if ($order->orderIntegrationDetail != null && $order->orderIntegrationDetail->integration_name != "rappi_pickup") {
                if (isset($externalServicesValues[$order->orderIntegrationDetail->integration_name])) {
                    // Si la orden de Didi fue pagada en efectivo se lo suma al valor de efectivo de caja,
                    // caso contrario se lo muestra en el valor Didi
                    if ($order->orderIntegrationDetail->integration_name == "didi" && count($order->payments) > 0 && $order->payments !== null) {
                        $payment = $order->payments[0];
                        if ($payment->type == PaymentType::CASH) {
                            $totalValueClose += $payment->total;
                        } 
                        
                        $externalServicesValues[$order->orderIntegrationDetail->integration_name] += $order->total;
                        
                    } else {
                        $externalServicesValues[$order->orderIntegrationDetail->integration_name] += $order->total;
                    }
                    $countExternalServicesValues[$order->orderIntegrationDetail->integration_name] += 1;
                } else {
                    // Inicializamos
                    $externalServicesValues[$order->orderIntegrationDetail->integration_name] = $order->total;
                    $countExternalServicesValues[$order->orderIntegrationDetail->integration_name] = 1;
                }
            } elseif ($order->payments !== null) {
                $shouldSubstractChange = false;

                foreach ($order->payments as $payment) {
                    switch ($payment->type) {
                        case PaymentType::CASH:

                            $totalValueClose += $payment->total;
                            $totalValueChange += $payment->change_value;

                            $shouldSubstractChange = true;
                            $totalTipCash += $payment->tip;
                            $countOrderCash++;
                            break;
                        case PaymentType::DEBIT:
                        case PaymentType::CREDIT:
                            $totalValueCardClose += $payment->total;

                            $totalTipCard += $payment->tip;
                            $totalValueCardTips += $payment->tip;

                            $countOrderCard++;
                            break;
                        case PaymentType::TRANSFER:
                            $totalValueTransferClose += $payment->total;
                            $countOrderTransfer++;
                            break;
                        case PaymentType::RAPPI_PAY:
                            $totalValueRappiPayClose += $payment->total;
                            $hasRappiPay = true;
                            $countOrderRappiPay++;
                            break;
                        case PaymentType::OTHER:
                            $totalValueOthersClose += $payment->total;
                            $countOrderOther++;
                            break;
                    }
                }

            } else {
                if ($order->cash) {
                    if (!$order->total) {
                        $order->load('orderDetails');
                        $order = $this->calculateOrderValues($order);
                    }
                    $countOrderCash++;
                    $totalValueClose += $order->total;
                } else {
                    if (!$order->total) {
                        $order->load('orderDetails');
                        $order = $this->calculateOrderValues($order);
                    }
                    $countOrderCard++;
                    $totalValueCardClose += $order->total;
                }
            }
        }

        $hasUberEats = false;
        $hasRappi = false;

        if (isset($externalServicesValues['uber_eats']) && $externalServicesValues['uber_eats'] > 0) {
            $hasUberEats = true;
            $cashierBalance = CashierBalance::where('id', $cashierBalanceId)
                            ->first();
            if ($cashierBalance->uber_discount != null) {
                $externalServicesValues['uber_eats'] -= $cashierBalance->uber_discount;
            }
        }
        if (isset($externalServicesValues['rappi']) && $externalServicesValues['rappi'] > 0) {
            $hasRappi = true;
        }
        if (isset($externalServicesValues['rappi_pickup']) && $externalServicesValues['rappi_pickup'] > 0) {
            $hasRappi = true;
        }
        if (isset($externalServicesValues['rappi_antojo']) && $externalServicesValues['rappi_antojo'] > 0) {
            $totalValueClose += $externalServicesValues['rappi_antojo'];
            unset($externalServicesValues['rappi_antojo']);
        }

        // Valores de las órdenes anuladas
        $totalValueRevoked = 0;
        foreach ($revokedOrders as $order) {
            $shouldAddTip = false;
            $shouldSubstractChange = false;
            foreach ($order->payments as $payment) {
                $totalValueRevoked += $payment->total;
                switch ($payment->type) {
                    case PaymentType::CASH:
                        $shouldSubstractChange = true;
                        break;
                    case PaymentType::DEBIT:
                    case PaymentType::CREDIT:
                        $shouldAddTip = true;
                        break;
                    case PaymentType::TRANSFER:
                    case PaymentType::RAPPI_PAY:
                    case PaymentType::OTHER:
                        break;
                }
            }
        }

        // Valores de las órdenes pendientes
        $totalValuePending = 0;
        foreach ($pendingOrders as $order) {
            $shouldAddTip = false;
            $shouldSubstractChange = false;
            foreach ($order->payments as $payment) {
                $totalValuePending += $payment->total;

                switch ($payment->type) {
                    case PaymentType::CASH:
                        $shouldSubstractChange = true;
                        break;
                    case PaymentType::DEBIT:
                    case PaymentType::CREDIT:
                        $shouldAddTip = true;
                        break;
                    case PaymentType::TRANSFER:
                    case PaymentType::RAPPI_PAY:
                    case PaymentType::OTHER:
                        break;
                }

            }
        }

        return [
            'close' => (string)$totalValueClose,
            'card' => (string)$totalValueCardClose,
            'transfer' => (string)$totalValueTransferClose,
            'rappi_pay' => (string)$totalValueRappiPayClose,
            'others' => (string)$totalValueOthersClose,
            'card_tips' => (string)$totalValueCardTips,
            'cash_tips' => (string) $totalTipCash,
            'external_values' => $externalServicesValues,
            'has_uber_eats' => $hasUberEats,
            'has_rappi' => $hasRappi,
            'has_rappi_pay' => $hasRappiPay,
            'revoked_orders' => $countRevokedOrders,
            'value_revoked_orders' => $totalValueRevoked,
            'value_pending_orders' => $totalValuePending,
            'change_value' => $totalValueChange,
            'card_tip_value' => $totalTipCard,
            'cash_tip_value' => $totalTipCash,
            'count_orders_cash' => $countOrderCash,
            'count_orders_card' => $countOrderCard,
            'count_orders_transfer' => $countOrderTransfer,
            'count_orders_rappi_pay' => $countOrderRappiPay,
            'count_orders_other' => $countOrderOther,
            'count_orders_external' => $countExternalServicesValues
        ];
    }

    // Deprecado, usar @getValuesCashierBalance
    public function getValuesCashierBalanceInvoices($cashierBalanceId)
    {
        $ordersCashierBalance = Order::where('cashier_balance_id', $cashierBalanceId)
            ->where('status', 1)
            ->where('preorder', 0)
            ->with('orderIntegrationDetail', 'invoice', 'payments')
            ->get();
        $revokedOrders = Order::where('cashier_balance_id', $cashierBalanceId)
            ->where('status', 2)
            ->where('preorder', 0)
            ->with('orderIntegrationDetail', 'invoice', 'payments')
            ->count();

        $totalValueClose = 0;
        $totalValueCardClose = 0;
        $totalValueTransferClose = 0;
        $totalValueRappiPayClose = 0;
        $totalValueOthersClose = 0;
        $totalValueCardTips = 0;
        $externalServicesValues = array();
        $hasRappiPay = false;
        foreach ($ordersCashierBalance as $order) {
            if ($order->orderIntegrationDetail != null  && $order->orderIntegrationDetail->integration_name != "rappi_pickup") {
                if ($order->invoice) {
                    if (isset($externalServicesValues[$order->orderIntegrationDetail->integration_name])) {
                        $externalServicesValues[$order->orderIntegrationDetail->integration_name] +=
                            $order->invoice->total;
                    } else {
                        $externalServicesValues[$order->orderIntegrationDetail->integration_name] =
                            $order->invoice->total;
                    }
                } else {
                    if (isset($externalServicesValues[$order->orderIntegrationDetail->integration_name])) {
                        $externalServicesValues[$order->orderIntegrationDetail->integration_name] +=
                            Helper::bankersRounding($order->total, 0);
                    } else {
                        $externalServicesValues[$order->orderIntegrationDetail->integration_name] =
                            Helper::bankersRounding($order->total, 0);
                    }
                }
            } elseif ($order->payments !== null) {
                $shouldSubstractChange = false;
                foreach ($order->payments as $payment) {
                    switch ($payment->type) {
                        case PaymentType::CASH:
                            $shouldSubstractChange = true;
                            $totalValueClose += $payment->total;
                            break;
                        case PaymentType::DEBIT:
                        case PaymentType::CREDIT:
                            $shouldAddTip = true;
                            $totalValueCardClose += $payment->total;
                            if ($order->invoice) {
                                $totalValueCardTips += $order->invoice->tip;
                            } else {
                                $totalValueCardTips += Helper::bankersRounding($payment->tip, 0);
                            }
                            break;
                        case PaymentType::TRANSFER:
                            $totalValueTransferClose += $payment->total;
                            break;
                        case PaymentType::RAPPI_PAY:
                            $totalValueRappiPayClose += $payment->total;
                            $hasRappiPay = true;
                            break;
                        case PaymentType::OTHER:
                            $totalValueOthersClose += $payment->total;
                            break;
                    }
                }

                if ($shouldSubstractChange && $order->change_value > 0) {
                    $totalValueClose -= $order->change_value;
                }
            } else {
                if (!$order->total) {
                    $order->load('orderDetails');
                    $order = $this->calculateOrderValues($order);
                }
                if ($order->cash) {
                    if ($order->invoice) {
                        $totalValueClose += $order->invoice->total;
                    } else {
                        $totalValueClose += Helper::bankersRounding($order->total, 0);
                    }
                } else {
                    if ($order->invoice) {
                        $total = $order->invoice->total - ($order->invoice->total * $order->discount_percentage);
                        $totalValueCardClose += $total;
                    } else {
                        $total = $order->total - ($order->total * $order->discount_percentage);
                        $totalValueCardClose += Helper::bankersRounding($total, 0);
                    }
                }
            }
        }

        $hasUberEats = false;
        $hasRappi = false;

        if (isset($externalServicesValues['uber_eats']) && $externalServicesValues['uber_eats'] > 0) {
            $hasUberEats = true;
        }
        if (isset($externalServicesValues['rappi']) && $externalServicesValues['rappi'] > 0) {
            $hasRappi = true;
        }
        if (isset($externalServicesValues['rappi_pickup']) && $externalServicesValues['rappi_pickup'] > 0) {
            $hasRappi = true;
        }
        if (isset($externalServicesValues['rappi_antojo']) && $externalServicesValues['rappi_antojo'] > 0) {
            $totalValueClose += $externalServicesValues['rappi_antojo'];
            unset($externalServicesValues['rappi_antojo']);
        }

        return [
            'close' => (string)$totalValueClose,
            'card' => (string)$totalValueCardClose,
            'transfer' => (string)$totalValueTransferClose,
            'rappi_pay' => (string)$totalValueRappiPayClose,
            'others' => (string)$totalValueOthersClose,
            'card_tips' => (string)$totalValueCardTips,
            'external_values' => $externalServicesValues,
            'has_uber_eats' => $hasUberEats,
            'has_rappi' => $hasRappi,
            'has_rappi_pay' => $hasRappiPay,
            'revoked_orders' => $revokedOrders
        ];
    }

    public function formatCashierValuesForMail($request, $cashierBalance, $data, $dateClose, $hourClose)
    {
        $uberDiscount = 0;
        if (isset($request->totalUberDiscount)) {
            $uberDiscount = $request->totalUberDiscount * 100;
        }

        $totalValueExternal = 0;

        $externalArray = array();
        foreach ($data['external_values'] as $key => $value) {
            $totalValueExternal += $value;
            array_push($externalArray, [$key, $value]);
        }

        $totalExpenses = 0;
        if (isset($request->expenses)) {
            foreach ($request->expenses as $expense) {
                $totalExpenses += $expense['value'];
            }
        }

        $data['external_values'] = $externalArray;
        
        $value_sales = $data['close'] +
            $data['card'] +
            $totalValueExternal +
            $data['transfer'] +
            $data['others'] +
            $data['rappi_pay'];

        return [
            'value_open' => round($request->value_open/100, 2),
            'value_cash' => round($data['close']/100, 2),
            'value_sales' => round($value_sales/100, 2),
            'value_close' => round($data['close']/100, 2),
            'reported_value_close' => round($request->reported_value_close/100, 2),
            'value_card' => round($data['card']/100, 2),
            'value_transfer' => round($data['transfer']/100, 2),
            'value_rappi_pay' => round($data['rappi_pay']/100, 2),
            'value_others' => round($data['others']/100, 2),
            'value_card_tips' => round($data['card_tips']/100, 2),
            'value_discount' => round($request->value_discount/100, 2),
            'date_close' => $dateClose,
            'hour_open' => $cashierBalance->hour_open,
            'hour_close' => $hourClose,
            'expenses' => $request->expenses,
            'total_expenses' => $totalExpenses,
            'externalValues' => $data['external_values'],
            'revoked_orders' => $data['revoked_orders'],
            'uber_discount' => $uberDiscount,
            'cashier_number' => $cashierBalance->cashier_number == null ? "" : $cashierBalance->cashier_number,
            'date_open' => $cashierBalance->date_open,
            'revoked_orders' => $data['revoked_orders'],
            'value_revoked_orders' => round($data['value_revoked_orders']/100, 2),
            'value_pending_orders' => round($data['value_pending_orders']/100, 2),
            'value_change' => round($data['change_value']/100, 2),
            'value_tip_cash' => round($data['cash_tip_value']/100, 2),
            'value_tip_card' => round($data['card_tip_value']/100, 2),
            'value_deliveries' => round($totalValueExternal/100, 2),
            'count_orders_cash' => $data['count_orders_cash'],
            'count_orders_card' => $data['count_orders_card'],
            'count_orders_transfer' => $data['count_orders_transfer'],
            'count_orders_rappi_pay' => $data['count_orders_rappi_pay'],
            'count_orders_other' => $data['count_orders_other'],
            'count_orders_external' => $data['count_orders_external'],
        ];
    }

    public function getPreviousValueClosed(Store $store)
    {
        $store->load('previousCashierBalance.expenses');
        $cashierBalanceClosed = $store->previousCashierBalance;
        $totalExpenses = 0;
        $valuePreviousClose = 0;
        if ($cashierBalanceClosed) {
            $valuePreviousClose = $cashierBalanceClosed->value_close;
            $totalExpenses = $cashierBalanceClosed->expenses->sum('value');
        }

        $valuePreviousClose = $valuePreviousClose - $totalExpenses;

        if ($valuePreviousClose < 0) {
            $valuePreviousClose = 0;
        }
        
        return $valuePreviousClose;
    }

    public function extraDataCashierBalance($cashierBalance)
    {
        $orders = $cashierBalance->orders;
        $validOrders = $orders->where('status', 1)->where('preorder', 0);
        $countOrders = $validOrders->count();
        $firstOrder = $validOrders->first();
        $lastOrder = $validOrders->last();

        $countCustomerLocal = 0;
        $countCustomerDelivery = 0;
        $countLocalOrders = 0;
        $countDeliveryOrders = 0;
        $spots = [];

        $cardPaymentsData = [];
        $cards = Card::all();

        // Data de comida y bebidas
        $foodSutotal = 0;
        $foodTotal = 0;
        $foodTax = 0;
        $drinkSutotal = 0;
        $drinkTotal = 0;
        $drinkTax = 0;
        $otherSutotal = 0;
        $otherTotal = 0;
        $otherTax = 0;
        $totalDiscount = 0;
        $totalNoTax = 0;
        $totalTax = 0;
        $valueTaxes = [];
        $valueTaxes[0] = [
            "percentage" => 0,
            "total" => 0
        ];
        
        // Valores de empleado
        $valueEmployees = [];
        // Valores de Rappi
        $valueRappiOrders = [];

        // Inicializando array que contendrá data de pagos con tarjeta
        foreach ($cards as $card) {
            $cardPaymentsData[$card->id] = [
                "name" => $card->name,
                "transactions" => []
            ];
        }

        $employeePaymentsData = [];
        $employees = Employee::where('store_id', $cashierBalance->store_id)->withTrashed()->get();
        // Inicializando array que contendrá data de pagos con tarjeta
        foreach ($employees as $employee) {
            $employeePaymentsData[$employee->id] = [
                "name" => $employee->name,
                "value" => 0,
                "tips" => 0
            ];
        }

        foreach ($validOrders as $order) {
            if ($order->orderIntegrationDetail != null &&
                $order->orderIntegrationDetail->integration_name != "rappi_pickup"
            ) {
                // Orden delivery por defecto 1 persona
                $countCustomerDelivery += 1;
                $countDeliveryOrders += 1;

                if ($order->orderIntegrationDetail->integration_name == "rappi") {
                    array_push(
                        $valueRappiOrders,
                        [
                            "invoice_number" => $order->invoice->invoice_number,
                            "name" => "RAPPI",
                            "value" => round($order->invoice->total/100, 2)
                        ]
                    );
                }
            } else {
                $numberCustomerOrder = is_null($order->people) ? 1 : $order->people;
                $countCustomerLocal += $numberCustomerOrder;
                $countLocalOrders += 1;
                if ($order->payments !== null) {
                    $shouldSubstractChange = false;
                    foreach ($order->payments as $payment) {
                        $addTip = false;
                        switch ($payment->type) {
                            case PaymentType::DEBIT:
                            case PaymentType::CREDIT:
                                $card = $cards->where('id', $payment->card_id)->first();
                                if (!is_null($card)) {
                                    array_push(
                                        $cardPaymentsData[$card->id]["transactions"],
                                        [
                                            "invoice_number" => $order->invoice->invoice_number,
                                            "last_digits" => is_null($payment->card_last_digits) ? "" : $payment->card_last_digits,
                                            "value" => round($payment->total/100, 2),
                                            "tip" => round($payment->tip/100, 2)
                                        ]
                                    );
                                }
                                
                                $employeePaymentsData[$order->employee_id]["tips"] += $payment->tip;

                                break;
                            case PaymentType::CASH:
                                $shouldSubstractChange = true;
                                $employeePaymentsData[$order->employee_id]["tips"] += $payment->tip;
                                break;
                            case PaymentType::TRANSFER:
                            case PaymentType::RAPPI_PAY:
                            case PaymentType::OTHER:
                                break;
                        }

                        $employeePaymentsData[$order->employee_id]["value"] += $payment->total;

                    }
                }
            }
            if (!in_array($order->spot_id, $spots)) {
                array_push($spots, $order->spot_id);
            }

            $productDetail = Helper::getDetailsUniqueGroupedByCompoundKey($order->orderDetails);
            $totalWithTax = 0;

            foreach ($productDetail as $product) {
                $beforeTax  = (float) $product['tax_values']['no_tax'];
                $withTax    = (float) $product['tax_values']['with_tax'];
                $taxDetails = $product['tax_values']['tax_details'];
                $valTax     = $withTax - $beforeTax;
                $totalWithTax += $withTax;

                if ($product['tax_values']['has_taxes']) {
                    foreach ($taxDetails as $taxDetail) {
                        $percentage = (int) $taxDetail["tax"]["percentage"];
                        if (!isset($valueTaxes[$percentage])) {
                            $valueTaxes[$percentage] = [
                                "percentage" => $percentage,
                                "total" => $beforeTax
                            ];
                        } else {
                            $valueTaxes[$percentage]["total"] += $beforeTax;
                        }
                    }

                    if ($product['product_detail']['product']['type_product'] === "food") {
                        $foodSutotal += $beforeTax;
                        $foodTotal += $withTax;
                        $foodTax += $valTax;
                    } elseif ($product['product_detail']['product']['type_product'] === "drink") {
                        $drinkSutotal += $beforeTax;
                        $drinkTotal += $withTax;
                        $drinkTax += $valTax;
                    } else {
                        $otherSutotal += $beforeTax;
                        $otherTotal += $withTax;
                        $otherTax += $valTax;
                    }
                    $totalTax += $withTax;
                } else {
                    $valueTaxes[0]["total"] += $beforeTax;

                    if ($product['product_detail']['product']['type_product'] === "food") {
                        $foodSutotal += $beforeTax;
                        $foodTotal += $withTax;
                    } elseif ($product['product_detail']['product']['type_product'] === "drink") {
                        $drinkSutotal += $beforeTax;
                        $drinkTotal += $withTax;
                    } else {
                        $otherSutotal += $beforeTax;
                        $otherTotal += $withTax;
                    }
                    $totalNoTax += $withTax;
                }
            }
            $totalDiscount = $totalDiscount + ($totalWithTax - $order->total);
        }

	unset($cashierBalance->orders);

        return [
            "count_orders" => $countOrders,
            "first_invoice_number" => is_null($firstOrder) ? "" : $firstOrder->invoice->invoice_number,
            "last_invoice_number" => is_null($lastOrder) ? "" : $lastOrder->invoice->invoice_number,
            "customer_local" => $countCustomerLocal == 0 ? 1 : $countCustomerLocal,
            "customer_delivery" => $countCustomerDelivery == 0 ? 1 : $countCustomerDelivery,
            "served_spots" => $spots,
            "count_local_orders" => $countLocalOrders == 0 ? 1 : $countLocalOrders,
            "count_delivery_orders" => $countDeliveryOrders,
            "card_details_payments" => $cardPaymentsData,
            "employee_details_transactions" => $employeePaymentsData,
            "food_sutotal" => round($foodSutotal / 100, 2),
            "food_total" => Helper::bankersRounding($foodTotal, 2) / 100,
            "food_tax" => round($foodTax / 100, 2),
            "drink_sutotal" => round($drinkSutotal / 100, 2),
            "drink_total" => Helper::bankersRounding($drinkTotal, 2) / 100,
            "drink_tax" => round($drinkTax / 100, 2),
            "other_sutotal" => round($otherSutotal / 100, 2),
            "other_total" => Helper::bankersRounding($otherTotal, 2) / 100,
            "other_tax" => round($otherTax / 100, 2),
            "total_discount" => Helper::bankersRounding($totalDiscount, 2) / 100,
            "total_value_no_tax" => Helper::bankersRounding($totalDiscount, 2) / 100,
            "total_value_tax" => Helper::bankersRounding($totalDiscount, 2) / 100,
            "subtotal_no_tax" => round(($foodSutotal + $drinkSutotal + $otherSutotal) / 100, 2),
            "tax_values_details" => $valueTaxes,
            "rappi_values_details" => $valueRappiOrders,
        ];
    }

    // Función temporal para no cambiar nada de la función principal
    public function getValuesCashierBalanceX($cashierBalanceId, $orderIdsIgnore)
    {

        $ordersCashierBalance = Order::where('cashier_balance_id', $cashierBalanceId)
            ->whereNotIn('id', $orderIdsIgnore)
            ->where('status', 1)
            ->where('preorder', 0)
            ->with(['orderIntegrationDetail', 'payments'])
            ->get();
        $revokedOrders = Order::where('cashier_balance_id', $cashierBalanceId)
            ->where('status', 2)
            ->whereNotIn('id', $orderIdsIgnore)
            ->where('preorder', 0)
            ->with('orderIntegrationDetail', 'invoice', 'payments')
            ->get();
        $pendingOrders = Order::where('cashier_balance_id', $cashierBalanceId)
            ->where('status', 1)
            ->whereNotIn('id', $orderIdsIgnore)
            ->where('preorder', 1)
            ->with('orderIntegrationDetail', 'invoice', 'payments')
            ->get();
            
        
        $countRevokedOrders = $revokedOrders->count();

        $totalValueClose = 0;
        $totalValueCardClose = 0;
        $totalValueTransferClose = 0;
        $totalValueRappiPayClose = 0;
        $totalValueOthersClose = 0;
        $totalValueCardTips = 0;
        $externalServicesValues = array();
        $hasRappiPay = false;
        $totalValueChange = 0;
        $totalTipCash = 0;
        $totalTipCard = 0;
        $countOrderCash = 0;
        $countOrderCard = 0;
        $countOrderTransfer = 0;
        $countOrderRappiPay = 0;
        $countOrderOther = 0;
        $countExternalServicesValues = array();
        $orderIds = [];
        foreach ($ordersCashierBalance as $order) {
            array_push($orderIds, $order->id);
            if ($order->orderIntegrationDetail != null && $order->orderIntegrationDetail->integration_name != "rappi_pickup") {
                if (isset($externalServicesValues[$order->orderIntegrationDetail->integration_name])) {
                    $externalServicesValues[$order->orderIntegrationDetail->integration_name] += $order->total;
                    $countExternalServicesValues[$order->orderIntegrationDetail->integration_name] += 1;
                } else {
                    // Inicializamos
                    $externalServicesValues[$order->orderIntegrationDetail->integration_name] = $order->total;
                    $countExternalServicesValues[$order->orderIntegrationDetail->integration_name] = 1;
                }
            } elseif ($order->payments !== null) {
                $shouldSubstractChange = false;

                foreach ($order->payments as $payment) {
                    switch ($payment->type) {
                        case PaymentType::CASH:
                            $totalValueClose += $payment->total;
                            $shouldSubstractChange = true;
                            $totalTipCash += $payment->tip;
                            $countOrderCash++;
                            break;
                        case PaymentType::DEBIT:
                        case PaymentType::CREDIT:
                            $totalValueCardClose += $payment->total;

                            $totalTipCard += $payment->tip;
                            $totalValueCardTips += $payment->tip;

                            $countOrderCard++;
                            break;
                        case PaymentType::TRANSFER:
                            $totalValueTransferClose += $payment->total;
                            $countOrderTransfer++;
                            break;
                        case PaymentType::RAPPI_PAY:
                            $totalValueRappiPayClose += $payment->total;
                            $hasRappiPay = true;
                            $countOrderRappiPay++;
                            break;
                        case PaymentType::OTHER:
                            $totalValueOthersClose += $payment->total;
                            $countOrderOther++;
                            break;
                    }
                }

            } else {
                if ($order->cash) {
                    if (!$order->total) {
                        $order->load('orderDetails');
                        $order = $this->calculateOrderValues($order);
                    }
                    $countOrderCash++;
                    $totalValueClose += $order->total;
                } else {
                    if (!$order->total) {
                        $order->load('orderDetails');
                        $order = $this->calculateOrderValues($order);
                    }
                    $countOrderCard++;
                    $totalValueCardClose += $order->total;
                }
            }
        }

        $hasUberEats = false;
        $hasRappi = false;

        if (isset($externalServicesValues['uber_eats']) && $externalServicesValues['uber_eats'] > 0) {
            $hasUberEats = true;
            $cashierBalance = CashierBalance::where('id', $cashierBalanceId)
                            ->first();
            if ($cashierBalance->uber_discount != null) {
                $externalServicesValues['uber_eats'] -= $cashierBalance->uber_discount;
            }
        }
        if (isset($externalServicesValues['rappi']) && $externalServicesValues['rappi'] > 0) {
            $hasRappi = true;
        }
        if (isset($externalServicesValues['rappi_pickup']) && $externalServicesValues['rappi_pickup'] > 0) {
            $hasRappi = true;
        }
        if (isset($externalServicesValues['rappi_antojo']) && $externalServicesValues['rappi_antojo'] > 0) {
            $totalValueClose += $externalServicesValues['rappi_antojo'];
            unset($externalServicesValues['rappi_antojo']);
        }

        // Valores de las órdenes anuladas
        $totalValueRevoked = 0;
        foreach ($revokedOrders as $order) {
            array_push($orderIds, $order->id);
            $shouldAddTip = false;
            $shouldSubstractChange = false;
            foreach ($order->payments as $payment) {
                $totalValueRevoked += $payment->total;
                switch ($payment->type) {
                    case PaymentType::CASH:
                        $shouldSubstractChange = true;
                        break;
                    case PaymentType::DEBIT:
                    case PaymentType::CREDIT:
                        $shouldAddTip = true;
                        break;
                    case PaymentType::TRANSFER:
                    case PaymentType::RAPPI_PAY:
                    case PaymentType::OTHER:
                        break;
                }
            }
        }

        // Valores de las órdenes pendientes
        $totalValuePending = 0;
        foreach ($pendingOrders as $order) {
            $shouldAddTip = false;
            $shouldSubstractChange = false;
            foreach ($order->payments as $payment) {
                $totalValuePending += $payment->total;
                switch ($payment->type) {
                    case PaymentType::CASH:
                        $shouldSubstractChange = true;
                        break;
                    case PaymentType::DEBIT:
                    case PaymentType::CREDIT:
                        $shouldAddTip = true;
                        break;
                    case PaymentType::TRANSFER:
                    case PaymentType::RAPPI_PAY:
                    case PaymentType::OTHER:
                        break;
                }
            }
        }

        return [
            'close' => (string)$totalValueClose,
            'card' => (string)$totalValueCardClose,
            'transfer' => (string)$totalValueTransferClose,
            'rappi_pay' => (string)$totalValueRappiPayClose,
            'others' => (string)$totalValueOthersClose,
            'card_tips' => (string)$totalValueCardTips,
            'external_values' => $externalServicesValues,
            'has_uber_eats' => $hasUberEats,
            'has_rappi' => $hasRappi,
            'has_rappi_pay' => $hasRappiPay,
            'revoked_orders' => $countRevokedOrders,
            'value_revoked_orders' => $totalValueRevoked,
            'value_pending_orders' => $totalValuePending,
            'change_value' => $totalValueChange,
            'cash_tip_value' => $totalTipCash,
            'card_tip_value' => $totalTipCard,
            'count_orders_cash' => $countOrderCash,
            'count_orders_card' => $countOrderCard,
            'count_orders_transfer' => $countOrderTransfer,
            'count_orders_rappi_pay' => $countOrderRappiPay,
            'count_orders_other' => $countOrderOther,
            'count_orders_external' => $countExternalServicesValues,
            'order_ids' => $orderIds
        ];
    }

    // Función temporal para no cambiar nada de la función principal
    public function extraDataCashierBalanceX($cashierBalance, $orderIdsIgnore)
    {
        $orders = $cashierBalance->orders;
        $validOrders = $orders->whereNotIn('id', $orderIdsIgnore)->where('status', 1)->where('preorder', 0);
        $countOrders = $validOrders->count();
        $firstOrder = $validOrders->first();
        $lastOrder = $validOrders->last();

        $countCustomerLocal = 0;
        $countCustomerDelivery = 0;
        $countLocalOrders = 0;
        $countDeliveryOrders = 0;
        $spots = [];

        $cardPaymentsData = [];
        $cards = Card::all();

        // Data de comida y bebidas
        $foodSutotal = 0;
        $foodTotal = 0;
        $foodTax = 0;
        $drinkSutotal = 0;
        $drinkTotal = 0;
        $drinkTax = 0;
        $otherSutotal = 0;
        $otherTotal = 0;
        $otherTax = 0;
        $totalDiscount = 0;
        $totalNoTax = 0;
        $totalTax = 0;
        $valueTaxes = [];
        $valueTaxes[0] = [
            "percentage" => 0,
            "total" => 0
        ];
        
        // Valores de empleado
        $valueEmployees = [];
        // Valores de Rappi
        $valueRappiOrders = [];

        // Inicializando array que contendrá data de pagos con tarjeta
        foreach ($cards as $card) {
            $cardPaymentsData[$card->id] = [
                "name" => $card->name,
                "transactions" => []
            ];
        }

        $employeePaymentsData = [];
        $employees = Employee::where('store_id', $cashierBalance->store_id)->withTrashed()->get();
        // Inicializando array que contendrá data de pagos con tarjeta
        foreach ($employees as $employee) {
            $employeePaymentsData[$employee->id] = [
                "name" => $employee->name,
                "value" => 0,
                "tips" => 0
            ];
        }

        foreach ($validOrders as $order) {
            if ($order->orderIntegrationDetail != null &&
                $order->orderIntegrationDetail->integration_name != "rappi_pickup"
            ) {
                // Orden delivery por defecto 1 persona
                $countCustomerDelivery += 1;
                $countDeliveryOrders += 1;

                if ($order->orderIntegrationDetail->integration_name == "rappi") {
                    array_push(
                        $valueRappiOrders,
                        [
                            "invoice_number" => $order->invoice->invoice_number,
                            "name" => "RAPPI",
                            "value" => round($order->invoice->total/100, 2)
                        ]
                    );
                }
            } else {
                $numberCustomerOrder = is_null($order->people) ? 1 : $order->people;
                $countCustomerLocal += $numberCustomerOrder;
                $countLocalOrders += 1;
                if ($order->payments !== null) {
                    $shouldSubstractChange = false;
                    foreach ($order->payments as $payment) {
                        $addTip = false;
                        switch ($payment->type) {
                            case PaymentType::DEBIT:
                            case PaymentType::CREDIT:
                                $card = $cards->where('id', $payment->card_id)->first();
                                if (!is_null($card)) {
                                    array_push(
                                        $cardPaymentsData[$card->id]["transactions"],
                                        [
                                            "invoice_number" => $order->invoice->invoice_number,
                                            "last_digits" => is_null($payment->card_last_digits) ? "" : $payment->card_last_digits,
                                            "value" => round($payment->total/100, 2),
                                            "tip" => round($payment->tip/100, 2)
                                        ]
                                    );
                                }

                                $employeePaymentsData[$order->employee_id]["tips"] += $payment->tip;

                                break;
                            case PaymentType::CASH:
                                $shouldSubstractChange = true;
                                $employeePaymentsData[$order->employee_id]["tips"] += $payment->tip;
                                break;
                            case PaymentType::TRANSFER:
                            case PaymentType::RAPPI_PAY:
                            case PaymentType::OTHER:
                                break;
                        }
                        $employeePaymentsData[$order->employee_id]["value"] += $payment->total;
                            
                    }
                }
            }
            if (!in_array($order->spot_id, $spots)) {
                array_push($spots, $order->spot_id);
            }

            $productDetail = Helper::getDetailsUniqueGroupedByCompoundKey($order->orderDetails);
            $totalWithTax = 0;

            foreach ($productDetail as $product) {
                $beforeTax  = (int) $product['tax_values']['no_tax'];
                $withTax    = (int) $product['tax_values']['with_tax'];
                $taxDetails = $product['tax_values']['tax_details'];
                $valTax     = $withTax - $beforeTax;
                $totalWithTax += $withTax;

                if ($product['tax_values']['has_taxes']) {
                    foreach ($taxDetails as $taxDetail) {
                        $percentage = (int) $taxDetail["tax"]["percentage"];
                        if (!isset($valueTaxes[$percentage])) {
                            $valueTaxes[$percentage] = [
                                "percentage" => $percentage,
                                "total" => $beforeTax
                            ];
                        } else {
                            $valueTaxes[$percentage]["total"] += $beforeTax;
                        }
                    }

                    if ($product['product_detail']['product']['type_product'] === "food") {
                        $foodSutotal += $beforeTax;
                        $foodTotal += $withTax;
                        $foodTax += $valTax;
                    } elseif ($product['product_detail']['product']['type_product'] === "drink") {
                        $drinkSutotal += $beforeTax;
                        $drinkTotal += $withTax;
                        $drinkTax += $valTax;
                    } else {
                        $otherSutotal += $beforeTax;
                        $otherTotal += $withTax;
                        $otherTax += $valTax;
                    }
                    $totalTax += $withTax;
                } else {
                    $valueTaxes[0]["total"] += $beforeTax;

                    if ($product['product_detail']['product']['type_product'] === "food") {
                        $foodSutotal += $beforeTax;
                        $foodTotal += $withTax;
                    } elseif ($product['product_detail']['product']['type_product'] === "drink") {
                        $drinkSutotal += $beforeTax;
                        $drinkTotal += $withTax;
                    } else {
                        $otherSutotal += $beforeTax;
                        $otherTotal += $withTax;
                    }
                    $totalNoTax += $withTax;
                }
            }
            $totalDiscount = $totalDiscount + ($totalWithTax - $order->total);
        }

        return [
            "count_orders" => $countOrders,
            "first_invoice_number" => is_null($firstOrder) ? "" : $firstOrder->invoice->invoice_number,
            "last_invoice_number" => is_null($lastOrder) ? "" : $lastOrder->invoice->invoice_number,
            "customer_local" => $countCustomerLocal == 0 ? 1 : $countCustomerLocal,
            "customer_delivery" => $countCustomerDelivery == 0 ? 1 : $countCustomerDelivery,
            "served_spots" => $spots,
            "count_local_orders" => $countLocalOrders == 0 ? 1 : $countLocalOrders,
            "count_delivery_orders" => $countDeliveryOrders,
            "card_details_payments" => $cardPaymentsData,
            "employee_details_transactions" => $employeePaymentsData,
            "food_sutotal" => Helper::bankersRounding($foodSutotal, 2) / 100,
            "food_total" => Helper::bankersRounding($foodTotal, 2) / 100,
            "food_tax" => Helper::bankersRounding($foodTax, 2) / 100,
            "drink_sutotal" => Helper::bankersRounding($drinkSutotal, 2) / 100,
            "drink_total" => Helper::bankersRounding($drinkTotal, 2) / 100,
            "drink_tax" => Helper::bankersRounding($drinkTax, 2) / 100,
            "other_sutotal" => Helper::bankersRounding($otherSutotal, 2) / 100,
            "other_total" => Helper::bankersRounding($otherTotal, 2) / 100,
            "other_tax" => Helper::bankersRounding($otherTax, 2) / 100,
            "total_discount" => Helper::bankersRounding($totalDiscount, 2) / 100,
            "total_value_no_tax" => Helper::bankersRounding($totalDiscount, 2) / 100,
            "total_value_tax" => Helper::bankersRounding($totalDiscount, 2) / 100,
            "tax_values_details" => $valueTaxes,
            "rappi_values_details" => $valueRappiOrders,
        ];
    }
}
