<?php

namespace Tests\Feature\V2\OrderController;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

use App\CashierBalance;
use App\ProductCategory;
use App\Employee;
use App\Role;
use App\Order;
use App\User;
use Carbon\Carbon;

class ChangeContentPreorderTest extends TestCase
{
    use DatabaseTransactions;

    protected $user;
    protected $employee;
    protected $store;
    protected $spot;

    public function setUp()
    {
        parent::setUp();
        $this->user = User::whereHas('role', function ($role) {
            $role->where('name', Role::EMPLOYEE);
        })->inRandomOrder()->first();
        $this->employee = Employee::where('user_id', $this->user->id)->with('store.spots')->first();
        $this->store = $this->employee->store;
        $this->spot = $this->store->spots->first();
    }

    // Aumentar la cantidad de un producto en la preorden.
    public function testIncreaseProductQuantity()
    {
        $now = Carbon::now();
        $dateOpen = $now->format('Y-m-d');
        $hourOpen = $now->format('H:i:s');
        $valuePreviousClose = rand(25000, 50000);
        $valueOpen = rand(0, 15000);
        $observation = 'Testing';
        $data = [
            'date_open' => $dateOpen,
            'hour_open' => $hourOpen,
            'value_previous_close' => $valuePreviousClose,
            'value_open' => $valueOpen,
            'observation' => $observation,
        ];
        $this->be($this->user);
        $this->json('POST', 'api/v1/cashier/balance/open/day', $data)
        ->assertJson([
            'msg' => 'Información guardada con éxito',
            'results' => [
                'value_previous_close' => 0,
                'value_open' => $valueOpen,
                'observation' => $observation,
            ],
        ])->assertStatus(200);
        $cashierBalance = CashierBalance::where('store_id', $this->store->id)->first();

        $companyId = $this->store->company_id;
        $preorderData = [
            'id_spot' => $this->spot->id,
            'instruction' => 'Testing',
            'specifications' => []
        ];
        $category = ProductCategory::where('company_id', $companyId)
        ->with(['products' => function ($products) {
            $products->with(['specifications' => function ($specs) {
                $specs->with('specificationCategory')->inRandomOrder()->take(rand(1, 3));
            }])->inRandomOrder()->first();
        }])->inRandomOrder()->first();
        $product = $category->products->first();
        $productQuantity = rand(1, 2);
        $productValue = $product->base_value * $productQuantity;
        $preorderData['id_product'] = $product->id;
        $preorderData['name'] = $product->name;
        $preorderData['invoice_name'] = $product->invoice_name;
        $preorderData['quantity'] = $productQuantity;
        $specs = $product->specifications;
        $categoryData = [
            'id' => $category->id,
            'name' => $category->name,
            'options' => []
        ];
        $specValue = 0;
        foreach ($specs as $spec) {
            $specCategory = $spec->specificationCategory;
            $specQuantity = $specCategory->isSizeType() ? rand(0, 1) : rand(0, $specCategory->max);
            $specChecked = $specQuantity > 0 ? 1 : 0;
            $sumValue = $specChecked ? $spec->value * $specQuantity : 0;
            array_push($categoryData['options'], [
                'id' => $spec->id,
                'name' => $spec->name,
                'value' => $spec->value,
                'quantity' => $specQuantity,
                'checked' => $specChecked
            ]);
            array_push($preorderData['specifications'], $categoryData);
            $specValue += $sumValue;
        }
        $value = $productValue + $specValue;
        $preorderData['value'] = $value;
        $this->be($this->user);
        $this->json('POST', 'api/v2/preorder', $preorderData)
        ->assertJson([
            'status' => 'Orden creada con éxito',
        ])->assertStatus(200);
        $order = Order::orderBy('id', 'desc')->first();
        // Verifico que los datos de la orden coincidan con los datos enviados.
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'store_id' => $this->store->id,
            'spot_id' => $this->spot->id,
            'order_value' => $value,
            'people' => null,
            'status' => 1,
            'employee_id' => $this->employee->id,
            'cash' => 1,
            'identifier' => 0,
            'preorder' => 1,
            'cashier_balance_id' => $cashierBalance->id,
        ]);
        // Agregar los mismos productos a la orden. El valor se duplica.
        $this->json('POST', 'api/v2/preorder', $preorderData)
        ->assertJson([
            'status' => 'Orden creada con éxito',
        ])->assertStatus(200);
        $order2 = Order::orderBy('id', 'desc')->first();
        $this->assertTrue($order->order_value < $order2->order_value);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'store_id' => $this->store->id,
            'spot_id' => $this->spot->id,
            'people' => null,
            'status' => 1,
            'employee_id' => $this->employee->id,
            'cash' => 1,
            'identifier' => 0,
            'preorder' => 1,
            'cashier_balance_id' => $cashierBalance->id,
        ]);
        $preorderData = [
            'id_spot' => $this->spot->id,
            'instruction' => 'Testing',
            'specifications' => []
        ];
        // Agregar nuevo producto.
        $category = ProductCategory::where('company_id', $companyId)
        ->with(['products' => function ($products) {
            $products->with(['specifications' => function ($specs) {
                $specs->with('specificationCategory')->inRandomOrder()->take(rand(1, 3));
            }])->inRandomOrder()->first();
        }])->inRandomOrder()->first();
        $product = $category->products->first();
        $productQuantity = rand(1, 2);
        $productValue = $product->base_value * $productQuantity;
        $preorderData['id_product'] = $product->id;
        $preorderData['name'] = $product->name;
        $preorderData['invoice_name'] = $product->invoice_name;
        $preorderData['quantity'] = $productQuantity;
        $specs = $product->specifications;
        $categoryData = [
            'id' => $category->id,
            'name' => $category->name,
            'options' => []
        ];
        $specValue = 0;
        foreach ($specs as $spec) {
            $specCategory = $spec->specificationCategory;
            $specQuantity = $specCategory->isSizeType() ? rand(0, 1) : rand(0, $specCategory->max);
            $specChecked = $specQuantity > 0 ? 1 : 0;
            $sumValue = $specChecked ? $spec->value * $specQuantity : 0;
            array_push($categoryData['options'], [
                'id' => $spec->id,
                'name' => $spec->name,
                'value' => $spec->value,
                'quantity' => $specQuantity,
                'checked' => $specChecked
            ]);
            array_push($preorderData['specifications'], $categoryData);
            $specValue += $sumValue;
        }
        $value2 = $productValue + $specValue;
        $preorderData['value'] = $value2;
        $this->be($this->user);
        $this->json('POST', 'api/v2/preorder', $preorderData)
        ->assertJson([
            'status' => 'Orden creada con éxito',
        ])->assertStatus(200);
        $order3 = Order::orderBy('id', 'desc')->first();
        $this->assertTrue($order2->order_value < $order3->order_value);
        // Verifico que se hayan sumado los valores del nuevo producto a la orden.
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'store_id' => $this->store->id,
            'spot_id' => $this->spot->id,
            'people' => null,
            'status' => 1,
            'employee_id' => $this->employee->id,
            'cash' => 1,
            'identifier' => 0,
            'preorder' => 1,
            'cashier_balance_id' => $cashierBalance->id,
        ]);
        // Modifico cantidad de un producto de la orden.
        $orderDetail = $order->orderDetails()->inRandomOrder()->first();
        $productId = $orderDetail->productDetail->product->id;
        // Cantidad mayor a la actual.
        $changeQuantity = rand(5, 7);
        $changePreorderData = [
            'id_spot' => $this->spot->id,
            'id_product' => $productId,
            'action' => 1,
            'quantity' => $changeQuantity,
            'id_order_detail' => $orderDetail->id,
        ];
        $this->json('POST', 'api/v2/preorder/update', $changePreorderData)
        ->assertJson([
            'status' => 'Orden modificada con éxito',
        ])->assertStatus(200);
        $order4 = Order::orderBy('id', 'desc')->first();
        $this->assertTrue($order3->order_value < $order4->order_value);
    }

    // El requerimiento valida que la caja esté abierta.
    public function testCashierMustBeOpen()
    {
        $this->be($this->user);
        $this->json('POST', 'api/v2/preorder/update', [])
        ->assertJson([
            'status' => 'Se tiene que abrir caja antes de hacer órdenes'
        ])->assertStatus(409);
    }

    // La caja debe estar abierta para modificar una orden.
    public function testCashierMustBeOpenWhenUpdatingOrder()
    {
        $now = Carbon::now();
        $dateOpen = $now->format('Y-m-d');
        $hourOpen = $now->format('H:i:s');
        $valuePreviousClose = rand(25000, 50000);
        $valueOpen = rand(0, 15000);
        $observation = 'Testing';
        $data = [
            'date_open' => $dateOpen,
            'hour_open' => $hourOpen,
            'value_previous_close' => $valuePreviousClose,
            'value_open' => $valueOpen,
            'observation' => $observation,
        ];
        $this->be($this->user);
        $this->json('POST', 'api/v1/cashier/balance/open/day', $data)
        ->assertJson([
            'msg' => 'Información guardada con éxito',
            'results' => [
                'value_previous_close' => 0,
                'value_open' => $valueOpen,
                'observation' => $observation,
            ],
        ])->assertStatus(200);
        $cashierBalance = CashierBalance::where('store_id', $this->store->id)->first();

        $companyId = $this->store->company_id;
        $preorderData = [
            'id_spot' => $this->spot->id,
            'instruction' => 'Testing',
            'specifications' => []
        ];
        $category = ProductCategory::where('company_id', $companyId)
        ->with(['products' => function ($products) {
            $products->with(['specifications' => function ($specs) {
                $specs->with('specificationCategory')->inRandomOrder()->take(rand(1, 3));
            }])->inRandomOrder()->first();
        }])->inRandomOrder()->first();
        $product = $category->products->first();
        $productQuantity = rand(1, 2);
        $productValue = $product->base_value * $productQuantity;
        $preorderData['id_product'] = $product->id;
        $preorderData['name'] = $product->name;
        $preorderData['invoice_name'] = $product->invoice_name;
        $preorderData['quantity'] = $productQuantity;
        $specs = $product->specifications;
        $categoryData = [
            'id' => $category->id,
            'name' => $category->name,
            'options' => []
        ];
        $specValue = 0;
        foreach ($specs as $spec) {
            $specCategory = $spec->specificationCategory;
            $specQuantity = $specCategory->isSizeType() ? rand(0, 1) : rand(0, $specCategory->max);
            $specChecked = $specQuantity > 0 ? 1 : 0;
            $sumValue = $specChecked ? $spec->value * $specQuantity : 0;
            array_push($categoryData['options'], [
                'id' => $spec->id,
                'name' => $spec->name,
                'value' => $spec->value,
                'quantity' => $specQuantity,
                'checked' => $specChecked
            ]);
            array_push($preorderData['specifications'], $categoryData);
            $specValue += $sumValue;
        }
        $value = $productValue + $specValue;
        $preorderData['value'] = $value;
        $this->be($this->user);
        $this->json('POST', 'api/v2/preorder', $preorderData)
        ->assertJson([
            'status' => 'Orden creada con éxito',
        ])->assertStatus(200);
        $order = Order::orderBy('id', 'desc')->first();
        // Verifico que los datos de la orden coincidan con los datos enviados.
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'store_id' => $this->store->id,
            'spot_id' => $this->spot->id,
            'order_value' => $value,
            'people' => null,
            'status' => 1,
            'employee_id' => $this->employee->id,
            'cash' => 1,
            'identifier' => 0,
            'preorder' => 1,
            'cashier_balance_id' => $cashierBalance->id,
        ]);
        $now = Carbon::now();
        $dateClose = $now->format('Y-m-d');
        $hourClose = $now->format('H:i:s');
        $valueClose = rand(25000, 50000);
        $expenses = [
            [
                'name' => str_random(10),
                'value' => 1,
            ]
        ];
        $closeData = [
            'cashier_balance_id' => $cashierBalance->id,
            'date_close' => $dateClose,
            'hour_close' => $hourClose,
            'value_close' => $valueClose,
            'expenses' => $expenses,
            'print_balance' => true,
        ];
        $order->preorder = 0;
        $order->save();
        $this->json('POST', 'api/v1/cashier/balance/close/day', $closeData)
        ->assertJson([
            'msg' => 'Información guardada con éxito',
            'results' => null
        ])->assertStatus(200);
        // Modifico cantidad de un producto de la orden.
        $orderDetail = $order->orderDetails()->inRandomOrder()->first();
        $productId = $orderDetail->productDetail->product->id;
        // Cantidad mayor a la actual.
        $changeQuantity = rand(5, 7);
        $changePreorderData = [
            'id_spot' => $this->spot->id,
            'id_product' => $productId,
            'action' => 1,
            'quantity' => $changeQuantity,
            'id_order_detail' => $orderDetail->id,
        ];
        $this->json('POST', 'api/v2/preorder/update', $changePreorderData)
        ->assertJson([
            'status' => 'Se tiene que abrir caja antes de hacer órdenes'
        ])->assertStatus(409);
        $order2 = Order::orderBy('id', 'desc')->first();
        $this->assertTrue($order->order_value === $order2->order_value);
    }

    // No encuentra la preorden que se intenta modificar.
    public function testPreorderNotFound()
    {
        $now = Carbon::now();
        $dateOpen = $now->format('Y-m-d');
        $hourOpen = $now->format('H:i:s');
        $valuePreviousClose = rand(25000, 50000);
        $valueOpen = rand(0, 15000);
        $observation = 'Testing';
        $data = [
            'date_open' => $dateOpen,
            'hour_open' => $hourOpen,
            'value_previous_close' => $valuePreviousClose,
            'value_open' => $valueOpen,
            'observation' => $observation,
        ];
        $this->be($this->user);
        $this->json('POST', 'api/v1/cashier/balance/open/day', $data)
        ->assertJson([
            'msg' => 'Información guardada con éxito',
            'results' => [
                'value_previous_close' => 0,
                'value_open' => $valueOpen,
                'observation' => $observation,
            ],
        ])->assertStatus(200);
        $cashierBalance = CashierBalance::where('store_id', $this->store->id)->first();

        $companyId = $this->store->company_id;
        $preorderData = [
            'id_spot' => $this->spot->id,
            'instruction' => 'Testing',
            'specifications' => []
        ];
        $category = ProductCategory::where('company_id', $companyId)
        ->with(['products' => function ($products) {
            $products->with(['specifications' => function ($specs) {
                $specs->with('specificationCategory')->inRandomOrder()->take(rand(1, 3));
            }])->inRandomOrder()->first();
        }])->inRandomOrder()->first();
        $product = $category->products->first();
        $productQuantity = rand(1, 2);
        $productValue = $product->base_value * $productQuantity;
        $preorderData['id_product'] = $product->id;
        $preorderData['name'] = $product->name;
        $preorderData['invoice_name'] = $product->invoice_name;
        $preorderData['quantity'] = $productQuantity;
        $specs = $product->specifications;
        $categoryData = [
            'id' => $category->id,
            'name' => $category->name,
            'options' => []
        ];
        $specValue = 0;
        foreach ($specs as $spec) {
            $specCategory = $spec->specificationCategory;
            $specQuantity = $specCategory->isSizeType() ? rand(0, 1) : rand(0, $specCategory->max);
            $specChecked = $specQuantity > 0 ? 1 : 0;
            $sumValue = $specChecked ? $spec->value * $specQuantity : 0;
            array_push($categoryData['options'], [
                'id' => $spec->id,
                'name' => $spec->name,
                'value' => $spec->value,
                'quantity' => $specQuantity,
                'checked' => $specChecked
            ]);
            array_push($preorderData['specifications'], $categoryData);
            $specValue += $sumValue;
        }
        $value = $productValue + $specValue;
        $preorderData['value'] = $value;
        $this->be($this->user);
        $this->json('POST', 'api/v2/preorder', $preorderData)
        ->assertJson([
            'status' => 'Orden creada con éxito',
        ])->assertStatus(200);
        $order = Order::orderBy('id', 'desc')->first();
        // Verifico que los datos de la orden coincidan con los datos enviados.
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'store_id' => $this->store->id,
            'spot_id' => $this->spot->id,
            'order_value' => $value,
            'people' => null,
            'status' => 1,
            'employee_id' => $this->employee->id,
            'cash' => 1,
            'identifier' => 0,
            'preorder' => 1,
            'cashier_balance_id' => $cashierBalance->id,
        ]);
        // Forzar fallo en búsqueda de preorden.
        $order->preorder = 0;
        $order->save();
        // Modifico cantidad de un producto de la orden.
        $orderDetail = $order->orderDetails()->inRandomOrder()->first();
        $productId = $orderDetail->productDetail->product->id;
        // Cantidad mayor a la actual.
        $changeQuantity = rand(5, 7);
        $changePreorderData = [
            'id_spot' => $this->spot->id,
            'id_product' => $productId,
            'action' => 1,
            'quantity' => $changeQuantity,
            'id_order_detail' => $orderDetail->id,
        ];
        $this->json('POST', 'api/v2/preorder/update', $changePreorderData)
        ->assertJson([
            'status' => 'Esta orden no existe'
        ])->assertStatus(404);
        $order2 = Order::orderBy('id', 'desc')->first();
        $this->assertTrue($order->order_value === $order2->order_value);
    }

    // No encuentra el producto en la orden que se intenta modificar.
    public function testProductNotFound()
    {
        $now = Carbon::now();
        $dateOpen = $now->format('Y-m-d');
        $hourOpen = $now->format('H:i:s');
        $valuePreviousClose = rand(25000, 50000);
        $valueOpen = rand(0, 15000);
        $observation = 'Testing';
        $data = [
            'date_open' => $dateOpen,
            'hour_open' => $hourOpen,
            'value_previous_close' => $valuePreviousClose,
            'value_open' => $valueOpen,
            'observation' => $observation,
        ];
        $this->be($this->user);
        $this->json('POST', 'api/v1/cashier/balance/open/day', $data)
        ->assertJson([
            'msg' => 'Información guardada con éxito',
            'results' => [
                'value_previous_close' => 0,
                'value_open' => $valueOpen,
                'observation' => $observation,
            ],
        ])->assertStatus(200);
        $cashierBalance = CashierBalance::where('store_id', $this->store->id)->first();

        $companyId = $this->store->company_id;
        $preorderData = [
            'id_spot' => $this->spot->id,
            'instruction' => 'Testing',
            'specifications' => []
        ];
        $category = ProductCategory::where('company_id', $companyId)
        ->with(['products' => function ($products) {
            $products->with(['specifications' => function ($specs) {
                $specs->with('specificationCategory')->inRandomOrder()->take(rand(1, 3));
            }])->inRandomOrder()->first();
        }])->inRandomOrder()->first();
        $product = $category->products->first();
        $productQuantity = rand(1, 2);
        $productValue = $product->base_value * $productQuantity;
        $preorderData['id_product'] = $product->id;
        $preorderData['name'] = $product->name;
        $preorderData['invoice_name'] = $product->invoice_name;
        $preorderData['quantity'] = $productQuantity;
        $specs = $product->specifications;
        $categoryData = [
            'id' => $category->id,
            'name' => $category->name,
            'options' => []
        ];
        $specValue = 0;
        foreach ($specs as $spec) {
            $specCategory = $spec->specificationCategory;
            $specQuantity = $specCategory->isSizeType() ? rand(0, 1) : rand(0, $specCategory->max);
            $specChecked = $specQuantity > 0 ? 1 : 0;
            $sumValue = $specChecked ? $spec->value * $specQuantity : 0;
            array_push($categoryData['options'], [
                'id' => $spec->id,
                'name' => $spec->name,
                'value' => $spec->value,
                'quantity' => $specQuantity,
                'checked' => $specChecked
            ]);
            array_push($preorderData['specifications'], $categoryData);
            $specValue += $sumValue;
        }
        $value = $productValue + $specValue;
        $preorderData['value'] = $value;
        $this->be($this->user);
        $this->json('POST', 'api/v2/preorder', $preorderData)
        ->assertJson([
            'status' => 'Orden creada con éxito',
        ])->assertStatus(200);
        $order = Order::orderBy('id', 'desc')->first();
        // Verifico que los datos de la orden coincidan con los datos enviados.
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'store_id' => $this->store->id,
            'spot_id' => $this->spot->id,
            'order_value' => $value,
            'people' => null,
            'status' => 1,
            'employee_id' => $this->employee->id,
            'cash' => 1,
            'identifier' => 0,
            'preorder' => 1,
            'cashier_balance_id' => $cashierBalance->id,
        ]);
        // Modifico cantidad de un producto de la orden.
        $orderDetail = $order->orderDetails()->inRandomOrder()->first();
        // Forzar fallo en la búsqueda del producto.
        $productId = 99999;
        // Cantidad mayor a la actual.
        $changeQuantity = rand(5, 7);
        $changePreorderData = [
            'id_spot' => $this->spot->id,
            'id_product' => $productId,
            'action' => 1,
            'quantity' => $changeQuantity,
            'id_order_detail' => $orderDetail->id,
        ];
        $this->json('POST', 'api/v2/preorder/update', $changePreorderData)
        ->assertJson([
            'status' => 'Este producto no existe'
        ])->assertStatus(404);
        $order2 = Order::orderBy('id', 'desc')->first();
        $this->assertTrue($order->order_value === $order2->order_value);
    }

    // No encuentra el detalle del producto en la orden que se intenta modificar.
    public function testOrderDetailNotFound()
    {
        $now = Carbon::now();
        $dateOpen = $now->format('Y-m-d');
        $hourOpen = $now->format('H:i:s');
        $valuePreviousClose = rand(25000, 50000);
        $valueOpen = rand(0, 15000);
        $observation = 'Testing';
        $data = [
            'date_open' => $dateOpen,
            'hour_open' => $hourOpen,
            'value_previous_close' => $valuePreviousClose,
            'value_open' => $valueOpen,
            'observation' => $observation,
        ];
        $this->be($this->user);
        $this->json('POST', 'api/v1/cashier/balance/open/day', $data)
        ->assertJson([
            'msg' => 'Información guardada con éxito',
            'results' => [
                'value_previous_close' => 0,
                'value_open' => $valueOpen,
                'observation' => $observation,
            ],
        ])->assertStatus(200);
        $cashierBalance = CashierBalance::where('store_id', $this->store->id)->first();

        $companyId = $this->store->company_id;
        $preorderData = [
            'id_spot' => $this->spot->id,
            'instruction' => 'Testing',
            'specifications' => []
        ];
        $category = ProductCategory::where('company_id', $companyId)
        ->with(['products' => function ($products) {
            $products->with(['specifications' => function ($specs) {
                $specs->with('specificationCategory')->inRandomOrder()->take(rand(1, 3));
            }])->inRandomOrder()->first();
        }])->inRandomOrder()->first();
        $product = $category->products->first();
        $productQuantity = rand(1, 2);
        $productValue = $product->base_value * $productQuantity;
        $preorderData['id_product'] = $product->id;
        $preorderData['name'] = $product->name;
        $preorderData['invoice_name'] = $product->invoice_name;
        $preorderData['quantity'] = $productQuantity;
        $specs = $product->specifications;
        $categoryData = [
            'id' => $category->id,
            'name' => $category->name,
            'options' => []
        ];
        $specValue = 0;
        foreach ($specs as $spec) {
            $specCategory = $spec->specificationCategory;
            $specQuantity = $specCategory->isSizeType() ? rand(0, 1) : rand(0, $specCategory->max);
            $specChecked = $specQuantity > 0 ? 1 : 0;
            $sumValue = $specChecked ? $spec->value * $specQuantity : 0;
            array_push($categoryData['options'], [
                'id' => $spec->id,
                'name' => $spec->name,
                'value' => $spec->value,
                'quantity' => $specQuantity,
                'checked' => $specChecked
            ]);
            array_push($preorderData['specifications'], $categoryData);
            $specValue += $sumValue;
        }
        $value = $productValue + $specValue;
        $preorderData['value'] = $value;
        $this->be($this->user);
        $this->json('POST', 'api/v2/preorder', $preorderData)
        ->assertJson([
            'status' => 'Orden creada con éxito',
        ])->assertStatus(200);
        $order = Order::orderBy('id', 'desc')->first();
        // Verifico que los datos de la orden coincidan con los datos enviados.
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'store_id' => $this->store->id,
            'spot_id' => $this->spot->id,
            'order_value' => $value,
            'people' => null,
            'status' => 1,
            'employee_id' => $this->employee->id,
            'cash' => 1,
            'identifier' => 0,
            'preorder' => 1,
            'cashier_balance_id' => $cashierBalance->id,
        ]);
        // Modifico cantidad de un producto de la orden.
        $orderDetail = $order->orderDetails()->inRandomOrder()->first();
        $productId = $orderDetail->productDetail->product->id;
        // Forzar fallo en la búsqueda del OrderDetail.
        $orderDetailId = 99999;
        // Cantidad mayor a la actual.
        $changeQuantity = rand(5, 7);
        $changePreorderData = [
            'id_spot' => $this->spot->id,
            'id_product' => $productId,
            'action' => 1,
            'quantity' => $changeQuantity,
            'id_order_detail' => $orderDetailId,
        ];
        $this->json('POST', 'api/v2/preorder/update', $changePreorderData)
        ->assertJson([
            'status' => 'Este producto no está en la orden'
        ])->assertStatus(404);
        $order2 = Order::orderBy('id', 'desc')->first();
        $this->assertTrue($order->order_value === $order2->order_value);
    }

    // Disminuir la cantidad de un producto en la preorden.
    public function testDecreaseProductQuantity()
    {
        $now = Carbon::now();
        $dateOpen = $now->format('Y-m-d');
        $hourOpen = $now->format('H:i:s');
        $valuePreviousClose = rand(25000, 50000);
        $valueOpen = rand(0, 15000);
        $observation = 'Testing';
        $data = [
            'date_open' => $dateOpen,
            'hour_open' => $hourOpen,
            'value_previous_close' => $valuePreviousClose,
            'value_open' => $valueOpen,
            'observation' => $observation,
        ];
        $this->be($this->user);
        $this->json('POST', 'api/v1/cashier/balance/open/day', $data)
        ->assertJson([
            'msg' => 'Información guardada con éxito',
            'results' => [
                'value_previous_close' => 0,
                'value_open' => $valueOpen,
                'observation' => $observation,
            ],
        ])->assertStatus(200);
        $cashierBalance = CashierBalance::where('store_id', $this->store->id)->first();

        $companyId = $this->store->company_id;
        $preorderData = [
            'id_spot' => $this->spot->id,
            'instruction' => 'Testing',
            'specifications' => []
        ];
        $category = ProductCategory::where('company_id', $companyId)
        ->with(['products' => function ($products) {
            $products->with(['specifications' => function ($specs) {
                $specs->with('specificationCategory')->inRandomOrder()->take(rand(1, 3));
            }])->inRandomOrder()->first();
        }])->inRandomOrder()->first();
        $product = $category->products->first();
        $productQuantity = rand(2, 3);
        $productValue = $product->base_value * $productQuantity;
        $preorderData['id_product'] = $product->id;
        $preorderData['name'] = $product->name;
        $preorderData['invoice_name'] = $product->invoice_name;
        $preorderData['quantity'] = $productQuantity;
        $specs = $product->specifications;
        $categoryData = [
            'id' => $category->id,
            'name' => $category->name,
            'options' => []
        ];
        $specValue = 0;
        foreach ($specs as $spec) {
            $specCategory = $spec->specificationCategory;
            $specQuantity = $specCategory->isSizeType() ? rand(0, 1) : rand(0, $specCategory->max);
            $specChecked = $specQuantity > 0 ? 1 : 0;
            $sumValue = $specChecked ? $spec->value * $specQuantity : 0;
            array_push($categoryData['options'], [
                'id' => $spec->id,
                'name' => $spec->name,
                'value' => $spec->value,
                'quantity' => $specQuantity,
                'checked' => $specChecked
            ]);
            array_push($preorderData['specifications'], $categoryData);
            $specValue += $sumValue;
        }
        $value = $productValue + $specValue;
        $preorderData['value'] = $value;
        $this->be($this->user);
        $this->json('POST', 'api/v2/preorder', $preorderData)
        ->assertJson([
            'status' => 'Orden creada con éxito',
        ])->assertStatus(200);
        $order = Order::orderBy('id', 'desc')->first();
        // Verifico que los datos de la orden coincidan con los datos enviados.
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'store_id' => $this->store->id,
            'spot_id' => $this->spot->id,
            'order_value' => $value,
            'people' => null,
            'status' => 1,
            'employee_id' => $this->employee->id,
            'cash' => 1,
            'identifier' => 0,
            'preorder' => 1,
            'cashier_balance_id' => $cashierBalance->id,
        ]);
        // Agregar los mismos productos a la orden. El valor se duplica.
        $this->json('POST', 'api/v2/preorder', $preorderData)
        ->assertJson([
            'status' => 'Orden creada con éxito',
        ])->assertStatus(200);
        $order2 = Order::orderBy('id', 'desc')->first();
        $this->assertTrue($order->order_value < $order2->order_value);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'store_id' => $this->store->id,
            'spot_id' => $this->spot->id,
            'people' => null,
            'status' => 1,
            'employee_id' => $this->employee->id,
            'cash' => 1,
            'identifier' => 0,
            'preorder' => 1,
            'cashier_balance_id' => $cashierBalance->id,
        ]);
        $preorderData = [
            'id_spot' => $this->spot->id,
            'instruction' => 'Testing',
            'specifications' => []
        ];
        // Agregar nuevo producto.
        $category = ProductCategory::where('company_id', $companyId)
        ->with(['products' => function ($products) {
            $products->with(['specifications' => function ($specs) {
                $specs->with('specificationCategory')->inRandomOrder()->take(rand(1, 3));
            }])->inRandomOrder()->first();
        }])->inRandomOrder()->first();
        $product = $category->products->first();
        $productQuantity = rand(2, 3);
        $productValue = $product->base_value * $productQuantity;
        $preorderData['id_product'] = $product->id;
        $preorderData['name'] = $product->name;
        $preorderData['invoice_name'] = $product->invoice_name;
        $preorderData['quantity'] = $productQuantity;
        $specs = $product->specifications;
        $categoryData = [
            'id' => $category->id,
            'name' => $category->name,
            'options' => []
        ];
        $specValue = 0;
        foreach ($specs as $spec) {
            $specCategory = $spec->specificationCategory;
            $specQuantity = $specCategory->isSizeType() ? rand(0, 1) : rand(0, $specCategory->max);
            $specChecked = $specQuantity > 0 ? 1 : 0;
            $sumValue = $specChecked ? $spec->value * $specQuantity : 0;
            array_push($categoryData['options'], [
                'id' => $spec->id,
                'name' => $spec->name,
                'value' => $spec->value,
                'quantity' => $specQuantity,
                'checked' => $specChecked
            ]);
            array_push($preorderData['specifications'], $categoryData);
            $specValue += $sumValue;
        }
        $value2 = $productValue + $specValue;
        $preorderData['value'] = $value2;
        $this->be($this->user);
        $this->json('POST', 'api/v2/preorder', $preorderData)
        ->assertJson([
            'status' => 'Orden creada con éxito',
        ])->assertStatus(200);
        $order3 = Order::orderBy('id', 'desc')->first();
        $this->assertTrue($order2->order_value < $order3->order_value);
        // Verifico que se hayan sumado los valores del nuevo producto a la orden.
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'store_id' => $this->store->id,
            'spot_id' => $this->spot->id,
            'people' => null,
            'status' => 1,
            'employee_id' => $this->employee->id,
            'cash' => 1,
            'identifier' => 0,
            'preorder' => 1,
            'cashier_balance_id' => $cashierBalance->id,
        ]);
        // Modifico cantidad de un producto de la orden.
        $orderDetail = $order->orderDetails()->inRandomOrder()->first();
        $productId = $orderDetail->productDetail->product->id;
        // Cantidad menor a la actual.
        $changeQuantity = 1;
        $changePreorderData = [
            'id_spot' => $this->spot->id,
            'id_product' => $productId,
            'action' => 1,
            'quantity' => $changeQuantity,
            'id_order_detail' => $orderDetail->id,
        ];
        $this->json('POST', 'api/v2/preorder/update', $changePreorderData)
        ->assertJson([
            'status' => 'Orden modificada con éxito',
        ])->assertStatus(200);
        $order4 = Order::orderBy('id', 'desc')->first();
        $this->assertTrue($order3->order_value > $order4->order_value);
    }

    // Remover un producto de la preorden.
    public function testRemoveProductFromPreorder()
    {
        $now = Carbon::now();
        $dateOpen = $now->format('Y-m-d');
        $hourOpen = $now->format('H:i:s');
        $valuePreviousClose = rand(25000, 50000);
        $valueOpen = rand(0, 15000);
        $observation = 'Testing';
        $data = [
            'date_open' => $dateOpen,
            'hour_open' => $hourOpen,
            'value_previous_close' => $valuePreviousClose,
            'value_open' => $valueOpen,
            'observation' => $observation,
        ];
        $this->be($this->user);
        $this->json('POST', 'api/v1/cashier/balance/open/day', $data)
        ->assertJson([
            'msg' => 'Información guardada con éxito',
            'results' => [
                'value_previous_close' => 0,
                'value_open' => $valueOpen,
                'observation' => $observation,
            ],
        ])->assertStatus(200);
        $cashierBalance = CashierBalance::where('store_id', $this->store->id)->first();

        $companyId = $this->store->company_id;
        $preorderData = [
            'id_spot' => $this->spot->id,
            'instruction' => 'Testing',
            'specifications' => []
        ];
        $category = ProductCategory::where('company_id', $companyId)
        ->with(['products' => function ($products) {
            $products->with(['specifications' => function ($specs) {
                $specs->with('specificationCategory')->inRandomOrder()->take(rand(1, 3));
            }])->inRandomOrder()->first();
        }])->inRandomOrder()->first();
        $product = $category->products->first();
        $productQuantity = rand(2, 3);
        $productValue = $product->base_value * $productQuantity;
        $preorderData['id_product'] = $product->id;
        $preorderData['name'] = $product->name;
        $preorderData['invoice_name'] = $product->invoice_name;
        $preorderData['quantity'] = $productQuantity;
        $specs = $product->specifications;
        $categoryData = [
            'id' => $category->id,
            'name' => $category->name,
            'options' => []
        ];
        $specValue = 0;
        foreach ($specs as $spec) {
            $specCategory = $spec->specificationCategory;
            $specQuantity = $specCategory->isSizeType() ? rand(0, 1) : rand(0, $specCategory->max);
            $specChecked = $specQuantity > 0 ? 1 : 0;
            $sumValue = $specChecked ? $spec->value * $specQuantity : 0;
            array_push($categoryData['options'], [
                'id' => $spec->id,
                'name' => $spec->name,
                'value' => $spec->value,
                'quantity' => $specQuantity,
                'checked' => $specChecked
            ]);
            array_push($preorderData['specifications'], $categoryData);
            $specValue += $sumValue;
        }
        $value = $productValue + $specValue;
        $preorderData['value'] = $value;
        $this->be($this->user);
        $this->json('POST', 'api/v2/preorder', $preorderData)
        ->assertJson([
            'status' => 'Orden creada con éxito',
        ])->assertStatus(200);
        $order = Order::orderBy('id', 'desc')->first();
        // Verifico que los datos de la orden coincidan con los datos enviados.
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'store_id' => $this->store->id,
            'spot_id' => $this->spot->id,
            'order_value' => $value,
            'people' => null,
            'status' => 1,
            'employee_id' => $this->employee->id,
            'cash' => 1,
            'identifier' => 0,
            'preorder' => 1,
            'cashier_balance_id' => $cashierBalance->id,
        ]);
        // Agregar los mismos productos a la orden. El valor se duplica.
        $this->json('POST', 'api/v2/preorder', $preorderData)
        ->assertJson([
            'status' => 'Orden creada con éxito',
        ])->assertStatus(200);
        $order2 = Order::orderBy('id', 'desc')->first();
        $this->assertTrue($order->order_value < $order2->order_value);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'store_id' => $this->store->id,
            'spot_id' => $this->spot->id,
            'people' => null,
            'status' => 1,
            'employee_id' => $this->employee->id,
            'cash' => 1,
            'identifier' => 0,
            'preorder' => 1,
            'cashier_balance_id' => $cashierBalance->id,
        ]);
        $preorderData = [
            'id_spot' => $this->spot->id,
            'instruction' => 'Testing',
            'specifications' => []
        ];
        // Agregar nuevo producto.
        $category = ProductCategory::where('company_id', $companyId)
        ->with(['products' => function ($products) {
            $products->with(['specifications' => function ($specs) {
                $specs->with('specificationCategory')->inRandomOrder()->take(rand(1, 3));
            }])->inRandomOrder()->first();
        }])->inRandomOrder()->first();
        $product = $category->products->first();
        $productQuantity = rand(2, 3);
        $productValue = $product->base_value * $productQuantity;
        $preorderData['id_product'] = $product->id;
        $preorderData['name'] = $product->name;
        $preorderData['invoice_name'] = $product->invoice_name;
        $preorderData['quantity'] = $productQuantity;
        $specs = $product->specifications;
        $categoryData = [
            'id' => $category->id,
            'name' => $category->name,
            'options' => []
        ];
        $specValue = 0;
        foreach ($specs as $spec) {
            $specCategory = $spec->specificationCategory;
            $specQuantity = $specCategory->isSizeType() ? rand(0, 1) : rand(0, $specCategory->max);
            $specChecked = $specQuantity > 0 ? 1 : 0;
            $sumValue = $specChecked ? $spec->value * $specQuantity : 0;
            array_push($categoryData['options'], [
                'id' => $spec->id,
                'name' => $spec->name,
                'value' => $spec->value,
                'quantity' => $specQuantity,
                'checked' => $specChecked
            ]);
            array_push($preorderData['specifications'], $categoryData);
            $specValue += $sumValue;
        }
        $value2 = $productValue + $specValue;
        $preorderData['value'] = $value2;
        $this->be($this->user);
        $this->json('POST', 'api/v2/preorder', $preorderData)
        ->assertJson([
            'status' => 'Orden creada con éxito',
        ])->assertStatus(200);
        $order3 = Order::orderBy('id', 'desc')->first();
        $this->assertTrue($order2->order_value < $order3->order_value);
        // Verifico que se hayan sumado los valores del nuevo producto a la orden.
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'store_id' => $this->store->id,
            'spot_id' => $this->spot->id,
            'people' => null,
            'status' => 1,
            'employee_id' => $this->employee->id,
            'cash' => 1,
            'identifier' => 0,
            'preorder' => 1,
            'cashier_balance_id' => $cashierBalance->id,
        ]);
        // Remover producto de la preorden.
        $orderDetail = $order->orderDetails()->inRandomOrder()->first();
        $productId = $orderDetail->productDetail->product->id;
        $changePreorderData = [
            'id_spot' => $this->spot->id,
            'id_product' => $productId,
            'action' => 2,
            'id_order_detail' => $orderDetail->id,
        ];
        $this->json('POST', 'api/v2/preorder/update', $changePreorderData)
        ->assertJson([
            'status' => 'Orden modificada con éxito',
        ])->assertStatus(200);
        $order4 = Order::orderBy('id', 'desc')->first();
        $this->assertTrue($order3->order_value > $order4->order_value);
    }

    // Acción inválida (no es aumento ni disminución de producto).
    public function testInvalidAction()
    {
        $now = Carbon::now();
        $dateOpen = $now->format('Y-m-d');
        $hourOpen = $now->format('H:i:s');
        $valuePreviousClose = rand(25000, 50000);
        $valueOpen = rand(0, 15000);
        $observation = 'Testing';
        $data = [
            'date_open' => $dateOpen,
            'hour_open' => $hourOpen,
            'value_previous_close' => $valuePreviousClose,
            'value_open' => $valueOpen,
            'observation' => $observation,
        ];
        $this->be($this->user);
        $this->json('POST', 'api/v1/cashier/balance/open/day', $data)
        ->assertJson([
            'msg' => 'Información guardada con éxito',
            'results' => [
                'value_previous_close' => 0,
                'value_open' => $valueOpen,
                'observation' => $observation,
            ],
        ])->assertStatus(200);
        $cashierBalance = CashierBalance::where('store_id', $this->store->id)->first();

        $companyId = $this->store->company_id;
        $preorderData = [
            'id_spot' => $this->spot->id,
            'instruction' => 'Testing',
            'specifications' => []
        ];
        $category = ProductCategory::where('company_id', $companyId)
        ->with(['products' => function ($products) {
            $products->with(['specifications' => function ($specs) {
                $specs->with('specificationCategory')->inRandomOrder()->take(rand(1, 3));
            }])->inRandomOrder()->first();
        }])->inRandomOrder()->first();
        $product = $category->products->first();
        $productQuantity = rand(2, 3);
        $productValue = $product->base_value * $productQuantity;
        $preorderData['id_product'] = $product->id;
        $preorderData['name'] = $product->name;
        $preorderData['invoice_name'] = $product->invoice_name;
        $preorderData['quantity'] = $productQuantity;
        $specs = $product->specifications;
        $categoryData = [
            'id' => $category->id,
            'name' => $category->name,
            'options' => []
        ];
        $specValue = 0;
        foreach ($specs as $spec) {
            $specCategory = $spec->specificationCategory;
            $specQuantity = $specCategory->isSizeType() ? rand(0, 1) : rand(0, $specCategory->max);
            $specChecked = $specQuantity > 0 ? 1 : 0;
            $sumValue = $specChecked ? $spec->value * $specQuantity : 0;
            array_push($categoryData['options'], [
                'id' => $spec->id,
                'name' => $spec->name,
                'value' => $spec->value,
                'quantity' => $specQuantity,
                'checked' => $specChecked
            ]);
            array_push($preorderData['specifications'], $categoryData);
            $specValue += $sumValue;
        }
        $value = $productValue + $specValue;
        $preorderData['value'] = $value;
        $this->be($this->user);
        $this->json('POST', 'api/v2/preorder', $preorderData)
        ->assertJson([
            'status' => 'Orden creada con éxito',
        ])->assertStatus(200);
        $order = Order::orderBy('id', 'desc')->first();
        // Verifico que los datos de la orden coincidan con los datos enviados.
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'store_id' => $this->store->id,
            'spot_id' => $this->spot->id,
            'order_value' => $value,
            'people' => null,
            'status' => 1,
            'employee_id' => $this->employee->id,
            'cash' => 1,
            'identifier' => 0,
            'preorder' => 1,
            'cashier_balance_id' => $cashierBalance->id,
        ]);
        // Agregar los mismos productos a la orden. El valor se duplica.
        $this->json('POST', 'api/v2/preorder', $preorderData)
        ->assertJson([
            'status' => 'Orden creada con éxito',
        ])->assertStatus(200);
        $order2 = Order::orderBy('id', 'desc')->first();
        $this->assertTrue($order->order_value < $order2->order_value);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'store_id' => $this->store->id,
            'spot_id' => $this->spot->id,
            'people' => null,
            'status' => 1,
            'employee_id' => $this->employee->id,
            'cash' => 1,
            'identifier' => 0,
            'preorder' => 1,
            'cashier_balance_id' => $cashierBalance->id,
        ]);
        $preorderData = [
            'id_spot' => $this->spot->id,
            'instruction' => 'Testing',
            'specifications' => []
        ];
        // Agregar nuevo producto.
        $category = ProductCategory::where('company_id', $companyId)
        ->with(['products' => function ($products) {
            $products->with(['specifications' => function ($specs) {
                $specs->with('specificationCategory')->inRandomOrder()->take(rand(1, 3));
            }])->inRandomOrder()->first();
        }])->inRandomOrder()->first();
        $product = $category->products->first();
        $productQuantity = rand(2, 3);
        $productValue = $product->base_value * $productQuantity;
        $preorderData['id_product'] = $product->id;
        $preorderData['name'] = $product->name;
        $preorderData['invoice_name'] = $product->invoice_name;
        $preorderData['quantity'] = $productQuantity;
        $specs = $product->specifications;
        $categoryData = [
            'id' => $category->id,
            'name' => $category->name,
            'options' => []
        ];
        $specValue = 0;
        foreach ($specs as $spec) {
            $specCategory = $spec->specificationCategory;
            $specQuantity = $specCategory->isSizeType() ? rand(0, 1) : rand(0, $specCategory->max);
            $specChecked = $specQuantity > 0 ? 1 : 0;
            $sumValue = $specChecked ? $spec->value * $specQuantity : 0;
            array_push($categoryData['options'], [
                'id' => $spec->id,
                'name' => $spec->name,
                'value' => $spec->value,
                'quantity' => $specQuantity,
                'checked' => $specChecked
            ]);
            array_push($preorderData['specifications'], $categoryData);
            $specValue += $sumValue;
        }
        $value2 = $productValue + $specValue;
        $preorderData['value'] = $value2;
        $this->be($this->user);
        $this->json('POST', 'api/v2/preorder', $preorderData)
        ->assertJson([
            'status' => 'Orden creada con éxito',
        ])->assertStatus(200);
        $order3 = Order::orderBy('id', 'desc')->first();
        $this->assertTrue($order2->order_value < $order3->order_value);
        // Verifico que se hayan sumado los valores del nuevo producto a la orden.
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'store_id' => $this->store->id,
            'spot_id' => $this->spot->id,
            'people' => null,
            'status' => 1,
            'employee_id' => $this->employee->id,
            'cash' => 1,
            'identifier' => 0,
            'preorder' => 1,
            'cashier_balance_id' => $cashierBalance->id,
        ]);
        // Remover producto de la preorden.
        $orderDetail = $order->orderDetails()->inRandomOrder()->first();
        $productId = $orderDetail->productDetail->product->id;
        $changePreorderData = [
            'id_spot' => $this->spot->id,
            'id_product' => $productId,
            'action' => 1000,
            'id_order_detail' => $orderDetail->id,
        ];
        $this->json('POST', 'api/v2/preorder/update', $changePreorderData)
        ->assertJson([
            'status' => 'Esta operación no existe',
        ])->assertStatus(404);
        $order4 = Order::orderBy('id', 'desc')->first();
        $this->assertTrue($order3->order_value === $order4->order_value);
    }
}
