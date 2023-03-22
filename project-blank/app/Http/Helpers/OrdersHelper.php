<?php

namespace App\Http\Helpers;

use Log;
use App\Helper;
use App\ProductComponent;
use App\Order;
use App\ProductSpecificationComponent;
use App\ProductSpecification;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Expose functions to split and create orders
 */
// @codingStandardsIgnoreLine
class OrdersHelper
{

    public static function dispatchJobs($jobs)
    {
        if ($jobs == null || empty($jobs)) {
            return;
        }

        $rabbitHost = config('app.rabbitmq_host');
        $rabbitPort = config('app.rabbitmq_port');
        $rabbitUser = config('app.rabbitmq_username');
        $rabbitPswd = config('app.rabbitmq_password');
        $rabbitVhost = config('app.rabbitmq_vhost');

        $connection = new AMQPStreamConnection($rabbitHost, $rabbitPort, $rabbitUser, $rabbitPswd, $rabbitVhost);
        $channel = $connection->channel();
        $channel->exchange_declare('order-created', 'fanout', false, false, false);

        foreach ($jobs as $job) {
            $msg = new AMQPMessage(json_encode($job));
            $channel->batch_basic_publish($msg, 'order-created');
        }

        $channel->publish_batch();
        $channel->close();
        $connection->close();
    }

    public static function saveOrder($order, $employee)
    {
        $jobs = OrdersHelper::getOrderJobs($order, $employee);
        OrdersHelper::dispatchJobs($jobs);
    }

    public static function getOrderJobs($order, $employee)
    {
        $store = $employee->store;
        
        $order = OrdersHelper::getSingleOrder($order->id);

        try {
            $job = array();
            $job["store_id"] = $store->id;
            $job["order"] = $order;

            return $job;
        } catch (\Exception $e) {
            Log::info("OrdersHelper: NO SE PUDO ENVIAR LA ORDEN");
            Log::info($e->getMessage());
            Log::info("Archivo");
            Log::info($e->getFile());
            Log::info("Línea");
            Log::info($e->getLine());
            Log::info("Provocado por");
            Log::info(json_encode($order));
        }
    }

    public static function getSingleOrder($order_id)
    {
        $order = Order::select(
            'id',
            'identifier',
            'total',
            'order_value',
            'cash',
            'spot_id',
            'created_at',
            'store_id'
        )->with(
            [
                'invoice' => function ($invoice) {
                    $invoice->select(
                        'id',
                        'billing_id',
                        'order_id',
                        'total',
                        'document',
                        'name',
                        'address',
                        'phone',
                        'email',
                        'subtotal',
                        'tax',
                        'created_at',
                        'discount_percentage',
                        'discount_value',
                        'undiscounted_subtotal',
                        'tip'
                    )->with('billing');
                },
                'orderDetails' => function ($detail) {
                    $detail->with([
                        'productDetail',
                        'orderSpecifications'
                    ]);
                },
                'spot',
                'payments'
            ]
        )
        ->where('id', $order_id)
        ->where('status', 1)
        ->where('preorder', 0)
        ->first();

        foreach ($order->orderDetails as &$detail) {
            $detail->append('spec_fields');
            $detailConsumption = "";
            $prodCompConsumptions = ProductComponent::where(
                'product_id',
                $detail->productDetail->product_id
            )
                ->with([
                    'variation' => function ($variation) {
                        $variation->with(['unit']);
                    }
                ])
                ->where('status', 1)
                ->get();
            foreach ($prodCompConsumptions as $prodCompConsumption) {
                if (
                    $prodCompConsumption->variation->unit != null
                    && $prodCompConsumption->consumption > 0
                ) {
                    $detailConsumption = $detailConsumption .
                        "    Por Producto:           " . $prodCompConsumption->variation->name
                        . "  " . ($prodCompConsumption->consumption * $detail->quantity)
                        . "(" . $prodCompConsumption->variation->unit->short_name . ")"
                        . "\n";
                }
            }
            foreach ($detail->orderSpecifications as $orderSpec) {
                $detailConsumption = $detailConsumption .
                OrdersHelper::getConsumptionDetails(
                        $orderSpec,
                        $detail->productDetail->product_id
                    );
            }
            $detail['consumption'] = $detailConsumption;
        }

        return $order;
    }   

    public static function getConsumptionDetails($orderProductSpecification, $productId)
    {
        $prodSpec = ProductSpecification::where(
            'product_id',
            $productId
        )
        ->where('specification_id', $orderProductSpecification->specification_id)
        ->first();
        if ($prodSpec) {
            $prodSpecComp = ProductSpecificationComponent::where(
                'prod_spec_id',
                $prodSpec->id
            )->with([
                'variation' => function ($variation) {
                    $variation->with(['unit']);
                }
            ])->first();
            if ($prodSpecComp) {
                return "    Por Especificación:  " . $prodSpecComp->variation->name
                    . "  " . ($prodSpecComp->consumption * $orderProductSpecification->quantity)
                    . "(" . $prodSpecComp->variation->unit->short_name . ")"
                    . "\n";
            }
        }
        return "";
    }
}