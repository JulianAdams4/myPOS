<?php

namespace Tests\Feature\V1\CashierBalanceController;

use App\CashierBalance;
use Carbon\Carbon;
use App\Employee;
use App\ProductCategory;
use App\Role;
use App\Order;
use App\User;
use DB;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CloseDayTest extends TestCase
{
    use DatabaseTransactions;

    protected $user;
    protected $employee;

    public function setUp()
    {
        parent::setUp();
        $this->user = User::whereHas('role', function ($role) {
            $role->where('name', Role::EMPLOYEE);
        })->inRandomOrder()->first();
        $this->employee = Employee::where('user_id', $this->user->id)->with('store.company')->first();
    }

    // Envía todos los datos correctamente
    public function testSuccessCloseDayWithCashierBalanceId()
    {
        $then = Carbon::now()->subHours(10);
        $dateOpen = $then->format('Y-m-d');
        $hourOpen = $then->format('H:i:s');
        $valueOpen = rand(0, 15000);
        $observation = 'Testing';

        $openData = [
            'date_open' => $dateOpen,
            'hour_open' => $hourOpen,
            'value_previous_close' => 0,
            'value_open' => $valueOpen,
            'observation' => $observation,
        ];

        $this->be($this->user);
        $this->json('POST', 'api/v1/cashier/balance/open/day', $openData)
        ->assertJson([
            'msg' => 'Información guardada con éxito',
            'results' => [
                'value_open' => $openData['value_open'],
                'observation' => $openData['observation']
            ],
        ])->assertStatus(200);
        $store = $this->employee->store;
        $cashierBalance = CashierBalance::where('store_id', $store->id)->first();

        $now = Carbon::now();
        $dateClose = $now->format('Y-m-d');
        $hourClose = $now->format('H:i:s');
        $valueClose = rand(25000, 50000);
        $expenses = [
            [
                'name' => str_random(10),
                'value' => rand(100, 500),
            ],
            [
                'name' => str_random(10),
                'value' => rand(100, 500),
            ],
            [
                'name' => str_random(10),
                'value' => rand(100, 500),
            ],
        ];
        $closeData = [
            'cashier_balance_id' => $cashierBalance->id,
            'date_close' => $dateClose,
            'hour_close' => $hourClose,
            'value_close' => $valueClose,
            'expenses' => $expenses,
            'print_balance' => true,
        ];
        $this->json('POST', 'api/v1/cashier/balance/close/day', $closeData)
        ->assertJson([
            'msg' => 'Información guardada con éxito',
            'results' => null
        ])->assertStatus(200);
        $checkOpenData = [
            'value_open' => $openData['value_open'],
            'observation' => $openData['observation']
        ];
        $this->assertDatabaseHas('cashier_balances', $checkOpenData);
        $closedData = array_merge($checkOpenData, [
            'value_close' => $valueOpen,
            'uber_discount' => 0
        ]);
        $this->assertDatabaseHas('cashier_balances', $closedData);
        $cashierBalance = CashierBalance::latest()->first();
        foreach ($expenses as $expense) {
            $this->assertDatabaseHas('expenses_balances', [
                'cashier_balance_id' => $cashierBalance->id,
                'name' => $expense['name'],
                'value' => $expense['value'],
            ]);
        }
    }

    // Envía todos los datos correctamente
    public function testSuccessCloseDayWithoutCashierBalanceId()
    {
        $then = Carbon::now()->subHours(10);
        $dateOpen = $then->format('Y-m-d');
        $hourOpen = $then->format('H:i:s');
        $valueOpen = rand(0, 15000);
        $observation = 'Testing';

        $openData = [
            'date_open' => $dateOpen,
            'hour_open' => $hourOpen,
            'value_previous_close' => 0,
            'value_open' => $valueOpen,
            'observation' => $observation,
        ];

        $this->be($this->user);
        $this->json('POST', 'api/v1/cashier/balance/open/day', $openData)
        ->assertJson([
            'msg' => 'Información guardada con éxito',
            'results' => [
                'value_open' => $openData['value_open'],
                'observation' => $openData['observation']
            ],
        ])->assertStatus(200);

        $now = Carbon::now();
        $dateClose = $now->format('Y-m-d');
        $hourClose = $now->format('H:i:s');
        $valueClose = rand(25000, 50000);
        $expenses = [
            [
                'name' => str_random(10),
                'value' => rand(100, 500),
            ],
            [
                'name' => str_random(10),
                'value' => rand(100, 500),
            ],
            [
                'name' => str_random(10),
                'value' => rand(100, 500),
            ],
        ];
        $closeData = [
            'date_close' => $dateClose,
            'hour_close' => $hourClose,
            'value_close' => $valueClose,
            'expenses' => $expenses,
            'print_balance' => true,
        ];
        $this->json('POST', 'api/v1/cashier/balance/close/day', $closeData)
        ->assertJson([
            'msg' => 'Información guardada con éxito',
            'results' => null
        ])->assertStatus(200);
        $checkOpenData = [
            'value_open' => $openData['value_open'],
            'observation' => $openData['observation']
        ];
        $this->assertDatabaseHas('cashier_balances', $checkOpenData);
        $closedData = array_merge($checkOpenData, [
            'value_close' => $valueOpen,
            'uber_discount' => 0
        ]);
        $this->assertDatabaseHas('cashier_balances', $closedData);
        $cashierBalance = CashierBalance::latest()->first();
        foreach ($expenses as $expense) {
            $this->assertDatabaseHas('expenses_balances', [
                'cashier_balance_id' => $cashierBalance->id,
                'name' => $expense['name'],
                'value' => $expense['value'],
            ]);
        }
    }

    // Envía todos los datos correctamente
    public function testSuccessWithUberDiscount()
    {
        $then = Carbon::now()->subHours(10);
        $dateOpen = $then->format('Y-m-d');
        $hourOpen = $then->format('H:i:s');
        $valueOpen = rand(0, 15000);
        $observation = 'Testing';

        $openData = [
            'date_open' => $dateOpen,
            'hour_open' => $hourOpen,
            'value_previous_close' => 0,
            'value_open' => $valueOpen,
            'observation' => $observation,
        ];

        $this->be($this->user);
        $this->json('POST', 'api/v1/cashier/balance/open/day', $openData)
        ->assertJson([
            'msg' => 'Información guardada con éxito',
            'results' => [
                'value_open' => $openData['value_open'],
                'observation' => $openData['observation']
            ],
        ])->assertStatus(200);
        $store = $this->employee->store;
        $cashierBalance = CashierBalance::where('store_id', $store->id)->first();

        $now = Carbon::now();
        $dateClose = $now->format('Y-m-d');
        $hourClose = $now->format('H:i:s');
        $valueClose = rand(25000, 50000);
        $expenses = [
            [
                'name' => str_random(10),
                'value' => rand(100, 500),
            ],
            [
                'name' => str_random(10),
                'value' => rand(100, 500),
            ],
            [
                'name' => str_random(10),
                'value' => rand(100, 500),
            ],
        ];
        $closeData = [
            'cashier_balance_id' => $cashierBalance->id,
            'date_close' => $dateClose,
            'hour_close' => $hourClose,
            'value_close' => $valueClose,
            'expenses' => $expenses,
            'print_balance' => true,
            'totalUberDiscount' => 5,
        ];
        $this->json('POST', 'api/v1/cashier/balance/close/day', $closeData)
        ->assertJson([
            'msg' => 'Información guardada con éxito',
            'results' => null
        ])->assertStatus(200);
        $checkOpenData = [
            'value_open' => $openData['value_open'],
            'observation' => $openData['observation']
        ];
        $this->assertDatabaseHas('cashier_balances', $checkOpenData);
        $closedData = array_merge($checkOpenData, [
            'value_close' => $valueOpen,
            'uber_discount' => 500
        ]);
        $this->assertDatabaseHas('cashier_balances', $closedData);
        $cashierBalance = CashierBalance::latest()->first();
        foreach ($expenses as $expense) {
            $this->assertDatabaseHas('expenses_balances', [
                'cashier_balance_id' => $cashierBalance->id,
                'name' => $expense['name'],
                'value' => $expense['value'],
            ]);
        }
    }

    // No se puede cerrar caja ya que existen órdenes no finalizadas (preórdenes).
    // public function testUnfinishedOrders()
    // {
    //     $now = Carbon::now();
    //     $dateOpen = $now->format('Y-m-d');
    //     $hourOpen = $now->format('H:i:s');
    //     $valuePreviousClose = rand(25000, 50000);
    //     $valueOpen = rand(0, 15000);
    //     $observation = 'Testing';
    //     $data = [
    //         'date_open' => $dateOpen,
    //         'hour_open' => $hourOpen,
    //         'value_previous_close' => $valuePreviousClose,
    //         'value_open' => $valueOpen,
    //         'observation' => $observation,
    //     ];
    //     $this->be($this->user);
    //     $this->json('POST', 'api/v1/cashier/balance/open/day', $data)
    //     ->assertJson([
    //         'msg' => 'Información guardada con éxito',
    //         'results' => [
    //             'value_previous_close' => 0,
    //             'value_open' => $valueOpen,
    //             'observation' => $observation,
    //         ],
    //     ])->assertStatus(200);
    //     $store = $this->employee->store;
    //     $spot = $store->spots()->inRandomOrder()->first();
    //     $cashierBalance = CashierBalance::where('store_id', $store->id)->first();

    //     $companyId = $store->company_id;
    //     $people = rand(1, 5);
    //     $preorderData = [
    //         'id_spot' => $spot->id,
    //         'instruction' => 'Testing',
    //         'people' => $people,
    //         'specifications' => []
    //     ];
    //     $category = ProductCategory::where('company_id', $companyId)
    //     ->with(['products' => function ($products) {
    //         $products->with(['specifications' => function ($specs) {
    //             $specs->with('specificationCategory')->inRandomOrder()->take(rand(1, 3));
    //         }])->inRandomOrder()->first();
    //     }])->inRandomOrder()->first();
    //     $product = $category->products->first();
    //     $productQuantity = rand(1, 3);
    //     $productValue = $product->base_value * $productQuantity;
    //     $preorderData['id_product'] = $product->id;
    //     $preorderData['name'] = $product->name;
    //     $preorderData['invoice_name'] = $product->invoice_name;
    //     $preorderData['quantity'] = $productQuantity;
    //     $specs = $product->specifications;
    //     $categoryData = [
    //         'id' => $category->id,
    //         'name' => $category->name,
    //         'options' => []
    //     ];
    //     $specValue = 0;
    //     foreach ($specs as $spec) {
    //         $specCategory = $spec->specificationCategory;
    //         $specQuantity = $specCategory->isSizeType() ? 1 : rand(1, $specCategory->max);
    //         $specChecked = $specQuantity > 0 ? 1 : 0;
    //         $sumValue = $specChecked ? $spec->value * $specQuantity : 0;
    //         array_push($categoryData['options'], [
    //             'id' => $spec->id,
    //             'name' => $spec->name,
    //             'value' => $spec->value,
    //             'quantity' => $specQuantity,
    //             'checked' => $specChecked
    //         ]);
    //         array_push($preorderData['specifications'], $categoryData);
    //         $specValue += $sumValue;
    //     }
    //     $value = $productValue + $specValue;
    //     $preorderData['value'] = $value;
    //     $this->be($this->user);
    //     $this->json('POST', 'api/v2/preorder', $preorderData)
    //     ->assertJson([
    //         'status' => 'Orden creada con éxito',
    //     ])->assertStatus(200);
    //     $order = Order::with('orderDetails.productDetail')->orderBy('id', 'desc')->first();
    //     // Verifico que los datos de la orden coincidan con los datos enviados.
    //     $this->assertDatabaseHas('orders', [
    //         'id' => $order->id,
    //         'store_id' => $store->id,
    //         'spot_id' => $spot->id,
    //         'order_value' => $value,
    //         'status' => 1,
    //         'employee_id' => $this->employee->id,
    //         'cash' => 1,
    //         'identifier' => 0,
    //         'people' => $people,
    //         'preorder' => 1,
    //         'cashier_balance_id' => $cashierBalance->id,
    //     ]);

    //     $now = Carbon::now();
    //     $dateClose = $now->format('Y-m-d');
    //     $hourClose = $now->format('H:i:s');
    //     $valueClose = rand(25000, 50000);
    //     $expenses = [
    //         [
    //             'name' => str_random(10),
    //             'value' => rand(100, 500),
    //         ],
    //         [
    //             'name' => str_random(10),
    //             'value' => rand(100, 500),
    //         ],
    //         [
    //             'name' => str_random(10),
    //             'value' => rand(100, 500),
    //         ],
    //     ];
    //     $closeData = [
    //         'date_close' => $dateClose,
    //         'hour_close' => $hourClose,
    //         'value_close' => $valueClose,
    //         'expenses' => $expenses,
    //         'print_balance' => true,
    //     ];
    //     $this->json('POST', 'api/v1/cashier/balance/close/day', $closeData)
    //     ->assertJson([
    //         'msg' => 'Existen mesas con órdenes no finalizadas',
    //         'results' => null
    //     ])->assertStatus(409);
    // }

    // No encuentra caja abierta
    public function testOpenCashierBalanceNotFound()
    {
        $now = Carbon::now();
        $dateClose = $now->format('Y-m-d');
        $hourClose = $now->format('H:i:s');
        $valueClose = rand(25000, 50000);
        $expenses = [
            [
                'name' => str_random(10),
                'value' => rand(100, 500),
            ],
            [
                'name' => str_random(10),
                'value' => rand(100, 500),
            ],
            [
                'name' => str_random(10),
                'value' => rand(100, 500),
            ],
        ];
        $closeData = [
            'date_close' => $dateClose,
            'hour_close' => $hourClose,
            'value_close' => $valueClose,
            'expenses' => $expenses,
        ];
        $this->be($this->user);
        $this->json('POST', 'api/v1/cashier/balance/close/day', $closeData)
        ->assertJson([
            'msg' => 'No existe apertura de caja',
            'results' => null
        ])->assertStatus(400);
        foreach ($expenses as $expense) {
            $this->assertDatabaseMissing('expenses_balances', [
                'name' => $expense['name'],
                'value' => $expense['value'],
            ]);
        }
    }

    // Gastos deben tener nombre
    public function testExpensesShouldHaveName()
    {
        $then = Carbon::now()->subHours(10);
        $dateOpen = $then->format('Y-m-d');
        $hourOpen = $then->format('H:i:s');
        $valuePreviousClose = rand(25000, 50000);
        $valueOpen = rand(0, 15000);
        $observation = 'Testing';

        $openData = [
            'date_open' => $dateOpen,
            'hour_open' => $hourOpen,
            'value_previous_close' => $valuePreviousClose,
            'value_open' => $valueOpen,
            'observation' => $observation,
        ];

        $this->be($this->user);
        $this->json('POST', 'api/v1/cashier/balance/open/day', $openData)
        ->assertJson([
            'msg' => 'Información guardada con éxito',
            'results' => [
                'value_open' => $openData['value_open'],
                'observation' => $openData['observation']
            ],
        ])->assertStatus(200);

        $now = Carbon::now();
        $dateClose = $now->format('Y-m-d');
        $hourClose = $now->format('H:i:s');
        $valueClose = rand(25000, 50000);
        $expenses = [
            [
                'name' => '',
                'value' => rand(100, 500),
            ],
            [
                'name' => null,
                'value' => rand(100, 500),
            ],
            [
                'name' => str_random(10),
                'value' => rand(100, 500),
            ],
        ];
        $closeData = [
            'date_close' => $dateClose,
            'hour_close' => $hourClose,
            'value_close' => $valueClose,
            'expenses' => $expenses,
        ];
        $this->be($this->user);
        $this->json('POST', 'api/v1/cashier/balance/close/day', $closeData)
        ->assertJson([
            'msg' => 'Los gastos deben tener un motivo',
            'results' => null
        ])->assertStatus(409);
        $checkOpenData = [
            'value_open' => $openData['value_open'],
            'observation' => $openData['observation']
        ];
        $this->assertDatabaseHas('cashier_balances', $checkOpenData);
        $closedData = array_merge($checkOpenData, [
            'value_close' => $valueOpen
        ]);
        $this->assertDatabaseMissing('cashier_balances', $closedData);
        foreach ($expenses as $expense) {
            $this->assertDatabaseMissing('expenses_balances', [
                'name' => $expense['name'],
                'value' => $expense['value'],
            ]);
        }
    }

    // No tiene los permisos necesarios (module).
    public function testModuleNoPermission()
    {
        $then = Carbon::now()->subHours(10);
        $dateOpen = $then->format('Y-m-d');
        $hourOpen = $then->format('H:i:s');
        $valuePreviousClose = rand(25000, 50000);
        $valueOpen = rand(0, 15000);
        $observation = 'Testing';
        $openData = [
            'date_open' => $dateOpen,
            'hour_open' => $hourOpen,
            'value_previous_close' => $valuePreviousClose,
            'value_open' => $valueOpen,
            'observation' => $observation,
        ];
        $employee = Employee::where('user_id', $this->user->id)->first();
        DB::table('cashier_balances')->insert([
            'employee_id_open' => $employee->id,
            'store_id' => $employee->store_id,
            'date_open' => $openData['date_open'],
            'hour_open' => $openData['hour_open'],
            'value_previous_close' => $openData['value_previous_close'],
            'value_open' => $openData['value_open'],
            'observation' => $openData['observation']
        ]);
        $this->user->modules()->where('identifier', 'cashier-balance')->delete();
        $now = Carbon::now();
        $dateClose = $now->format('Y-m-d');
        $hourClose = $now->format('H:i:s');
        $valueClose = rand(25000, 50000);
        $expenses = [
            [
                'name' => str_random(10),
                'value' => rand(100, 500),
            ],
            [
                'name' => str_random(10),
                'value' => rand(100, 500),
            ],
            [
                'name' => str_random(10),
                'value' => rand(100, 500),
            ],
        ];
        $closeData = [
            'date_close' => $dateClose,
            'hour_close' => $hourClose,
            'value_close' => $valueClose,
            'expenses' => $expenses,
            'print_balance' => true,
        ];
        $this->be($this->user);
        $this->json('POST', 'api/v1/cashier/balance/close/day', $closeData)
        ->assertStatus(403);
    }

    // No tiene los permisos necesarios (action).
    public function testActionNoPermission()
    {
        $then = Carbon::now()->subHours(10);
        $dateOpen = $then->format('Y-m-d');
        $hourOpen = $then->format('H:i:s');
        $valuePreviousClose = rand(25000, 50000);
        $valueOpen = rand(0, 15000);
        $observation = 'Testing';
        $openData = [
            'date_open' => $dateOpen,
            'hour_open' => $hourOpen,
            'value_previous_close' => $valuePreviousClose,
            'value_open' => $valueOpen,
            'observation' => $observation,
        ];
        $employee = Employee::where('user_id', $this->user->id)->first();
        DB::table('cashier_balances')->insert([
            'employee_id_open' => $employee->id,
            'store_id' => $employee->store_id,
            'date_open' => $openData['date_open'],
            'hour_open' => $openData['hour_open'],
            'value_previous_close' => $openData['value_previous_close'],
            'value_open' => $openData['value_open'],
            'observation' => $openData['observation']
        ]);
        $this->user->actions()->where('identifier', 'close-cashier-balance')->delete();
        $now = Carbon::now();
        $dateClose = $now->format('Y-m-d');
        $hourClose = $now->format('H:i:s');
        $valueClose = rand(25000, 50000);
        $expenses = [
            [
                'name' => str_random(10),
                'value' => rand(100, 500),
            ],
            [
                'name' => str_random(10),
                'value' => rand(100, 500),
            ],
            [
                'name' => str_random(10),
                'value' => rand(100, 500),
            ],
        ];
        $closeData = [
            'date_close' => $dateClose,
            'hour_close' => $hourClose,
            'value_close' => $valueClose,
            'expenses' => $expenses,
            'print_balance' => true,
        ];
        $this->be($this->user);
        $this->json('POST', 'api/v1/cashier/balance/close/day', $closeData)
        ->assertStatus(403);
    }
}
