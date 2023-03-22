<?php

namespace Tests\Feature\Store\ProductController;

use App\Component;
use App\User;
use App\Section;
use App\Employee;
use App\ProductCategory;
use App\Role;
use App\SpecificationCategory;
use App\StoreTax;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CreateTest extends TestCase
{
    use DatabaseTransactions;

    protected $user;
    protected $employee;

    public function setUp()
    {
        parent::setUp();
        $this->user = User::whereHas('role', function ($role) {
            $role->where('name', Role::ADMIN_STORE);
        })->inRandomOrder()->first();
        $this->employee = Employee::where('user_id', $this->user->id)->with('store.company')->first();
    }

    // Envía todos los datos correctamente
    public function testSuccess()
    {
        $store = $this->employee->store;
        $company = $store->company;
        $section = Section::where('store_id', $store->id)->inRandomOrder()->first();
        $category = ProductCategory::where('company_id', $company->id)
                                    ->inRandomOrder()->first();
        $items = Component::whereHas('category', function ($category) use ($company) {
            $category->where('company_id', $company->id);
        })->inRandomOrder()->take(3)->get();
        $itemsProduct = [];
        foreach ($items as $item) {
            array_push($itemsProduct, [
                'id' => $item->id,
                'consumption' => rand(1, 5)
            ]);
        }
        $specCategories = SpecificationCategory::with(['specifications' => function ($specs) {
            $specs->inRandomOrder()->take(rand(1, 3));
        }])->where('section_id', $section->id)->inRandomOrder()->take(2)->get();
        $specificationsProduct = [];
        foreach ($specCategories as $specCategory) {
            $specData = [
                'id' => $specCategory->id,
                'name' => $specCategory->name,
                'specifications' => [],
            ];
            $specs = $specCategory->specifications;
            foreach ($specs as $spec) {
                array_push($specData['specifications'], [
                    'id' => $spec->id,
                ]);
            }
            array_push($specificationsProduct, $specData);
        }
        $taxes = StoreTax::where('store_id', $store->id)->where('type', '<>', 'invoice')
                                ->inRandomOrder()->take(2)->pluck('id')->toArray();
        $taxesProduct = [];
        foreach ($taxes as $tax) {
            array_push($taxesProduct, [
                'id' => $tax,
            ]);
        }
        $data = [
            'name' => 'Test product',
            'category_id' => $category->id,
            'section_id' => $section->id,
            'price' => '10.00',
            'description' => 'Lorem ipsum',
            'sku' => 'TEST-1234',
            'itemsProduct' => $itemsProduct,
            'specificationsProduct' => $specificationsProduct,
            'taxesProduct' => $taxesProduct,
            'integrations' => [],
            'image_bitmap64' => null,
            'ask' => 1,
            'is_alcohol' => 1,
            'type_product' => 'null'
        ];
        $this->be($this->user);
        $this->json('POST', 'api/product', [
            'data' => $data
        ])->assertJson([
            'status' => 'Producto creado con éxito',
        ])->assertStatus(200);
    }

    // Intenta hacer un requerimiento con un API token inválido, deberia retornar 401.
    public function testUnauthorized()
    {
        $store = $this->employee->store;
        $company = $store->company;
        $section = Section::where('store_id', $store->id)->inRandomOrder()->first();
        $category = ProductCategory::where('company_id', $company->id)
                                    ->inRandomOrder()->first();
        $items = Component::whereHas('category', function ($category) use ($company) {
            $category->where('company_id', $company->id);
        })->inRandomOrder()->take(3)->get();
        $itemsProduct = [];
        foreach ($items as $item) {
            array_push($itemsProduct, [
                'id' => $item->id,
                'consumption' => rand(1, 5)
            ]);
        }
        $specCategories = SpecificationCategory::with(['specifications' => function ($specs) {
            $specs->inRandomOrder()->take(rand(1, 3));
        }])->where('section_id', $section->id)->inRandomOrder()->take(2)->get();
        $specificationsProduct = [];
        foreach ($specCategories as $specCategory) {
            $specData = [
                'id' => $specCategory->id,
                'name' => $specCategory->name,
                'specifications' => [],
            ];
            $specs = $specCategory->specifications;
            foreach ($specs as $spec) {
                array_push($specData['specifications'], [
                    'id' => $spec->id,
                ]);
            }
            array_push($specificationsProduct, $specData);
        }
        $taxes = StoreTax::where('store_id', $store->id)->where('type', '<>', 'invoice')
                                ->inRandomOrder()->take(2)->pluck('id')->toArray();
        $taxesProduct = [];
        foreach ($taxes as $tax) {
            array_push($taxesProduct, [
                'id' => $tax,
            ]);
        }
        $data = [
            'name' => 'Test product',
            'category_id' => $category->id,
            'section_id' => $section->id,
            'price' => '10.00',
            'description' => 'Lorem ipsum',
            'sku' => 'TEST-1234',
            'itemsProduct' => $itemsProduct,
            'specificationsProduct' => $specificationsProduct,
            'taxesProduct' => $taxesProduct,
            'integrations' => [],
            'image_bitmap64' => null,
            'ask' => 1,
            'is_alcohol' => 1,
        ];
        $this->withHeaders([
           'Authorization' => 'Bearer ' . str_random(60),
        ])->json('POST', 'api/product', [
            'data' => $data
        ])->assertStatus(401);
    }

    // Intenta crear un producto con el mismo nombre, categoría y sección que uno ya existente.
    public function testProductAlreadyExists()
    {
        $store = $this->employee->store;
        $company = $store->company;
        $category = ProductCategory::where('company_id', $company->id)
                                    ->with('section')
                                    ->inRandomOrder()->first();
        $section = $category->section;
        $items = Component::whereHas('category', function ($category) use ($company) {
            $category->where('company_id', $company->id);
        })->inRandomOrder()->take(3)->get();
        $itemsProduct = [];
        foreach ($items as $item) {
            array_push($itemsProduct, [
                'id' => $item->id,
                'consumption' => rand(1, 5)
            ]);
        }
        $specCategories = SpecificationCategory::with(['specifications' => function ($specs) {
            $specs->inRandomOrder()->take(rand(1, 3));
        }])->where('section_id', $section->id)->inRandomOrder()->take(2)->get();
        $specificationsProduct = [];
        foreach ($specCategories as $specCategory) {
            $specData = [
                'id' => $specCategory->id,
                'name' => $specCategory->name,
                'specifications' => [],
            ];
            $specs = $specCategory->specifications;
            foreach ($specs as $spec) {
                array_push($specData['specifications'], [
                    'id' => $spec->id,
                ]);
            }
            array_push($specificationsProduct, $specData);
        }
        $taxes = StoreTax::where('store_id', $store->id)->where('type', '<>', 'invoice')
                                ->inRandomOrder()->take(2)->pluck('id')->toArray();
        $taxesProduct = [];
        foreach ($taxes as $tax) {
            array_push($taxesProduct, [
                'id' => $tax,
            ]);
        }
        $data = [
            'name' => 'Test product',
            'category_id' => $category->id,
            'section_id' => $section->id,
            'price' => '10.00',
            'description' => 'Lorem ipsum',
            'sku' => 'TEST-1234',
            'itemsProduct' => $itemsProduct,
            'specificationsProduct' => $specificationsProduct,
            'taxesProduct' => $taxesProduct,
            'integrations' => [],
            'image_bitmap64' => null,
            'ask' => 1,
            'is_alcohol' => 1,
            'type_product' => 'null'
        ];
        $this->be($this->user);
        $this->json('POST', 'api/product', [
            'data' => $data
        ])->assertStatus(200);
        $this->json('POST', 'api/product', [
            'data' => $data
        ])->assertJson([
            'status' => 'Este producto ya existe',
        ])->assertStatus(409);
    }
}
