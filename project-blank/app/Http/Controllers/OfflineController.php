<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Company;
use App\Store;
use Carbon\Carbon;
use App\Section;
use App\SpecificationCategory;
use App\Product;
use App\ProductCategory;
use App\CashierBalance;
use App\Order;
use App\Employee;
use App\OrderDetail;
use App\ProductSpecification;
use App\Specification;
use Illuminate\Support\Facades\DB;
use App\Helper;
use App\Traits\AuthTrait;
use Ramsey\Uuid\Uuid;
use App\Traits\CashierBalanceHelper;
use App\Checkin;
use App\StoreConfigurations;

class OfflineController extends Controller
{
    use AuthTrait, CashierBalanceHelper;
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
    public function getCompany()
    {
        $store = $this->authStore;
        // return response()->json($store);
        $companies = Company::where('id', $store->company->id)
            ->with('billingInformation')
            ->get()
            ->first();
        $companies->store = Store::where('company_id', $companies->id)
            ->where('id', $store->id)
            ->with(['sections' => function ($sections) {
                $sections->where("is_main", 1);
            }])
            ->with(['spots', 'cards', 'configs', 'locations'])
            ->get()
            ->first();

        $companies->store->cashier_balances = CashierBalance::where('store_id', $store->id)
            ->whereNull('date_close')
            ->first();


        //Configuration array to json
        $configurations = array();

        $storeConfigurations = StoreConfigurations::where('store_id', $store->id)
            ->get();

        foreach ($storeConfigurations as $config) {
            $configurations[$config->key] = json_decode($config->value);
        }

        $configurations['store_id'] = $store->id;

        $companies->store->configurations = $configurations;

        $index = 0;
        foreach ($companies->store->spots as $spot) {
            $bussy = false;
            if ($companies->store->cashier_balances) {
                $activeOrdersCount = Order::where('store_id', $store->id)
                    ->where('cashier_balance_id', $companies->store->cashier_balances->id)
                    ->where('spot_id', $spot['id'])
                    ->where('preorder', 1)
                    ->where('status', 1)
                    ->count();
                $bussy = $activeOrdersCount > 0;
            }
            $spot['index'] = $index;
            $spot['bussy'] = $bussy;
            $index++;
        }

        if ($companies->store->country_code == "CO") {
            $companies->store->nextbilling = Helper::getNextBillingOfficialNumber($companies->store->id);
        } else {
            $companies->store->nextbilling = $companies->store->nextInvoiceBillingNumber();
        }

        //cashier balance
        $hasOpenCashierBalance = true;
        $valueOpen = "0";
        $valuesData = [
            'close' => "0",
            'card' => "0",
            'card_tips' => "0",
            'transfer' => "0",
            'rappi_pay' => "0",
            'others' => "0",
            'external_values' => [],
            'revoked_orders' => 0
        ];
        if (!$companies->store->cashier_balances) {
            $hasOpenCashierBalance = false;
        } else {
            $valueOpen = (string) $companies->store->cashier_balances->value_open;
            $valuesData = $this->getValuesCashierBalance($companies->store->cashier_balances->id);
        }
        $companies->store->open_cashier_balance = [
            'id' =>  Uuid::uuid5(Uuid::NAMESPACE_DNS, $store->id . "-open"),
            'msg' => 'Success',
            'results' => $hasOpenCashierBalance,
            'cashier_balance_id' => $companies->store->cashier_balances ? $companies->store->cashier_balances->id : null,
            'value' => $valueOpen,
            'close' => $valuesData['close'],
            'card' => $valuesData['card'],
            'transfer' => $valuesData['transfer'],
            'rappi_pay' => $valuesData['rappi_pay'],
            'others' => $valuesData['others'],
            'card_tips' => $valuesData['card_tips'],
            'external_values' => $valuesData['external_values'],
            'revoked_orders' => $valuesData['revoked_orders']
        ];
        // fin de cashier balance open
        // cashier balance

        // fin del cashier balance

        if ($companies->store->cashier_balances) {
            $companies->store->cashier_balances->preorders =  Order::where('cashier_balance_id', $companies->store->cashier_balances->id)
                ->where('store_id', $store->id)
                ->where('preorder', 1)
                ->with('taxDetails.storeTax')->get()
                ->map(function ($preorder) {
                    $preorder->order_details = OrderDetail::where('order_id', $preorder->id)
                        ->where('status', 1)
                        ->with(
                            [
                                'productDetail.product',
                                'orderSpecifications.specification.specificationCategory',
                                'processStatus' => function ($process) {
                                    $process->orderBy('created_at', 'DESC');
                                }
                            ]
                        )
                        ->get();
                    return $preorder;
                });
            //hasOpenCashierBalance
        } else {
            $dt = Carbon::now();
            $today = $dt->toDateString();
            $companies->store->cashier_balances = [
                'id' => Uuid::uuid5(Uuid::NAMESPACE_DNS, $store->id . "-cb"),
                "date_open" => str_replace('-', '/', $today),
                "hour_open" => Carbon::createFromFormat('Y-m-d H:i:s', $dt)->format('H:i'),
                "value_previous_close" => $this->getPreviousValueClosed($store),
                "value_open" => null,
                "observation" => "",
            ];
        }

        $companies->store->employee = Employee::where('store_id', $companies->store->id)
            ->with('user')
            ->where('type_employee', 3)->get()
            ->map(function ($employee) {
                $employee->checkin = Checkin::where('employee_id', $employee->id)
                    ->orderBy('created_at', 'DESC')->limit(1)->get();
                return $employee;
            });

        // $employee = Employee::where('store_id', $store_id)
        //     ->whereHas('user', function ($user) use ($pinCode) {
        //         $user->where('pin_code', $pinCode);
        //     })
        //     ->first();

        foreach ($companies->store->sections as $section) {
            $section->product_categories = ProductCategory::where('company_id', $companies->id)
                ->where('status', 1)
                ->where('section_id', $section->id)
                ->orderBy('priority', 'ASC')
                ->get()
                ->map(function ($cat) use ($store) {
                    $cat->products =  Product::where('product_category_id', $cat->id)
                        ->where('status', 1)
                        ->with([
                            'product_details' => function ($detail) use ($store) {
                                $detail->where("status", 1)->where('store_id', $store->id);
                            },
                            'specifications' => function ($query) {
                                $query->wherePivot('status', 1)
                                    ->orderBy('specifications.priority')
                                    ->with('specificationCategory')
                                    ->join('specification_categories', function ($join) {
                                        $join->on(
                                            'specification_categories.id',
                                            '=',
                                            'specifications.specification_category_id'
                                        )
                                            ->where('specification_categories.status', 1)
                                            ->whereNull('specification_categories.deleted_at');
                                    })
                                    ->orderBy('specification_categories.priority', 'ASC');
                            },
                            'category'
                        ])
                        ->get();
                    return $cat;
                });
        }

        $companies->specification_categories = DB::table('specification_categories')->where('status', 1)
            ->where('company_id', $companies->id)
            ->get();
        // $companies->specification_categories = SpecificationCategory::where('company_id', $companies->id)
        //     ->where('status',1)
        //     ->where('id',1425)
        //     ->get();
        return response()->json($companies);
    }
}
