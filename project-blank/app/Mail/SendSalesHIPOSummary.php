<?php

namespace App\Mail;

use Log;
use App\Order;
use App\Spot;
use App\Store;
use App\Company;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendSalesHIPOSummary extends Mailable
{
    use Queueable, SerializesModels;

    public $company;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct($company)
    {
        $this->company = $company;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        Log::info('SendSalesHIPOSummary');
        $date = Carbon::today()->subDay();
        $past_week = $date->copy()->subDays(7);
        $total_orders = 0;
        $total_value = 0;
        $company_id = $this->company->id;
        $company_name = $this->company->name;

        $store_summary = [];

        $string_json_start = "{
          type:'bar',
          options:{
            legend:{ display:false },
            scales:{
              yAxes:[{ ticks:{ fontSize:8 }}],
              xAxes:[{ ticks:{ fontSize:7 }}]
            }},
            data:{ labels:";
        $string_json_middle = ", datasets:[{ backgroundColor: 'rgb(102, 0, 102)', data:";
        $string_json_end = "}]}}";

        foreach ($this->company->stores as $st) {
            //Inicializando
            $value = 0;
            $ammount = 0;
            $value_cash = 0;
            $value_debit = 0;
            $value_credit = 0;
            $ammount_cash = 0;
            $ammount_debit = 0;
            $ammount_credit = 0;
            $spot_summary = [];

            $orders = $st->orders()->whereRaw("DATE(created_at) = '$date' AND current_status IS NOT NULL");
            $past_orders = $st->orders()->whereRaw("DATE(created_at) = '$past_week' AND current_status IS NOT NULL");

            $spots = $st->spots()->get();

            $value = $orders->sum('total');
            $ammount = $orders->count();
            $past_ammount = $past_orders->count();

            $total_value += $value;
            $total_orders += $ammount;

            $store_max = DB::select(DB::raw(
               "SELECT
                    HOUR(o.created_at) AS hour,
                    COUNT(*) AS total
                FROM orders AS o
                WHERE
                    o.store_id = '$st->id' AND
                    DATE(o.created_at) = '$date'
                GROUP BY
                    HOUR(o.created_at)
                ORDER BY
                    total DESC LIMIT 1;"
            ));

            $customers_data = DB::select(DB::raw(
               "SELECT
                    iv.billing_id,
                    COUNT(iv.id) AS bill_ammount,
                    SUM(o.order_value/100) AS bill_value
                FROM invoices AS iv
                INNER JOIN orders AS o ON o.id = iv.order_id
                WHERE
                    o.store_id = '$st->id' AND
                    DATE(o.created_at) = '$date'
                GROUP BY iv.billing_id
                ORDER BY bill_value DESC;"
            ));

            $prom = $ammount > 0 ? round($value/$ammount, 2) : 0;

            $customer_ammount = count($customers_data);

            $store_max_percentage = $ammount > 0 ? round(($store_max[0]->total/$ammount) * 100, 2) : 0;

            // Efectivo
            $cashSpotValue = null;
            $cashSpotAmmount = null;
            $cashSpotPercentage = null;

            // Débito
            $debitSpotValue = null;
            $debitSpotAmmount = null;
            $debitSpotPercentage = null;

            // Crédito
            $creditSpotValue = null;
            $creditSpotAmmount = null;
            $creditSpotPercentage = null;

            // Transfer
            $transferSpotValue = null;
            $transferSpotAmmount = null;
            $transferSpotPercentage = null;

            // Other
            $otherSpotValue = null;
            $otherSpotAmmount = null;
            $otherSpotPercentage = null;

            // rappiPay
            $rappiPaySpotValue = null;
            $rappiPaySpotAmmount = null;
            $rappiPaySpotPercentage = null;

            foreach ($spots as $spt) {
                if($spt->origin <= 1){
                    $orders = $spt->orders()->whereRaw("DATE(created_at) = '$date'")->get();

                    foreach ($orders as $order) {
                        $payments = $order->payments()->get();

                        foreach ($payments as $payment) {
                            switch ($payment->type) {
                                case 0:
                                    $cashSpotValue = $cashSpotValue + $payment->total;
                                    $cashSpotAmmount = $cashSpotAmmount + 1;
                                    $cashSpotPercentage = $ammount > 0 ? round( ($cashSpotAmmount / $ammount) * 100,2) : 0;
                                break;

                                case 1:
                                    $debitSpotValue = $debitSpotValue + $payment->total;
                                    $debitSpotAmmount = $debitSpotAmmount + 1;
                                    $debitSpotPercentage = $ammount > 0 ? round( ($debitSpotAmmount / $ammount) * 100,2) : 0;
                                break;

                                case 2:
                                    $creditSpotValue = $creditSpotValue + $payment->total;
                                    $creditSpotAmmount = $creditSpotAmmount + 1;
                                    $creditSpotPercentage = $ammount > 0 ? round( ($creditSpotAmmount / $ammount) * 100,2) : 0;
                                break;

                                case 3:
                                    $transferSpotValue = $transferSpotValue + $payment->total;
                                    $transferSpotAmmount = $transferSpotAmmount + 1;
                                    $transferSpotPercentage = $ammount > 0 ? round( ($transferSpotAmmount / $ammount) * 100,2) : 0;
                                break;

                                case 4:
                                    $otherSpotValue = $otherSpotValue + $payment->total;
                                    $otherSpotAmmount = $otherSpotAmmount + 1;
                                    $otherSpotPercentage = $ammount > 0 ? round( ($otherSpotAmmount / $ammount) * 100,2) : 0;
                                break;

                                case 5:
                                    $rappiPaySpotValue = $rappiPaySpotValue + $payment->total;
                                    $rappiPaySpotAmmount = $rappiPaySpotAmmount + 1;
                                    $rappiPaySpotPercentage = $ammount > 0 ? round( ($rappiPaySpotAmmount / $ammount) * 100,2) : 0;
                                break;
                            }
                        }

                    }

                } else {
                    $or = $spt->orders()->whereRaw("DATE(created_at) = '$date'");

                    $spot_value = $or->sum('total');
                    $spot_ammount = $or->count();
                    $spot_percentage = $ammount > 0 ? round(($spot_ammount / $ammount) * 100,2) : 0;
                    if ($spot_value > 0) {
                        $spot_summary[] = [
                            'spot_name' => Spot::getNameIntegrationByOrigin($spt->origin),
                            'spot_value' => round($spot_value/100,2),
                            'spot_ammount' => $spot_ammount,
                            'spot_percentage' => $spot_percentage,
                        ];
                    }
                }
            }

            // Efectivo
            if ($cashSpotValue > 0) {
                $spot_summary[] = [
                    'spot_name' => 'Efectivo',
                    'spot_value' => round($cashSpotValue/100,2),
                    'spot_ammount' => $cashSpotAmmount,
                    'spot_percentage' => $cashSpotPercentage,
                ];
            }

            //Débito
            if ($debitSpotValue > 0) {
                $spot_summary[] = [
                    'spot_name' => 'T. Débito',
                    'spot_value' => round($debitSpotValue/100,2),
                    'spot_ammount' => $debitSpotAmmount,
                    'spot_percentage' => $debitSpotPercentage,
                ];
            }

            //Crédito
            if ($creditSpotValue > 0) {
                $spot_summary[] = [
                    'spot_name' => 'T. Crédito',
                    'spot_value' => round($creditSpotValue/100,2),
                    'spot_ammount' => $creditSpotAmmount,
                    'spot_percentage' => $creditSpotPercentage,
                ];
            }

            // Transfer
            if ($transferSpotValue > 0) {
                $spot_summary[] = [
                    'spot_name' => 'Transferencia',
                    'spot_value' => round($transferSpotValue/100,2),
                    'spot_ammount' => $transferSpotAmmount,
                    'spot_percentage' => $transferSpotPercentage,
                ];
            }

            // Other
            if ($otherSpotValue > 0) {
                $spot_summary[] = [
                    'spot_name' => 'Otros medios',
                    'spot_value' => round($otherSpotValue/100,2),
                    'spot_ammount' => $otherSpotAmmount,
                    'spot_percentage' => $otherSpotPercentage,
                ];
            }

            // RappiPay
            if ($rappiPaySpotValue > 0) {
                $spot_summary[] = [
                    'spot_name' => 'RappiPay',
                    'spot_value' => round($rappiPaySpotValue/100,2),
                    'spot_ammount' => $rappiPaySpotAmmount,
                    'spot_percentage' => $rappiPaySpotPercentage,
                ];
            }

            $growth = $past_ammount > 0 ? round((($ammount - $past_ammount)/$past_ammount) * 100,2) : 100;

            $store_summary[] = [
                'name' => $st->name,
                'store_orders' => $ammount,
                'store_total_value' => round($value/100,2),
                'store_prom' => round($prom/100,2),
                'store_customer_ammount' => $customer_ammount,
                'store_max_hour' => $store_max ? $store_max[0]->hour . ':00' : 'N/A',
                'store_max_orders' => $store_max ? $store_max[0]->total : 0,
                'store_max_percentage' => $store_max_percentage,
                'store_growth' => $growth,
                'spots' => $spot_summary,
            ];
            $labels[] = $st->name;
            $datasets[] = $ammount;
        }

        $final_json = $string_json_start ."". json_encode($labels) ."". $string_json_middle ."". json_encode($datasets) ."". $string_json_end;

        $max_orders = DB::select(DB::raw(
           "SELECT
                HOUR(o.created_at) AS hour,
                COUNT(*) AS total
            FROM orders AS o
            INNER JOIN stores AS st ON st.id = o.store_id
            WHERE
                st.company_id = '$company_id' AND
                DATE(o.created_at) = '$date'
            GROUP BY HOUR(o.created_at)
            ORDER BY total DESC LIMIT 1;"
        ));

        $prom_value = $total_orders > 0 ? round($total_value / $total_orders,2) : 0;
        $percentage_max_value = $total_orders > 0 ? round(($max_orders[0]->total/$total_orders) * 100,2) : 0;

        $config = [
            'company_name' => $company_name,
            'total_value' => round($total_value/100,2),
            'total_orders' => $total_orders,
            'date' => $date->format('Y-m-d'),
            'prom_value' => round($prom_value/100,2),
            'max_hour' => $max_orders ? $max_orders[0]->hour . ':00' : 'N/A',
            'max_orders' => $max_orders ? $max_orders[0]->total : 0,
            'percentage_max_value' => $percentage_max_value,
        ];

        return $this->withSwiftMessage(function ($message) {
            $headers = $message->getHeaders();
            })->subject("HIPO RESUMEN DE VENTAS " . $date->format('Y-m-d'))
                ->view('maileclipse::templates.hIPOSalesSummary')
                ->with('config', $config)
                ->with('stores', $store_summary)
                ->with('graph_json', $final_json);
    }
}
