<?php

namespace App\Traits;

ini_set('max_execution_time', 300);

use Log;
use App\Order;
use App\Store;
use App\Helper;
use App\Invoice;
use App\Employee;
use Carbon\Carbon;
use App\Payment;
use App\PaymentType;
use App\ProductDetail;
use App\ComponentCategory;
use App\OrderIntegrationDetail;
use App\SpecificationComponent;
use App\HistoricalInventoryItem;
use App\OrderProductSpecification;
use Illuminate\Support\Facades\DB;
use App\ComponentVariationComponent;
use App\ProductSpecificationComponent;
use App\InventoryAction;
use App\StockMovement;
use App\Traits\TimezoneHelper;
use App\Traits\AuthTrait;

trait ReportHelperTrait
{
    public static function transactionDetails($date, $store_id)
    {
        //Parseo de las fechas de inicio y fin para la obtención de la data
        $store = Store::find($store_id);
        $startDate = TimezoneHelper::convertToServerDateTime($date['from'] . '00:00:00', $store);
        $finalDate = TimezoneHelper::convertToServerDateTime($date['to'] . '23:59:59', $store);

        $orders = Order::where('store_id', $store_id)
            ->where('preorder', 0)
            ->whereBetween('created_at', [$startDate, $finalDate])
            ->with('orderIntegrationDetail')
            ->get();
        $data = [];
        foreach ($orders as $o) {
            $invoice_number = $o->invoice ? $o->invoice->invoice_number : '';
            $customer_name = $o->invoice ? $o->invoice->name : 'CONSUMIDOR FINAL';
            $cash = 0;
            $debit = 0;
            $credit = 0;
            $transfer = 0;
            $rappiPay = 0;
            $others = 0;

            foreach ($o->payments as $payment) {
                switch ($payment->type) {
                    case PaymentType::CASH:
                        $cash += $payment->total;
                        break;
                    case PaymentType::DEBIT:
                        $debit += $payment->total;
                        break;
                    case PaymentType::CREDIT:
                        $credit += $payment->total;
                        break;
                    case PaymentType::TRANSFER:
                        $transfer += $payment->total;
                        break;
                    case PaymentType::RAPPI_PAY:
                        $rappiPay += $payment->total;
                        break;
                    case PaymentType::OTHER:
                        $others += $payment->total;
                        break;
                }
            }

            $orderIntegration = OrderIntegrationDetail::where('order_id', $o->id)->first();
            $externalProvider = '';
            if ($o->orderIntegrationDetail != null) {
                $externalProvider = $o->orderIntegrationDetail->integration_name;
            }
            $paymentMethod = '';
            if ($o->status === 2) {
                $paymentMethod = ReportHelperTrait::appendMethod($paymentMethod, 'Anulada');
            } else {
                if (!$cash && !$debit && !$credit && !$transfer && !$rappiPay && !$others && $externalProvider == '') {
                    $paymentMethod = ReportHelperTrait::appendMethod($paymentMethod, 'Efectivo');
                } else {
                    if ($externalProvider != '') {
                        $paymentMethod = ReportHelperTrait::appendMethod($paymentMethod, $externalProvider);
                    } else {
                        if ($cash) {
                            $paymentMethod = ReportHelperTrait::appendMethod($paymentMethod, 'Efectivo');
                        }
                        if ($debit) {
                            $paymentMethod = ReportHelperTrait::appendMethod($paymentMethod, 'Tarjeta de Débito');
                        }
                        if ($credit) {
                            $paymentMethod = ReportHelperTrait::appendMethod($paymentMethod, 'Tarjeta de Crédito');
                        }
                        if ($transfer) {
                            $paymentMethod = ReportHelperTrait::appendMethod($paymentMethod, 'Transferencia');
                        }
                        if ($rappiPay) {
                            $paymentMethod = ReportHelperTrait::appendMethod($paymentMethod, 'Rappi Pay');
                        }
                        if ($others) {
                            $paymentMethod = ReportHelperTrait::appendMethod($paymentMethod, 'Otros');
                        }
                    }
                }
            }

            if (!isset($o->invoice)) {
                continue;
            }

            $items = $o->invoice->items;
            foreach ($items as $it) {
                $subtotal = $it->base_value;
                $total = $it->total;
                $discount = $it->invoice->discount_percentage / 100;
                $discounted_value = 0;
                $discounted_subtotal = $subtotal;
                $tax = $it->total - $it->base_value;
                $tax_perc = $tax > 0 ? round(($tax / $subtotal) * 100, 2) : 0;
                $unitPrice = $it->base_value / $it->quantity;

                if ($discount > 0) {
                    $discounted_value = $subtotal * $discount;
                    $discounted_subtotal = $subtotal * (1 - $discount);
                    $tax = $discounted_subtotal * $tax_perc / 100;
                    $total = $discounted_subtotal + $tax;
                }

                $data[] = [
                    'id' => $it->id,
                    'date' => $o->created_at,
                    'fact' => $invoice_number,
                    'customer' => $customer_name,
                    'product' => preg_replace('/[^\p{L}\p{N}\s]/u', '', $it->product_name),
                    'quantity' => $it->quantity,
                    'value' => Helper::bankersRounding($unitPrice, 0) / 100,
                    'employee' => $o->employee->name,
                    'device_id' => $o->device_id,
                    'cash' => $cash,
                    'debit' => $debit,
                    'credit' => $credit,
                    'transfer' => $transfer,
                    'rappi_pay' => $rappiPay,
                    'others' => $others,
                    'tax' => Helper::bankersRounding($tax, 2) / 100,
                    'tax_perc' => $tax_perc,
                    'subtotal' => Helper::bankersRounding($subtotal, 0) / 100,
                    'discounted_value' => Helper::bankersRounding($discounted_value, 0) / 100,
                    'discounted_subtotal' => Helper::bankersRounding($discounted_subtotal, 2) / 100,
                    'total' => Helper::bankersRounding($total, 2) / 100,
                    'category' => '',
                    'external_provider' => $externalProvider,
                    'payment_method' => $paymentMethod,
                ];
            }
        }
        return $data;
    }

    public static function transactionDetailsRefact($date, $store)
    {
        //Parseo de las fechas de inicio y fin para la obtención de la data
        $startDate = TimezoneHelper::convertToServerDateTime($date['from'] . "00:00:00", $store);
        $finalDate = TimezoneHelper::convertToServerDateTime($date['to'] . "23:59:59", $store);

        DB::statement("SET sql_mode=(SELECT REPLACE(@@sql_mode, 'ONLY_FULL_GROUP_BY', ''))");
        $transactions = DB::select("select *,
                case
                    when status = 2 then 'Anulada'
                    else
                        case
                            when (cash = 0 and debit = 0 and credit = 0 and
                                    transfer = 0 and other = 0 and rappi_pay = 0 and external_provider_check = false)
                                then 'Efectivo'
                            else
                                if(external_provider_check = true, external_provider, concat(
                                        if(cash, 'Efectivo', ''),
                                        if(debit, 'Tarjeta de Débito', ''),
                                        if(credit, 'Tarjeta de Crédito', ''),
                                        if(transfer, 'Transferencia', ''),
                                        if(other, 'Otros', ''),
                                        if(rappi_pay, 'Rappi Pay', '')
                                    ))
                            end
                    end as payment_method
            from (
                    select ord.status,
                            ord.id                                                                  as order_id,
                            e.name                                                                  as employee,
                            ord.device_id                                                           as device_id,
                            s.name                                                                  as spot,
                            DATE_FORMAT(ord.created_at, '%Y-%m-%d %H:%i:%s')                        as created_at,
                            coalesce(oid.integration_name, '')                                      as external_provider,
                            items.id,
                            coalesce(invoice.name, '')                                              as customer,
                            coalesce(invoice.invoice_number, '')                                    as fact,
                            invoice.discount_percentage,
                            items.product_name                                                      as product,
                            items.quantity,
                            items.base_value,
                            items.total,
                            pct.name                                                                as category,
                            ord.is_courtesy                                                         as courtesy,
                            (items.base_value / items.quantity)                                     as unit_price,
                            (items.total - items.base_value)                                        as unit_tax,
                            if(oid.id is not null, true, false)                                     as external_provider_check,
                            sum(case when p.type = 0 then p.total else 0 end)                       as cash,
                            sum(case when p.type = 1 then p.total else 0 end)                       as debit,
                            sum(case when p.type = 2 then p.total else 0 end)                       as credit,
                            sum(case when p.type = 3 then p.total else 0 end)                       as transfer,
                            sum(case when p.type = 4 then p.total else 0 end)                       as other,
                            sum(case when p.type = 5 then p.total else 0 end)                       as rappi_pay,
                            JSON_ARRAYAGG(JSON_OBJECT('id', ops.id, 'name_specification', ops.name_specification, 'quantity',
                                                    ops.quantity, 'unit_price', ops.value / 100)) as specifications
                    from orders ord
                            left join payments p on ord.id = p.order_id
                            left join spots s on s.id = ord.spot_id
                            left join employees e on ord.employee_id = e.id
                            left join order_integration_details oid on oid.order_id = ord.id
                            left join invoices invoice on invoice.order_id = ord.id
                            left join invoice_items items on items.invoice_id = invoice.id
                            left join order_details odt on odt.id = items.order_detail_id
                            left join product_details pdt on pdt.id = odt.product_detail_id
                            left join products pds on pds.id = pdt.product_id
                            left join product_categories pct on pct.id = pds.product_category_id
                            left join order_product_specifications ops on ops.order_detail_id = odt.id
                    where ord.store_id = ?
                    and ord.status = 1
                    and ord.preorder = 0
                    and (ord.created_at BETWEEN ? and ?)
                    group by items.id
                ) filtro;
             ", array($store->id, $startDate, $finalDate));

        $data = [];
        foreach ($transactions as $key => $trasaction) {
            // $trasaction->
            $subtotal = $trasaction->base_value;
            $total = $trasaction->total;
            $discount = $trasaction->discount_percentage / 100;
            $discounted_value = 0;
            $tax = $trasaction->unit_tax;
            $discounted_subtotal = $trasaction->base_value;
            $tax_perc = $tax > 0 ? ($tax / $subtotal) * 100 : 0;
            if ($discount > 0) {
                $discounted_value = $subtotal * $discount;
                $discounted_subtotal = ($subtotal * (1 - $discount));
                $tax = $discounted_subtotal * $tax_perc / 100;
                $total = $discounted_subtotal + $tax;
            }

            $data[] = [
                'id' => $trasaction->id,
                'date' => TimezoneHelper::localizedDateForStore($trasaction->created_at, $store),
                'fact' => $trasaction->fact,
                'customer' => $trasaction->customer,
                'product' => $trasaction->product,
                'category' => $trasaction->category,
                'quantity' => $trasaction->quantity,
                'device_id' => $trasaction->device_id,
                'value' => round($trasaction->unit_price / 100, 2),
                'employee' => $trasaction->employee,
                'cash' => Helper::bankersRounding($trasaction->cash, 0),
                'debit' => Helper::bankersRounding($trasaction->debit, 0),
                'credit' => Helper::bankersRounding($trasaction->credit, 0),
                'transfer' => Helper::bankersRounding($trasaction->transfer, 0),
                'rappi_pay' => Helper::bankersRounding($trasaction->rappi_pay, 0),
                'others' => Helper::bankersRounding($trasaction->other, 0),
                'tax' => Helper::bankersRounding($tax, 0) / 100,
                'tax_perc' => $tax_perc,
                'subtotal' => round($subtotal / 100, 4),
                'discounted_value' => round($discounted_value / 100, 2),
                'discounted_subtotal' => round($discounted_subtotal / 100, 4),
                'total' => $total / 100,
                'external_provider' => $trasaction->external_provider,
                'payment_method' => $trasaction->payment_method,
                'spot' => $trasaction->spot,
                'courtesy' => $trasaction->courtesy,
                'specifications' => json_decode($trasaction->specifications),
            ];
        }
        return $data;
    }


    public static function invoiceData($date, $store, $pageNumber)
    {
        //Parseo de las fechas de inicio y fin para la obtención de la data
        $startDate = TimezoneHelper::convertToServerDateTime($date['from'] . "00:00:00", $store);
        $finalDate = TimezoneHelper::convertToServerDateTime($date['to'] . "23:59:59", $store);

        $paramsSelect = "select i.id, i.created_at, i.invoice_number, i.name, i.document, i.undiscounted_subtotal,
         round((((i.subtotal * 100) / i.total) / 100 * o.total),2) as subtotal, round(o.total - (((i.subtotal * 100) / i.total) / 100 * o.total),2) as tax,
         round(o.total/100,1)*100 as total, i.discount_percentage, i.discount_value, s.name as spot, o.is_courtesy as courtesy,
        case when o.status=2 then 1 else 0 end as revoked,
               i.tip, i.order_id, oid.integration_name, coalesce(oid.order_number, oid.external_order_id) as integration_id,
            (select JSON_ARRAYAGG(JSON_OBJECT('type', p.type )) from payments p where p.order_id=o.id) as payments ";

        $query = " 
               from invoices i
            join orders o on i.order_id = o.id
            and o.preorder=0 and o.store_id = ?
        left join spots s on s.id=o.spot_id
        left join order_integration_details oid on i.order_id = oid.order_id
        left join available_mypos_integrations ami on ami.code_name =oid.integration_name
        where i.created_at between ? and ? and o.status=1
        order by i.created_at asc";

        $config = array($store->id, $startDate, $finalDate);

        DB::statement("SET sql_mode=(SELECT REPLACE(@@sql_mode, 'ONLY_FULL_GROUP_BY', ''))");

        $invoicesCounting = DB::select("select count(i.id) as total " . $query, $config);

        if ($pageNumber !== null) {
            $query .= " limit ? offset ?";
            array_push($config, $pageNumber['pageSize'], $pageNumber['pageSize'] * ($pageNumber['pageNumber'] - 1));
        }

        $invoices = DB::select($paramsSelect . $query, $config);

        foreach ($invoices as $invoice) {
            $invoice->payments = json_decode($invoice->payments);
            $invoice->created_at = TimezoneHelper::localizedDateForStore($invoice->created_at, $store)->format('Y-m-d H:i:s');
        }

        return [
            "total" => $invoicesCounting[0]->total,
            "data" => $invoices,
        ];
    }

    public static function invoiceDataMultiStore($date, $company_id)
    {
        [$user, $employee, $store] = AuthTrait::getAuthData();
        if (!$user || !$employee || !$store) {
            return null;
        }
        $storeIds = Store::where('company_id', $company_id)->pluck('id')->toArray();
        // Parseo de las fechas de inicio y fin para la obtención de la data
        $startDate = TimezoneHelper::convertToServerDateTime($date['from'] . '00:00:00', $store);
        $finalDate = TimezoneHelper::convertToServerDateTime($date['to'] . '23:59:59', $store);
        $data = Invoice::select(
            'invoices.id',
            'invoices.created_at',
            'invoice_number',
            'invoices.name',
            'document',
            'undiscounted_subtotal',
            'subtotal',
            'tax',
            'invoices.total',
            'invoices.discount_percentage',
            'invoices.discount_value',
            'spots.name as spot',
            'orders.is_courtesy as courtesy',
            DB::raw('(CASE WHEN orders.status = 2 THEN 1 ELSE 0 END) AS revoked'),
            'order_integration_details.integration_name',
            'stores.name as store_name',
            'invoices.order_id'
        )->join('orders', 'orders.id', '=', 'invoices.order_id')
            ->leftJoin('order_integration_details', 'order_integration_details.order_id', '=', 'invoices.order_id')
            ->leftJoin('spots', 'spots.id', '=', 'orders.spot_id')
            ->leftJoin('stores', 'stores.id', '=', 'orders.store_id')
            ->whereIn('orders.store_id', $storeIds)
            ->whereBetween('invoices.created_at', [$startDate, $finalDate])
            ->orderBy('stores.name', 'ASC')
            ->get();
        foreach ($data as $invoice) {
            $payments = Payment::where('order_id', $invoice->order_id)->get();
            $invoice->payments = $payments;

            $totalTip = 0;
            foreach ($invoice->payments as $payout) {
                $totalTip += $payout->tip;
            }

            $invoice->tip = $totalTip;
        }

        return $data;
    }

    public static function appendMethod($methods, $type)
    {
        if ($methods !== '') {
            return $methods . ', ' . $type;
        }

        return $type;
    }

    public static function consumptionsSubrecipe($componentVariationId, $consumptionProduct)
    {
        // Total de consumo por subreceta de esta orden
        $consumptionSubrecipe = 0;
        $subrecipeComponents = ComponentVariationComponent::where(
            'component_origin_id',
            $componentVariationId
        )
            ->with('variationSubrecipe')
            ->get();
        foreach ($subrecipeComponents as $subrecipeComponent) {
            if ($subrecipeComponent->consumption && $subrecipeComponent->consumption > 0) {
                $quantityConsumptionSubRecipe = 0;
                if ($subrecipeComponent->value_reference != 0) {
                    // Calculando cantidad de consumo por subreceta
                    $quantityConsumptionSubRecipe = $consumptionProduct / $subrecipeComponent->value_reference;
                }
                // Sumando consumo por subreceta de esa orden al total de consumo
                $consumptionSubrecipe += $subrecipeComponent->consumption * $quantityConsumptionSubRecipe;
            }
        }
        return $consumptionSubrecipe;
    }

    public static function inventoryStockData($date, $store, $filters, $offset, $limit)
    {
        $startDate = TimezoneHelper::convertToServerDateTime($date['from'] . '00:00:00', $store);
        $finalDate = TimezoneHelper::convertToServerDateTime($date['to'] . '23:59:59', $store);
        $filterVariation = null;
        if (isset($filters["variation_id"])) {
            $filterVariation = $filters["variation_id"];
        }
        $componentCategoriesCompany = ComponentCategory::select('id', 'company_id', 'name')
            ->with([
                'components' => function ($components) use ($store, $startDate, $finalDate) {
                    $components->select('id', 'component_category_id', 'name')
                        ->where('status', 1)
                        ->with([
                            'lastComponentStock' => function ($componentStock) use ($store, $startDate, $finalDate) {
                                $componentStock->select('id', 'store_id', 'component_id', 'stock')
                                    ->where('store_id', $store->id)
                                    ->with([
                                        'stockMovements' => function ($movements) use ($startDate, $finalDate) {
                                            $movements->select('inventory_action_id', 'initial_stock', 'value', 'final_stock', 'created_at', 'component_stock_id')
                                                ->whereBetween('created_at', array($startDate, $finalDate));
                                        }
                                    ]);
                            }
                        ]);
                }
            ])
            ->where('company_id', $store->company_id)
            ->orderBy('name', 'asc')
            ->get();

        $componentsFilter = [];
        foreach ($componentCategoriesCompany as $componentCategory) {
            foreach ($componentCategory->components as $component) {
                $compStock = $component->lastComponentStock;
                $stock_movements = [];
                $initialStock = $finalStock = 0;
                if ($compStock) {
                    $stock_movements = $compStock->stockMovements;
                    if (count($stock_movements) > 0) {
                        $initialStock = $stock_movements->first()['initial_stock'];
                        $finalStock = $stock_movements->last()['final_stock'];
                    } else {
                        // Si no hay movimientos en el rango de fechas, se toma el ultimo
                        // registro antes del intervalo. Si existiese, de ese registro
                        // se toma el stock_final
                        $lastMovementBeforeFilter = StockMovement::where('component_stock_id', $compStock['id'])
                            ->where('created_at', '<', $startDate)
                            ->orderBy('created_at', 'desc')
                            ->first();
                        if ($lastMovementBeforeFilter) {
                            // Como NO hay movimientos, el final del reporte es igual al inicial
                            $initialStock = $lastMovementBeforeFilter->final_stock;
                            $finalStock = $lastMovementBeforeFilter->final_stock;
                        }
                    }
                }
                if ($filterVariation != null) {
                    if ($variation->id == $filterVariation) {
                        array_push(
                            $componentsFilter,
                            [
                                "id" => $component->id,
                                "name" => $component->name,
                                "initial_stock" => $initialStock,
                                'final_stock' => $finalStock,
                                "item_movements" => $stock_movements
                            ]
                        );
                    }
                } else {
                    array_push(
                        $componentsFilter,
                        [
                            "id" => $component->id,
                            "name" => $component->name,
                            "initial_stock" => $initialStock,
                            'final_stock' => $finalStock,
                            "item_movements" => $stock_movements
                        ]
                    );
                }
            }
        }
        usort($componentsFilter, function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });
        $slicedComponents = array_slice($componentsFilter, $offset, $limit);
        $componentsFilled = ReportHelperTrait::formStockInformationFromMovements($slicedComponents, $store);
        return [
            "data" => $componentsFilled,
            "list" => $componentsFilter
        ];
    }

    public static function formStockInformationFromMovements($slicedComponents, $store)
    {
        // Actions
        $receiveAction = InventoryAction::where('code', 'receive')->first();
        $countAction = InventoryAction::where('code', 'count')->first();
        $damagedAction = InventoryAction::where('code', 'damaged')->first();
        $stolenAction = InventoryAction::where('code', 'stolen')->first();
        $lostAction = InventoryAction::where('code', 'lost')->first();
        $returnedAction = InventoryAction::where('code', 'return')->first();
        $sendTransferAction = InventoryAction::where('code', 'send_transfer')->first();
        $receiveTransferAction = InventoryAction::where('code', 'receive_transfer')->first();
        $consumedAction = InventoryAction::where('code', 'order_consumption')->first();
        $revokedAction = InventoryAction::where('code', 'revoked_order')->first();
        $providerAction = InventoryAction::where('code', 'invoice_provider')->first();
        $filledComponents = [];
        foreach ($slicedComponents as $compVariation) {
            $totalIngresado = $totalReajuste = $totalPerdido = $totalExpirado = 0;
            $totalDevueltos = $totalEnviado = $totalRecibido = $totalConsumido = 0;
            foreach ($compVariation['item_movements'] as $movement) {
                switch ($movement->inventory_action_id) {
                    case $receiveAction['id']:
                        $totalIngresado += $movement->value;
                        break;
                    case $providerAction['id']:
                        $totalIngresado += $movement->value;
                        break;
                    case $countAction['id']:
                        $totalReajuste += $movement->final_stock - $movement->initial_stock;
                        break;
                    case $damagedAction['id']:
                    case $stolenAction['id']:
                        $totalPerdido += $movement->value;
                        break;
                    case $lostAction['id']:
                        $totalExpirado += $movement->value;
                        break;
                    case $returnedAction['id']:
                        $totalDevueltos += $movement->value;
                        break;
                    case $sendTransferAction['id']:
                        $totalEnviado += $movement->value;
                        break;
                    case $receiveTransferAction['id']:
                        $totalRecibido += $movement->value;
                        break;
                    case $consumedAction['id']:
                        $totalConsumido += $movement->value;
                        break;
                    case $revokedAction['id']:
                        $totalConsumido -= $movement->value; // Anulacion se resta de consumos
                        break;
                    default:
                        break;
                }
            }
            // Check Zero Lower Limit ***
            if ($store->configs->zero_lower_limit && $totalConsumido < 0) {
                $totalConsumido = 0;
            }
            array_push(
                $filledComponents,
                [
                    'id' => $compVariation['id'],
                    'name' => $compVariation['name'],
                    'initial' => $compVariation['initial_stock'],
                    'joined' => $totalIngresado,
                    'readjusted' => $totalReajuste,
                    'lost' => $totalPerdido,
                    'expired' => $totalExpirado,
                    'returned' => $totalDevueltos,
                    'transfer_sent' => $totalEnviado,
                    'transfer_received' => $totalRecibido,
                    'consumed' => $totalConsumido,
                    'final' => $compVariation['final_stock']
                ]
            );
        }
        return $filledComponents;
    }

    public static function inventoryStockDataSQL($date, $store)
    {
        $startDate = TimezoneHelper::convertToServerDateTime($date['from'] . "00:00:00", $store);
        $finalDate = TimezoneHelper::convertToServerDateTime($date['to'] . "23:59:59", $store);

        DB::statement("SET sql_mode=(SELECT REPLACE(@@sql_mode, 'ONLY_FULL_GROUP_BY', ''))");
        $reporte = DB::select(
            "select id, categoria, name, initial, readjusted, joined, damaged, expired, lost, returned,
                            transfer_received, transfer_sent,
                            case when (consumed_total-revoked_order)<0 then 0 else (consumed_total-revoked_order) end consumed,
                            update_cost, revoked_order,
                            final
                    from (
                    select *,
                    coalesce(JSON_EXTRACT(JSON_EXTRACT(movimientos, '$[0]'),'$.initial_stock'),
                                (select sm.final_stock
                                    from component_stock cs
                                    join stock_movements sm on cs.id = sm.component_stock_id and sm.created_at<?
                                    where cs.store_id=? and orden.id=cs.component_id order by sm.created_at desc limit 1), 0) as initial,
                    
                    coalesce(JSON_EXTRACT(JSON_EXTRACT(movimientos,CONCAT('$[', JSON_LENGTH(movimientos) - 1, ']')),'$.final_stock'),
                                (select sm.final_stock
                                    from component_stock cs
                                    join stock_movements sm on cs.id = sm.component_stock_id and sm.created_at<?
                                    where cs.store_id=? and orden.id=cs.component_id order by sm.created_at desc limit 1), 0) as final
                    from (
                    select *,
                    (select JSON_ARRAYAGG(JSON_OBJECT('initial_stock', sm.initial_stock, 'final_stock',sm.final_stock))
                    from component_stock cs
                        join stock_movements sm on cs.id = sm.component_stock_id and sm.created_at between ? and ?
                    where cs.store_id=? and cs.component_id=filtro.id order by sm.created_at)  as movimientos
                    from
                    (select c.id,cc.name categoria, concat(c.name) name,
                            sum(case when code='count' then (sm.final_stock - sm.initial_stock) else 0 end ) as readjusted,
                            sum(case when code='receive' then sm.value else 0 end ) as joined,
                            sum(case when code='damaged' then sm.value else 0 end ) as damaged,
                            sum(case when code='stolen' then sm.value else 0 end ) as expired,
                            sum(case when code='lost' then sm.value else 0 end ) as lost,
                            sum(case when code='retsm.urn' then sm.value else 0 end ) as returned,
                            sum(case when code='send_transfer' then sm.value else 0 end ) as transfer_sent,
                            sum(case when code='receive_transfer' then sm.value else 0 end ) as transfer_received,
                            sum(case when code='order_consumption' then sm.value else 0 end ) as consumed_total,
                            sum(case when code='update_cost' then sm.value else 0 end ) as update_cost,
                            sum(case when code='revoked_order' then sm.value else 0 end ) as revoked_order
                    from component_categories cc
                    join components c on cc.id = c.component_category_id and c.status=1
                    left join component_stock cs on cs.component_id=c.id and cs.store_id=? 
                    left join stock_movements sm on cs.id = sm.component_stock_id and sm.created_at between ? and ?
                    left join inventory_actions ia on sm.inventory_action_id = ia.id
                    where cc.company_id=? and cc.status=1 group by c.id) filtro order by name) orden) reporte",
            array($startDate, $store->id, $startDate, $store->id, $startDate, $finalDate, $store->id, $store->id, $startDate, $finalDate, $store->company_id)
        );

        return $reporte;
    }

    public static function consumptionsOrders($ordersData)
    {
        foreach ($ordersData as &$orderData) {
            // Por producto
            $consumptionsProductOrder = ReportHelperTrait::consumptionsProductOrder($orderData);
            $orderData["consumptionsProducts"] = $consumptionsProductOrder;
            // Por especificación
            $consumptionsSpecOrder = ReportHelperTrait::consumptionsSpecificationsOrder($orderData);
            $orderData["consumptionsSpecifications"] = $consumptionsSpecOrder;
        }
        return $ordersData;
    }

    public static function consumptionsProductOrder($orderData)
    {
        $consumptions = [];
        // Obteniendo la información de consumo del producto
        $productComponents = ProductDetail::select(
            'product_details.id as db_proddet_id',
            'product_details.product_id as db_proddet_prodid',
            'products.id as db_prod_id',
            'product_components.id as db_prodcomp_id',
            'product_components.product_id as db_prodcomp_prodid',
            'product_components.component_id as db_prodcomp_compvarid',
            'product_components.consumption as db_prodcomp_consumption'
        )
            ->where('product_details.id', $orderData->db_detail_proddetid)
            ->leftJoin(
                'products',
                function ($joinProducts) use ($orderData) {
                    $joinProducts->on(
                        'products.id',
                        '=',
                        'product_details.product_id'
                    )
                        ->leftJoin(
                            'product_components',
                            function ($joinProdComp) use ($orderData) {
                                $joinProdComp->on(
                                    'product_components.product_id',
                                    '=',
                                    'products.id'
                                )
                                    ->where(
                                        'product_components.status',
                                        1
                                    );
                            }
                        );
                }
            )
            ->get();
        // Recorriendo cada consumo de item definido para el producto
        foreach ($productComponents as $prodComponent) {
            if (
                $prodComponent->db_prodcomp_consumption
                && $prodComponent->db_prodcomp_consumption > 0
            ) {
                // Consumo por el producto de esta oden
                $consumptionProduct =
                    $prodComponent->db_prodcomp_consumption * $orderData->db_detail_quantity;
                // Total de consumo por subreceta de esta orden
                $consumptionSubrecipe = ReportHelperTrait::consumptionsSubrecipe(
                    $prodComponent->db_prodcomp_compvarid,
                    $consumptionProduct
                );
                $consumption = [
                    "variation_id" => $prodComponent->db_prodcomp_compvarid,
                    "consumption" => $consumptionProduct + $consumptionSubrecipe
                ];
                array_push($consumptions, $consumption);
            }
        }
        return $consumptions;
    }

    public static function consumptionsSpecificationsOrder($orderData)
    {
        $consumptions = [];
        $componentConsumptions = [];
        $prodSpecs = [];
        $orderProdSpecs = OrderProductSpecification::select(
            'order_product_specifications.id as db_ordprodspec_id',
            'order_product_specifications.order_detail_id as db_ordprodspec_detailid',
            'order_product_specifications.specification_id as db_ordprodspec_specid',
            'order_product_specifications.quantity as db_ordprodspec_quantity'
        )
            ->where('order_product_specifications.order_detail_id', $orderData->db_detail_id)
            ->get();
        foreach ($orderProdSpecs as $orderProdSpec) {
            // Obteniendo los product specifications para esta specification en particular, para
            // ver si esta combinación tiene consumo de items definido
            $prodSpecs = ProductDetail::select(
                'product_details.id as db_proddet_id',
                'product_details.product_id as db_proddet_prodid',
                'products.id as db_prod_id',
                'product_specifications.id as db_prodspec_id',
                'product_specifications.product_id as db_prodspec_prodid',
                'product_specifications.specification_id as db_prodspec_specid'
            )
                ->where('product_details.id', $orderData->db_detail_proddetid)
                ->leftJoin(
                    'products',
                    function ($joinProducts) use ($orderProdSpec) {
                        $joinProducts->on(
                            'products.id',
                            '=',
                            'product_details.product_id'
                        )
                            ->leftJoin(
                                'product_specifications',
                                function ($joinProdSpecs) use ($orderProdSpec) {
                                    $joinProdSpecs->on(
                                        'product_specifications.product_id',
                                        '=',
                                        'products.id'
                                    )
                                        ->where(
                                            'product_specifications.specification_id',
                                            $orderProdSpec["db_ordprodspec_specid"]
                                        );
                                }
                            );
                    }
                )
                ->get();

            if (count($prodSpecs) > 0) {
                foreach ($prodSpecs as $prodSpec) {
                    // Obteniendo info consumo de la combinación product_specification
                    $prodSpecsComp = ProductSpecificationComponent::where(
                        'prod_spec_id',
                        $prodSpec->db_prodspec_id
                    )
                        ->with([
                            'variation' => function ($variation) {
                                $variation->with(['component', 'unit']);
                            }
                        ])
                        ->get();
                    if (count($prodSpecsComp)) {
                        foreach ($prodSpecsComp as $prodSpecComp) {
                            if ($prodSpecComp->consumption && $prodSpecComp->consumption > 0) {
                                // Consumo por la especificación de esta oden
                                $consumptionSpec = $prodSpecComp->consumption * $orderProdSpec["db_ordprodspec_quantity"];
                                // Total de consumo por subreceta de esta orden
                                $consumptionSubrecipe = ReportHelperTrait::consumptionsSubrecipe(
                                    $prodSpecComp->component_id,
                                    $consumptionSpec
                                );
                                $consumption = [
                                    "variation_id" => $prodSpecComp->component_id,
                                    "consumption" => $consumptionSpec + $consumptionSubrecipe
                                ];
                                array_push($consumptions, $consumption);
                            }
                        }
                    }
                }
            } else {
                // Obteniendo la información de consumo de items por parte de esta especificación
                $componentConsumptions = SpecificationComponent::select(
                    'specification_components.id as db_speccomp_id',
                    'specification_components.consumption as db_speccomp_consumption',
                    'specification_components.component_id as db_speccomp_compvarid'
                )
                    ->where(
                        'specification_id',
                        $orderProdSpec["db_ordprodspec_specid"]
                    )
                    ->where('status', 1)
                    ->get();
                // Si no tiene product specifications se calcula el consumo directamente de la
                // specification y sus consumos de items definidos
                foreach ($componentConsumptions as $specificationComponent) {
                    if (
                        $specificationComponent->db_speccomp_consumption
                        && $specificationComponent->db_speccomp_consumption > 0
                    ) {
                        // Consumo por la especificación de esta oden
                        $consumptionSpec =
                            $specificationComponent->db_speccomp_consumption * $orderProdSpec["db_ordprodspec_quantity"];
                        // Total de consumo por subreceta de esta orden
                        $consumptionSubrecipe = ReportHelperTrait::consumptionsSubrecipe(
                            $specificationComponent->db_speccomp_compvarid,
                            $consumptionSpec
                        );
                        $consumption = [
                            "variation_id" => $specificationComponent->db_speccomp_compvarid,
                            "consumption" => $consumptionSpec + $consumptionSubrecipe
                        ];
                        array_push($consumptions, $consumption);
                    }
                }
            }
        }
        return $consumptions;
    }


    public static function ordersByEmployee($store_id, $date, $company_id)
    {
        // To-do: Cambiar a invoices.
        $store = Store::find($store_id);
        $startDate = TimezoneHelper::convertToServerDateTime($date['from'] . '00:00:00', $store);
        $finalDate = TimezoneHelper::convertToServerDateTime($date['to'] . '23:59:59', $store);

        /* Todas las ventas de la tienda en el intervalo de fechas*/
        $ordersStore = Order::where([
            ['store_id', '=', $store_id],
            ['status',   '=', 1],
            ['preorder', '=', 0]
        ])
            ->whereBetween('created_at', [$startDate, $finalDate])
            ->with('invoice')
            ->with('payments')
            ->get();


        $invoices = Invoice::whereIn('order_id', $ordersStore->pluck('id')->toArray())->get();

        /* Ventas totales de la tienda en el intervalo de fechas*/
        $totalSalesStore = $invoices->sum('total');

        /* Propinas totales de la tienda en el intervalo de fechas*/
        $totalTipsStore = 0;
        foreach ($ordersStore as $order) {
            foreach ($order['payments'] as $payout) {
                $totalTipsStore += $payout['tip'];
            }
        }

        $resByEmployee = [];

        foreach ($ordersStore->unique('employee_id') as $order) {

            /* Totaliza las ventas por empleado */
            $totalEmployeeSales = $ordersStore->where('employee_id', $order->employee_id)->sum('total');

            /* Totaliza las propinas por empleado */
            $totalEmployeeTips = 0;
            foreach ($ordersStore->where('employee_id', $order->employee_id) as $order) {
                foreach ($order['payments'] as $payout) {
                    $totalEmployeeTips += $payout['tip'];
                }
            }
            /* Número de clientes atentidos - "People" Null in db*/
            $totalEmployeeNumberClientsNull = $ordersStore->where('employee_id', $order->employee_id)
                ->where('people', null)
                ->count();

            /* Número de clientes atentidos - "People" not Null in db*/
            $totalEmployeeNumberClientsNotNull = $ordersStore->where('employee_id', $order->employee_id)
                ->where('people', '>', 0)
                ->sum('people');

            /* Sumatoria de clientes atendidos */
            $totalEmployeeNumberClients = $totalEmployeeNumberClientsNull + $totalEmployeeNumberClientsNotNull;

            /* Promedio de ventas por cliente */
            if ($totalEmployeeNumberClients > 0) {
                $totalEmployeeSalesAvgByClient = $totalEmployeeSales / $totalEmployeeNumberClients;
            } else {
                $totalEmployeeSalesAvgByClient = 0;
            }

            /* Número de mesas = Número de ordenes que registró el mesero*/
            $totalEmployeeNumberOrders = $ordersStore->where('employee_id', $order->employee_id)->count();

            /* Promedio vendido por mesa */
            if ($totalEmployeeNumberClients > 0) {
                $totalEmployeeSalesAvgByOrder = $totalEmployeeSales / $totalEmployeeNumberOrders;
            } else {
                $totalEmployeeSalesAvgByOrder = 0;
            }

            /* Organiza el array de respuesta */
            $add = [
                'employee_id' => $order->employee_id,
                'employee_name' => Employee::find($order->employee_id)->name,
                'total_sales' => Helper::bankersRounding($totalEmployeeSales, 2) / 100,
                'total_tips' => Helper::bankersRounding($totalEmployeeTips, 2) / 100,
                'total_sales_avg_by_client' => Helper::bankersRounding($totalEmployeeSalesAvgByClient, 2) / 100,
                'total_sales_avg_by_table' => Helper::bankersRounding($totalEmployeeSalesAvgByOrder, 2) / 100,
                'total_clients_attended'  => $totalEmployeeNumberClients,
                'total_tables_attended' => $totalEmployeeNumberOrders,
                'total_sales_percentage' => round(((int) $totalEmployeeSales * 100) / $totalSalesStore, 4)
            ];

            array_push($resByEmployee, $add);
        }

        if (count($resByEmployee) == 0) {
            return "No se encontraron resultados";
        }

        /*Totalización ↓ */
        $sumTotalSales = 0;
        $sumTotalTips = 0;
        $sumSalesAvgByClient = 0;
        $sumSalesAvgByTable = 0;
        $sumClientsAttended = 0;
        $sumTablesAttended = 0;

        foreach ($resByEmployee as $employee) {
            $sumTotalSales += $employee['total_sales'];
            $sumTotalTips += $employee['total_tips'];
            $sumSalesAvgByClient += $employee['total_sales_avg_by_client'];
            $sumSalesAvgByTable += $employee['total_sales_avg_by_table'];
            $sumClientsAttended += $employee['total_clients_attended'];
            $sumTablesAttended += $employee['total_tables_attended'];
        }

        /*Calculo de AVG*/
        $sumSalesAvgByClient = $sumSalesAvgByClient / count($resByEmployee);
        $sumSalesAvgByTable = $sumSalesAvgByTable / count($resByEmployee);

        return [
            "data" => $resByEmployee,
            "resume" => [
                "sum_sales_avg_by_client" =>  Helper::bankersRounding($sumSalesAvgByClient, 0),
                "sum_sales_avg_by_table" => Helper::bankersRounding($sumSalesAvgByTable, 0),
                "sum_clients_attended" => $sumClientsAttended,
                "sum_tables_attended" => $sumTablesAttended,
                "sum_sales_store" => Helper::bankersRounding($totalSalesStore, 2) / 100,
                "sum_tips_store" => Helper::bankersRounding($totalTipsStore, 2) / 100,
                "store_name" => Store::find($store_id)->name
            ],
            "items" => count($resByEmployee)
        ];
    }

    public static function hourlyData($date, $store_id)
    {
        $store = Store::find($store_id);
        //Parseo de las fechas de inicio y fin para la obtención de la data
        $startDate = TimezoneHelper::convertToServerDateTime($date['from'] . '00:00:00', $store);
        $finalDate = TimezoneHelper::convertToServerDateTime($date['to'] . '23:59:59', $store);
        $storeOffset = TimezoneHelper::getStoreTimezoneOffset($store);
        $serverOffset = TimezoneHelper::getServerTimezoneOffset();
        $data = DB::select(DB::raw(
            "SELECT HOUR(CONVERT_TZ(iv.created_at, '$serverOffset', '$storeOffset')) AS hora,
            COUNT(iv.id) AS num_fact, SUM(iv.total) AS monto
            FROM invoices AS iv LEFT JOIN orders AS o
            ON o.id = iv.order_id WHERE o.store_id = '$store_id'
            AND iv.created_at >= '$startDate'
            AND iv.created_at <= '$finalDate'
            GROUP BY HOUR(CONVERT_TZ(iv.created_at, '$serverOffset', '$storeOffset'))"
        ));
        return $data;
    }

    public static function weekDayData($date, $store_id)
    {
        $store = Store::find($store_id);
        $startDate = TimezoneHelper::convertToServerDateTime($date['from'] . '00:00:00', $store);
        $finalDate = TimezoneHelper::convertToServerDateTime($date['to'] . '23:59:59', $store);
        $storeOffset = TimezoneHelper::getStoreTimezoneOffset($store);
        $serverOffset = TimezoneHelper::getServerTimezoneOffset();
        //0 = Monday, 1 = Tuesday, 2 = Wednesday, 3 = Thursday, 4 = Friday, 5 = Saturday, 6 = Sunday
        $data = DB::select(DB::raw(
            "SELECT WEEKDAY(CONVERT_TZ(iv.created_at, '$serverOffset', '$storeOffset')) AS dia,
            COUNT(iv.id) AS num_fact, SUM(iv.total) AS monto 
            FROM invoices AS iv
            LEFT JOIN orders AS o ON o.id = iv.order_id
            WHERE o.store_id = '$store_id'
            AND o.status = 1
            AND iv.created_at >= '$startDate'
            AND iv.created_at <= '$finalDate'
            GROUP BY WEEKDAY(CONVERT_TZ(iv.created_at, '$serverOffset', '$storeOffset'))"
        ));
        return $data;
    }

    public static function categorySalesData($date, $store_id)
    {
        $store = Store::find($store_id);
        //Parseo de las fechas de inicio y fin para la obtención de la data
        $startDate = TimezoneHelper::convertToServerDateTime($date['from'] . '00:00:00', $store);
        $finalDate = TimezoneHelper::convertToServerDateTime($date['to'] . '23:59:59', $store);

        $data = DB::select(DB::raw("SELECT pc.id AS id, 
        pc.name AS category_name, 
        COUNT(pc.id) AS category_sales, 
        SUM(od.total) AS category_value 
        FROM invoices AS iv 
        LEFT JOIN orders AS o ON o.id = iv.order_id 
        LEFT JOIN order_details AS od ON od.order_id = o.id 
        LEFT JOIN product_details AS pd ON pd.id = od.product_detail_id 
        LEFT JOIN products AS pr ON pr.id = pd.product_id 
        LEFT JOIN product_categories AS pc ON pc.id = pr.product_category_id 
        WHERE o.store_id = '$store_id' 
        AND pc.name IS NOT NULL
        AND iv.created_at >= '$startDate' 
        AND iv.created_at <= '$finalDate'
        GROUP BY pc.id, pc.name;"));

        return $data;
    }

    public static function reportePorcentajeVentasXCategoria($date, $store_id)
    {
        $store = Store::find($store_id);
        //Parseo de las fechas de inicio y fin para la obtención de la data
        $startDate = TimezoneHelper::convertToServerDateTime($date['from'] . '00:00:00', $store);
        $finalDate = TimezoneHelper::convertToServerDateTime($date['to'] . '23:59:59', $store);

        $data = DB::select(DB::raw("
        SELECT pr.id AS id, pr.name AS category_name, COUNT(pr.id) AS category_sales, SUM(od.total) AS category_value
        FROM invoices AS iv LEFT JOIN orders AS o ON o.id = iv.order_id LEFT JOIN order_details AS od ON od.order_id = o.id LEFT JOIN product_details AS pd ON pd.id = od.product_detail_id LEFT JOIN products AS pr ON pr.id = pd.product_id LEFT JOIN product_categories AS pc ON pc.id = pr.product_category_id 
        WHERE o.store_id = '$store_id' AND pr.name IS NOT NULL AND DATE(iv.created_at) >= '2019-07-01' AND DATE(iv.created_at) <= '2019-07-30' GROUP BY pr.id, pr.name;
        "));
    }


    public static function transactionsClosingCashier($cashier_balance_id, Store $store)
    {
        $storeOffset = TimezoneHelper::getStoreTimezoneOffset($store);
        $serverOffset = TimezoneHelper::getServerTimezoneOffset();
        //Parseo de las fechas de inicio y fin para la obtención de la data
        DB::statement("SET sql_mode=(SELECT REPLACE(@@sql_mode, 'ONLY_FULL_GROUP_BY', ''))");
        $transactions = DB::select("select *,
        case when status=2 then 'Anulada' else
           case when (cash=0 and debit=0 and credit=0 and
                      transfer=0 and other=0 and rappi_pay=0 and external_provider_check=false) then 'Efectivo'
            else
                if(external_provider_check=true,external_provider,concat(
                    if(cash,'Efectivo',''),
                    if(debit,'Tarjeta de Débito',''),
                    if(credit,'Tarjeta de Crédito',''),
                    if(transfer,'Transferencia',''),
                    if(other,'Otros',''),
                    if(rappi_pay,'Rappi Pay','')
                ))
            end
        end as payment_method  from (
            select ord.status, ord.id as order_id, e.name as employee, ord.device_id as device_id, 
            eb.value as expense_value, eb.name as expense_name,
                DATE_FORMAT(CONVERT_TZ(ord.created_at, ?, ?), '%Y-%m-%d %H:%i:%s') as created_at,
                coalesce(oid.integration_name,'') as external_provider,
                items.id,
                coalesce(invoice.name,'') as customer,
                coalesce(invoice.invoice_number,'') as fact,
                invoice.discount_percentage,
                items.product_name as product,
                items.quantity,
                items.base_value,
                items.total,
                (items.base_value / items.quantity) as unit_price,
                (items.total - items.base_value) as unit_tax,
        if(oid.id is not null, true, false) as external_provider_check,
        sum(case when p.type=0 then p.total else 0 end) as cash,
        sum(case when p.type=1 then p.total else 0 end) as debit,
        sum(case when p.type=2 then p.total else 0 end) as credit,
        sum(case when p.type=3 then p.total else 0 end) as transfer,
        sum(case when p.type=4 then p.total else 0 end) as other,
        sum(case when p.type=5 then p.total else 0 end) as rappi_pay
        from orders ord
        left join payments p on ord.id = p.order_id
        join employees e on ord.employee_id = e.id
        left join expenses_balances eb on ord.cashier_balance_id = eb.cashier_balance_id
        left join order_integration_details oid on oid.id=(
         select id from order_integration_details where order_id=ord.id order by id desc limit 1
        )
        join invoices invoice on invoice.id=(
         select id from invoices where order_id=ord.id order by id desc limit 1
        )
        join invoice_items items on items.invoice_id=invoice.id
        where ord.cashier_balance_id=? and preorder=0
        group by items.id
            ) filtro;", array($serverOffset, $storeOffset, $cashier_balance_id));

        $data = [];
        foreach ($transactions as $key => $trasaction) {
            // $trasaction->
            $subtotal = $trasaction->base_value;
            $total = $trasaction->total;
            $discount = round($trasaction->discount_percentage / 100, 2);
            $discounted_value = 0;
            $tax = $trasaction->unit_tax;
            $discounted_subtotal = $trasaction->base_value;
            $tax_perc = $tax > 0 ? round(($tax / $subtotal) * 100, 2) : 0;
            if ($discount > 0) {
                $discounted_value = $subtotal * $discount;
                $discounted_subtotal = $subtotal * (1 - $discount);
                $tax = round($discounted_subtotal * $tax_perc / 100, 2);
                $total = $discounted_subtotal + $tax;
            }
            $data[] = [
                'id' => $trasaction->id,
                'date' => Carbon::parse($trasaction->created_at),
                'fact' => $trasaction->fact,
                'customer' => $trasaction->customer,
                'product' => $trasaction->product,
                'quantity' => $trasaction->quantity,
                'device_id' => $trasaction->device_id,
                'value' => Helper::bankersRounding($trasaction->unit_price, 0) / 100,
                'employee' => $trasaction->employee,
                'cash' => Helper::bankersRounding($trasaction->cash, 0),
                'debit' => Helper::bankersRounding($trasaction->debit, 0),
                'credit' => Helper::bankersRounding($trasaction->credit, 0),
                'transfer' => Helper::bankersRounding($trasaction->transfer, 0),
                'rappi_pay' => Helper::bankersRounding($trasaction->rappi_pay, 0),
                'others' => Helper::bankersRounding($trasaction->other, 0),
                'tax' => Helper::bankersRounding($tax, 0) / 100,
                'tax_perc' => $tax_perc,
                'subtotal' => Helper::bankersRounding($subtotal, 0) / 100,
                'discounted_value' => Helper::bankersRounding($discounted_value, 0) / 100,
                'discounted_subtotal' => Helper::bankersRounding($discounted_subtotal, 0) / 100,
                'total' => Helper::bankersRounding($total, 0) / 100,
                'category' => '',
                'external_provider' => $trasaction->external_provider,
                'payment_method' => $trasaction->payment_method,
                'expense_value' => $trasaction->expense_value,
                'expense_name' => $trasaction->expense_name
            ];
        }
        return $data;
    }
}
