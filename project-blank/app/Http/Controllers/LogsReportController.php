<?php

namespace App\Http\Controllers;

use App\Card;
use App\Employee;
use App\OrderDetail;
use App\Payment;
use App\Spot;
use App\Traits\TimezoneHelper;
use App\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LogsReportController extends Controller
{
    /**
     * Display a listing of logs.
     *
     * @return \Illuminate\Http\Response
     */
    public function getPaymentChangeLogs($page, $startDate = null, $endDate = null)
    {
        try {
            $rowsPerPage = 12;

            $startDate = $startDate . " 00:00:00";
            $endDate = $endDate . " 23:59:59";

            $logsTotal = DB::connection('pgsql')->select(
                "SELECT COUNT(*) AS total,
            SUM(CAST(model_data::json->'payment_original'->>'total' AS FLOAT)) AS total_value 
            FROM actions_logs 
            WHERE model = 'PAYMENT'
            AND action = 'CAMBIAR'
            AND creation_date BETWEEN '" . $startDate . "' AND '" . $endDate . "';"
            );

            $offset = $page * $rowsPerPage - $rowsPerPage;

            $logs = DB::connection('pgsql')->select(
                "SELECT model_data::json->'payment_changed'->'order'->>'identifier' AS ticket,
            id_action_log AS id,
            creation_date AS created_at,
            model_data::json->'payment_changed'->'order'->'store'->>'name' AS brand,
            CAST(model_data::json->'payment_original'->>'type' AS int) AS original_type,
            CAST(model_data::json->'payment_original'->>'card_id' AS int) AS original_card,
            CAST(model_data::json->'payment_changed'->>'type' AS int) AS changed_type,
            CAST(model_data::json->'payment_changed'->>'card_id' AS int) AS changed_card,
            CAST(model_data::json->'payment_changed'->>'total' AS float) AS total,
            null as details,
            CAST(model_data::json->'payment_changed'->'order'->>'id' AS bigint) AS order_id,
            user_id,
            null AS user            
            FROM
            actions_logs
            WHERE model = 'PAYMENT'
            AND action = 'CAMBIAR' 
            AND creation_date BETWEEN '" . $startDate . "' AND '" . $endDate . "'
            ORDER BY creation_date
            LIMIT 12 OFFSET " . $offset
            );

            $array_logs = array();
            foreach ($logs as &$log) {
                $orderDetail = OrderDetail::where('order_id', $log->order_id)
                    ->select('product_detail_id AS id', DB::raw('count(*) as amount'), 'name_product', 'total AS unit_price')
                    ->groupBy('product_detail_id', 'name_product', 'total')
                    ->get();
                $log->details = $orderDetail;

                $log->original_type = Payment::gettypeNameByCode($log->original_type);
                $log->changed_type = Payment::gettypeNameByCode($log->changed_type);

                $card_original = Card::find($log->original_card);
                if (!is_null($card_original)) {
                    $log->original_card = $card_original->name;
                }

                $card_changed = Card::find($log->changed_card);
                if (!is_null($card_changed)) {
                    $log->changed_card = $card_changed->name;
                }

                $user = Employee::find($log->user_id);
                if (!is_null($user)) {
                    $log->user = $user->name;
                }

                array_push($array_logs, $log);
            }

            return response()->json([
                'status' => 'Success',
                'results' => [
                    'total' => $logsTotal[0]->total,
                    'totalValue' => floatval($logsTotal[0]->total_value),
                    'logs' => $array_logs,
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'Error',
                'results' => 'null'
            ], 500);
        }
    }

    /**
     * Display a listing of logs.
     *
     * @return \Illuminate\Http\Response
     */
    public function getOrderRevokeLogs($page, $startDate = null, $endDate = null)
    {
        try {
            $rowsPerPage = 12;

            $startDate = $startDate . " 00:00:00";
            $endDate = $endDate . " 23:59:59";

            $logsTotal = DB::connection('pgsql')->select(
                "SELECT COUNT(*) AS total,
                SUM(CAST(model_data::json->'order'->>'total' AS FLOAT)) AS total_value
                FROM actions_logs 
                WHERE model = 'ORDER'
                AND action = 'ANULAR'
                AND creation_date BETWEEN '" . $startDate . "' AND '" . $endDate . "';"
            );

            $offset = $page * $rowsPerPage - $rowsPerPage;

            $logs = DB::connection('pgsql')->select(
                "SELECT al.id_action_log AS id,
            al.creation_date AS created_at,
            al.model_data::json->'order'->>'identifier' AS ticket,
            al.model_data::json->'order'->'store'->>'name' AS brand,
            al.model_data::json->'order'->>'observations' AS motive,
            CAST(al.model_data::json->'order'->>'spot_id' AS bigint) AS spot,
            al.model_data::json->'order'->'order_details' AS details,
            CAST(al.model_data::json->'order'->>'total' AS float) AS total,
            al.user_id,
            null AS user,
            (SELECT array_to_json(ARRAY_AGG(row_to_json(t))) 
             FROM (SELECT d->>'product_detail_id' AS id, 
                   d->>'name_product' AS name_product, 
                   COUNT(*) AS amount, 
                   CAST(d->>'total' AS float) AS unit_price 
                   FROM json_array_elements(al.model_data::json->'order'->'order_details') AS d 
                   GROUP BY d->>'product_detail_id', d->>'name_product', d->>'total') t)  AS details
            FROM actions_logs AS al
            WHERE al.action = 'ANULAR' 
            AND al.model = 'ORDER'
            AND al.creation_date BETWEEN '" . $startDate . "' AND '" . $endDate . "'
            ORDER BY al.creation_date
            LIMIT 12 OFFSET " . $offset
            );

            $array_logs = array();
            foreach ($logs as &$log) {
                $spot = Spot::find($log->spot);
                if (!is_null($spot)) {
                    $log->spot = $spot->name;
                }

                $user = Employee::find($log->user_id);
                if (!is_null($user)) {
                    $log->user = $user->name;
                }

                $log->details = json_decode($log->details);
                array_push($array_logs, $log);
            }



            return response()->json([
                'status' => 'Success',
                'results' => [
                    'total' => $logsTotal[0]->total,
                    'totalValue' => floatval($logsTotal[0]->total_value),
                    'logs' => $logs,
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'Error',
                'results' => 'null'
            ], 500);
        }
    }

    /**
     * Display a listing of logs.
     *
     * @return \Illuminate\Http\Response
     */
    public function getReprintOrderLogs($page, $startDate = null, $endDate = null)
    {
        try {
            $rowsPerPage = 12;

            $startDate = $startDate . " 00:00:00";
            $endDate = $endDate . " 23:59:59";

            $logsTotal = DB::connection('pgsql')->select(
                "SELECT ROW_NUMBER() OVER () AS total,
            SUM(COUNT(al.id_action_log)) OVER() AS total_value
            FROM actions_logs AS al 
            WHERE action = 'REPRINT'
            AND model = 'ORDER'            
            AND creation_date BETWEEN '" . $startDate . "' AND '" . $endDate . "'
            GROUP BY al.model_id, 
            al.model_data::json->'order'->>'identifier',
            al.model_data::json->'order'->'spot'->>'name',
            al.model_data::json->'order'->'store'->>'name',
            al.model_data::json->'order'->>'total'
            ORDER BY total DESC;"
            );

            $offset = $page * $rowsPerPage - $rowsPerPage;

            $logs = DB::connection('pgsql')->select(
                "SELECT al.model_id AS id,
                COUNT(al.id_action_log) AS times,
                al.model_data::json->'order'->>'identifier' AS ticket,
                al.model_data::json->'order'->'spot'->>'name' AS spot,
                al.model_data::json->'order'->'store'->>'name' AS brand,
                al.model_data::json->'order'->>'total' AS total,
                array_to_json(
                    ARRAY_AGG(
                        json_build_object(
                            'name', al.model_data::json->'user'->>'name', 										  
                            'created_at', al.creation_date
                        )
                    )
                ) AS users
                FROM actions_logs AS al 
                WHERE action = 'REPRINT'
                AND model = 'ORDER'
                /* AND model_id = 504863  */
                AND creation_date BETWEEN '" . $startDate . "' AND '" . $endDate . "'
                GROUP BY al.model_id, 
                al.model_data::json->'order'->>'identifier',
                al.model_data::json->'order'->'spot'->>'name',
                al.model_data::json->'order'->'store'->>'name',
                al.model_data::json->'order'->>'total'            
                LIMIT 12 OFFSET " . $offset
            );

            $array_logs = array();
            foreach ($logs as &$log) {
                $log->users = json_decode($log->users);
                array_push($array_logs, $log);
            }

            return response()->json([
                'status' => 'Success',
                'results' => [
                    'total' => $logsTotal[0]->total,
                    'totalValue' => $logsTotal[0]->total_value,
                    'logs' => $array_logs,
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'Error',
                'results' => 'null'
            ], 500);
        }
    }

    /**
     * Display a listing of logs.
     *
     * @return \Illuminate\Http\Response
     */
    public function getProductsOrderLogs($page, $startDate = null, $endDate = null)
    {
        try {
            $rowsPerPage = 12;

            $startDate = $startDate . " 00:00:00";
            $endDate = $endDate . " 23:59:59";

            $offset = $page * $rowsPerPage - $rowsPerPage;

            $logsTotal = DB::connection('pgsql')->select(
                "SELECT COUNT(*) AS total,
            SUM(foo.total) AS total_value
            FROM (SELECT DISTINCT ON (model_id) model_id,
            CAST(model_data::json->'order_new'->>'total' AS FLOAT) AS total
            FROM actions_logs
            WHERE model = 'ORDER' 
            AND (action = 'ELIMINAR PRODUCTO' OR action = 'ACTUALIZAR PRODUCTO')
            AND creation_date BETWEEN '" . $startDate . "' AND '" . $endDate . "'
            ORDER BY model_id DESC, creation_date DESC) AS foo;"
            );

            $logs = DB::connection('pgsql')->select(
                "SELECT DISTINCT ON (al.model_id) al.model_id AS id, 
            al.creation_date AS created_at, 
            al.user_id, 
            null AS user,
            al.model_data::json->'order_new'->>'identifier' AS ticket,
            CAST(al.model_data::json->'order_new'->>'spot_id' AS bigint) AS spot,
            al.model_data::json->'order_new'->'store'->>'name' AS brand,
            CAST(model_data::json->'order_new'->>'total' AS FLOAT) AS total,
            al.model_data::json->'order_new'->'order_details' AS details
            FROM actions_logs AS al
            WHERE model = 'ORDER' 
            AND (action = 'ELIMINAR PRODUCTO' OR action = 'ACTUALIZAR PRODUCTO')
            AND al.creation_date BETWEEN '" . $startDate . "' AND '" . $endDate . "'
            ORDER BY al.model_id DESC, al.creation_date DESC
            LIMIT 12 OFFSET " . $offset
            );

            $array_logs = array();
            foreach ($logs as &$log) {
                $spot = Spot::find($log->spot);
                if (!is_null($spot)) {
                    $log->spot = $spot->name;
                }

                $user = Employee::find($log->user_id);
                if (!is_null($user)) {
                    $log->user = $user->name;
                }

                $log->details = json_decode($log->details);
                array_push($array_logs, $log);
            }

            return response()->json([
                'status' => 'Success',
                'results' => [
                    'total' => $logsTotal[0]->total,
                    'totalValue' => $logsTotal[0]->total_value,
                    'logs' => $array_logs,
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'Error',
                'results' => 'null'
            ], 500);
        }
    }

    /**
     * Display a listing of products that were updated or deleted in a specific order.
     *
     * @return \Illuminate\Http\Response
     */
    public function getChangesProductOrderLogs($modelId)
    {
        try {
            $updated = DB::connection('pgsql')->select(
                "SELECT model_id, 
            array_to_json(ARRAY_AGG(json_build_object(
            'products', model_data::json->'order_details_old',
            'created_at', creation_date))) AS order_detail
            FROM actions_logs
            WHERE model_id = " . $modelId . "
            AND model = 'ORDER' AND action = 'ACTUALIZAR PRODUCTO'
            GROUP BY model_id;"
            );

            $deleted = DB::connection('pgsql')->select(
                "SELECT model_id, 
            array_to_json(ARRAY_AGG(json_build_object(
            'products', model_data::json->'order_details_old',
            'created_at', creation_date))) AS order_detail
            FROM actions_logs
            WHERE model_id = " . $modelId . "
            AND model = 'ORDER' AND action = 'ELIMINAR PRODUCTO'
            GROUP BY model_id;"
            );

            if (sizeof($updated) > 0) {
                $updated[0]->order_detail = json_decode($updated[0]->order_detail);
            }

            if (sizeof($deleted) > 0) {
                $deleted[0]->order_detail = json_decode($deleted[0]->order_detail);
            }

            return response()->json([
                'status' => 'Success',
                'results' => [
                    'updated' => $updated,
                    'deleted' => $deleted,
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'Error',
                'results' => 'null'
            ], 500);
        }
    }

    /**
     * Display a track of timeline of a specific product selected.
     *
     * @return \Illuminate\Http\Response
     */
    public function getTrackProductLogs($modelId, $productDetailId, $instruction = "")
    {
        try {
            $track = DB::connection('pgsql')->select(
                "SELECT DISTINCT ON (al.action) al.action, 
                al.creation_date AS created_at
                FROM actions_logs as al
                WHERE al.model_id = " . $modelId . "
                AND model = 'ORDER'
                AND (action = 'AGREGAR' OR action = 'COCINAR')
                AND CAST(al.model_data::json->'product'->>'product_detail_id' AS bigint) = " . $productDetailId . "
                AND al.model_data::json->'product'->>'instruction' = '" . $instruction . "';"
            );

            return response()->json([
                'status' => 'Success',
                'results' => [
                    'track' => $track,
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'Error',
                'results' => 'null'
            ], 500);
        }
    }
}
