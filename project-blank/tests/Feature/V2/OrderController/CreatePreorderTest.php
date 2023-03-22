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
use DB;

class CreatePreorderTest extends TestCase
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

    // Abre caja y crea la preorden correctamente.
    public function testSuccess()
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
        $people = rand(1, 5);
        $preorderData = [
            'id_spot' => $this->spot->id,
            'instruction' => 'Testing',
            'people' => $people,
            'specifications' => []
        ];
        $category = ProductCategory::where('company_id', $companyId)
        ->with(['products' => function ($products) {
            $products->with(['specifications' => function ($specs) {
                $specs->with('specificationCategory')->inRandomOrder()->take(rand(1, 3));
            }])->inRandomOrder()->first();
        }])->inRandomOrder()->first();
        $product = $category->products->first();
        $productQuantity = rand(1, 3);
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
            $specQuantity = $specCategory->isSizeType() ? 1 : rand(1, $specCategory->max);
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
        $order = Order::with('orderDetails.productDetail')->orderBy('id', 'desc')->first();
        // Verifico que los datos de la orden coincidan con los datos enviados.
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'store_id' => $this->store->id,
            'spot_id' => $this->spot->id,
            'order_value' => $value,
            'status' => 1,
            'employee_id' => $this->employee->id,
            'cash' => 1,
            'identifier' => 0,
            'people' => $people,
            'preorder' => 1,
            'cashier_balance_id' => $cashierBalance->id,
        ]);
    }

    // Abre caja y crea la preorden correctamente sin especificaciones.
    public function testNoSpecifications()
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
        $people = rand(1, 5);
        $preorderData = [
            'id_spot' => $this->spot->id,
            'instruction' => 'Testing',
            'people' => $people
        ];
        $category = ProductCategory::where('company_id', $companyId)
        ->with('products')->inRandomOrder()->first();
        $product = $category->products->first();
        $productQuantity = rand(1, 3);
        $preorderData['id_product'] = $product->id;
        $preorderData['name'] = $product->name;
        $preorderData['invoice_name'] = $product->invoice_name;
        $preorderData['quantity'] = $productQuantity;
        $preorderData['value'] = $product->base_value * $productQuantity;
        $this->be($this->user);
        $this->json('POST', 'api/v2/preorder', $preorderData)
        ->assertJson([
            'status' => 'Orden creada con éxito',
        ])->assertStatus(200);
        $order = Order::with('orderDetails.productDetail')->orderBy('id', 'desc')->first();
        // Verifico que los datos de la orden coincidan con los datos enviados.
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'store_id' => $this->store->id,
            'spot_id' => $this->spot->id,
            'order_value' => $preorderData['value'],
            'status' => 1,
            'employee_id' => $this->employee->id,
            'cash' => 1,
            'identifier' => 0,
            'people' => $people,
            'preorder' => 1,
            'cashier_balance_id' => $cashierBalance->id,
        ]);
    }

    // Agregar productos a una preorden existente.
    public function testAddingProductsToExistingPreorder()
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
        $productQuantity = rand(1, 3);
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
        $order = Order::with('orderDetails.productDetail')->orderBy('id', 'desc')->first();
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
        $value = $value * 2;
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
        $productQuantity = rand(1, 3);
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
        $order = Order::with('orderDetails.productDetail')->orderBy('id', 'desc')->first();
        // Verifico que se hayan sumado los valores del nuevo producto a la orden.
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'store_id' => $this->store->id,
            'spot_id' => $this->spot->id,
            'order_value' => $value + $value2,
            'people' => null,
            'status' => 1,
            'employee_id' => $this->employee->id,
            'cash' => 1,
            'identifier' => 0,
            'preorder' => 1,
            'cashier_balance_id' => $cashierBalance->id,
        ]);
    }

    // No existe caja abierta, no se puede crear la preorden.
    public function testNoOpenCashierBalance()
    {
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
        $productQuantity = rand(1, 3);
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
            'status' => 'No se ha abierto caja',
        ])->assertStatus(409);
    }

    // Forzar fallo del request (no existe la tienda).
    public function testForceFail()
    {
        DB::table('sections')->where('store_id', $this->store->id)->delete();
        DB::table('store_configs')->where('store_id', $this->store->id)->delete();
        DB::table('store_configs')->where('inventory_store_id', $this->store->id)->delete();
        $this->store->delete();
        $this->be($this->user);
        $this->json('POST', 'api/v2/preorder', [])
        ->assertStatus(500);
    }
}
