<?php

namespace App\Http\Controllers;

use App\OrderDetail;
use App\Order;
use App\Card;
use App\Company;
use App\Country;
use App\Payment;
use App\Spot;
use App\SubscriptionPlan;
use App\Traits\TimezoneHelper;
use App\Store;
use App\Traits\AuthTrait;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CompanyDashboardController extends Controller
{
    use AuthTrait;

    public $authUser;
    public $authStore;
    public $authEmployee;

    public function __construct()
    {
        // $this->middleware('store');
        $this->middleware('api');
        // [$this->authUser]= $this->getAuth();
        [$this->authUser, $this->authEmployee, $this->authStore] = $this->getAuth();
    }

    public function getCompany(Request $request)
    {
        $user = $this->authUser;
        $store = $this->authStore;
        $companyId = $store->company->id;
        $isAdminFranchise = $user->isAdminFranchise();

        $franchisesQuery = Company::whereHas('franchiseOf', function ($query) use ($companyId, $isAdminFranchise) {
            if (!$isAdminFranchise) return;
            $query->where('origin_company_id', $companyId);
        })
            ->orWhere('id', $companyId);

        $franchises = $franchisesQuery->get();
        $franchisesIds = $franchisesQuery->pluck('id');

        $stores = Store::whereHas('company.franchiseOf', function ($query) use ($companyId, $isAdminFranchise) {
            if (!$isAdminFranchise) return;
            $query->where('origin_company_id', $companyId);
        })
            ->orWhere('company_id', $companyId)
            ->with(['city.country'])
            ->get();

        $countries = Country::whereHas('cities.stores', function ($query) use ($franchisesIds) {
            $query->whereIn('company_id', $franchisesIds);
        })->get();

        $plans = SubscriptionPlan::whereHas('subscriptions.store', function ($query) use ($companyId) {
            $query->where('company_id', $companyId);
        })->get();

        return response()->json([
            "franchises" => $franchises,
            "stores" => $stores,
            "plans" => $plans,
            "countries" => $countries
        ]);
    }

    public function getTopProducts(Request $request)
    {
        $store = $this->authStore;
        $company = $store->company;
        $company_id = $company->id;
        $store_id = $request->store_id;
        $storeIds = $store_id !== null ?
            [$store_id] :
            Store::where('company_id', $company_id)->pluck('id')->toArray();
        $timezone = TimezoneHelper::getStoreTimezone($store);
        if (!$request->startDate) {
            $startDate = Carbon::now()->setTimezone($timezone)->startOfDay();
        } else {
            $startDate = TimezoneHelper::localizedDateForStore($request->startDate, $store);
        }

        if (!$request->endDate) {
            $endDate = Carbon::now()->setTimezone($timezone)->endOfDay();
        } else {
            $endDate = TimezoneHelper::localizedDateForStore($request->endDate, $store);
        }

        $products = DB::select(
            DB::raw("SELECT P.name, MAX(P.image) image, CAST(SUM(OD.quantity) AS UNSIGNED) quantity FROM order_details OD
                            INNER JOIN orders O ON O.id = OD.order_id
                            INNER JOIN stores S ON S.id = O.store_id
                            INNER JOIN product_details PD on PD.id = OD.product_detail_id
                            INNER JOIN products P ON P.ID = PD.product_id
                    WHERE O.store_id IN ('" . implode("','", $storeIds) . "')
                    AND O.created_at BETWEEN '$startDate' AND '$endDate'
                    AND O.status = 1 
                    GROUP BY P.name
                    ORDER BY quantity DESC;
                ")
        );

        return response()->json(
            [
                'status' => 'Exito',
                'results' => $products
            ],
            200
        );
    }

    public function getSalesByPayment(Request $request)
    {
        $store = $this->authStore;
        $company = $store->company;
        $company_id = $company->id;
        $storeIds = Store::where('company_id', $company_id)->pluck('id')->toArray();

        $timezone = TimezoneHelper::getStoreTimezone($store);
        if (!$request->startDate) {
            $startDate = Carbon::now()->setTimezone($timezone)->startOfDay();
        } else {
            $startDate = TimezoneHelper::localizedDateForStore($request->startDate, $store);
        }

        if (!$request->endDate) {
            $endDate = Carbon::now()->setTimezone($timezone)->endOfDay();
        } else {
            $endDate = TimezoneHelper::localizedDateForStore($request->endDate, $store);
        }

        $queryStores = DB::select(
            DB::raw("SELECT S.id, S.name, COUNT(O.id) orders, CAST(SUM(IFNULL(O.people, 0)) AS UNSIGNED) people
                FROM orders O
                        INNER JOIN stores S ON S.id = O.store_id
                WHERE O.store_id IN ('" . implode("','", $storeIds) . "')
                AND O.created_at BETWEEN '$startDate' AND '$endDate'
                AND O.status = 1
                GROUP BY S.id
                ORDER BY S.id DESC;
            ")
        );

        $stores = collect($queryStores);

        $stores = $stores->map(function ($store) use ($startDate, $endDate) {

            $queryPayments = DB::select(
                DB::raw("SELECT P.type, P.card_id, C.name, SPOT.origin,
                    CAST(ROUND(SUM(P.total)/100, 0) * 100 AS UNSIGNED) total FROM payments P
                    INNER JOIN orders O ON O.id = P.order_id
                    INNER JOIN spots SPOT ON SPOT.id = O.spot_id
                    INNER JOIN stores S ON S.id = O.store_id
                    LEFT JOIN cards C ON C.id = P.card_id
                    WHERE O.store_id = '$store->id'
                    AND O.created_at BETWEEN '$startDate' AND '$endDate'
                    AND O.status = 1
                    GROUP BY P.type, P.card_id, SPOT.origin
                    ORDER BY total DESC;
            ")
            );

            $payments = collect($queryPayments);

            $payments = $payments->map(function ($payment) {
                if (!$payment->name) {
                    $payment->name = Payment::getNameByType($payment->type);
                }

                if ($payment->origin > 1) {
                    $payment->name = Spot::getNameIntegrationByOrigin($payment->origin);
                }

                return $payment;
            });

            $store->payments = $payments;

            return $store;
        });

        return response()->json(
            [
                'status' => 'Exito',
                'data' => $stores
            ],
            200
        );
    }


    public function getActiveSpots(Request $request)
    {
        $store = $this->authStore;
        $company = $store->company;
        $company_id = $company->id;
        $store_id = $request->store_id;
        $storeIds = $store_id !== null ?
            [$store_id] :
            Store::where('company_id', $company_id)->pluck('id')->toArray();

        $active_spots = Order::whereIn('store_id', $storeIds)->where('preorder', 1)
            ->where('status', 1)
            ->get()
            ->groupBy(function ($order) {
                return $order->store_id;
            })
            ->map(function ($orders) {
                $spots = $orders->groupBy(function ($order) {
                    return $order->spot_id;
                });

                return [
                    'id' => $orders[0]->store->id,
                    'name' => $orders[0]->store->name,
                    'count' => sizeOf($spots)
                ];
            });
        return response()->json(
            [
                'status' => 'Exito',
                'data' => array_values($active_spots->toArray())
            ],
            200
        );
    }

    public function getFoodCountType(Request $request)
    {
        $store = $this->authStore;
        $company = $store->company;
        $company_id = $company->id;
        $storeIds = Store::where('company_id', $company_id)->pluck('id')->toArray();
        $timezone = TimezoneHelper::getStoreTimezone($store);
        if (!$request->startDate) {
            $startDate = Carbon::now()->startOfDay();
        } else {
            $startDate = TimezoneHelper::localizedDateForStore($request->startDate, $store)
                ->setTimezone($timezone)
                ->startOfDay();
        }

        if (!$request->endDate) {
            $endDate = Carbon::now()->endOfDay();
        } else {
            $endDate = TimezoneHelper::localizedDateForStore($request->endDate, $store)
                ->setTimezone($timezone)
                ->endOfDay();
        }

        $queryStores = DB::select(
            DB::raw("SELECT O.store_id, S.name, 
                    CASE
                        WHEN P.type_product = 'food' THEN 'food'
                        ELSE 'drink'
                        END type_food,
                    COUNT(OD.ID) quantity
                    FROM order_details OD
                    INNER JOIN orders O ON O.id = OD.order_id
                    INNER JOIN stores S ON S.id = O.store_id
                    INNER JOIN product_details PD on PD.id = OD.product_detail_id
                    INNER JOIN products P ON P.ID = PD.product_id
                    WHERE O.store_id IN ('" . implode("','", $storeIds) . "')
                    AND O.created_at BETWEEN '$startDate' AND '$endDate'
                    AND O.status = 1
                    GROUP BY O.store_id, type_food;
                ")
        );

        $stores = collect($queryStores);
        $stores = $stores->groupBy('store_id')
            ->map(function ($value) {

                $grouped = $value->mapWithKeys(function ($item) {
                    return [$item->type_food => $item->quantity];
                });

                return [
                    'id' => $value[0]->store_id,
                    'name' => $value[0]->name,
                    'type_count' => $grouped
                ];
            });

        return response()->json(
            [
                'status' => 'Exito',
                'data' => array_values($stores->toArray())
            ],
            200
        );
    }
}
