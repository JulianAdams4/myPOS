<?php

namespace Tests\Feature\V1\ProductCategoryController;

use App\Role;
use App\User;
use App\Employee;
use App\ProductCategory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ProductsCategoryTest extends TestCase
{
    use DatabaseTransactions;

    protected $user;

    public function setUp()
    {
        parent::setUp();
        $this->user = User::whereHas('role', function ($role) {
            $role->where('name', Role::EMPLOYEE);
        })->inRandomOrder()->first();
    }

    // Envía todos los datos correctamente
    public function testSuccess()
    {
        $employee = Employee::where('user_id', $this->user->id)->with('store')->first();
        $category = ProductCategory::where('company_id', $employee->store->company_id)->inRandomOrder()->first();
        $this->be($this->user);
        $this->json('GET', 'api/v1/products/category/' . $category->id)
        ->assertJson([
            'status' => 'Success'
        ])
        ->assertStatus(200);
    }

    // La categoría pertenece a otra compañía.
    public function testCategoryBelongsToAnotherCompany()
    {
        $employee = Employee::where('user_id', $this->user->id)->with('store')->first();
        $category = ProductCategory::where('company_id', '<>', $employee->store->company_id)
        ->inRandomOrder()->first();
        $this->be($this->user);
        $this->json('GET', 'api/v1/products/category/' . $category->id)
        ->assertJson([
            'status' => 'No existe la categoría.'
        ])
        ->assertStatus(404);
    }

    // La categoría pertenece a otra compañía.
    public function testCategoryDoesNotExist()
    {
        $employee = Employee::where('user_id', $this->user->id)->with('store')->first();
        $this->be($this->user);
        $this->json('GET', 'api/v1/products/category/' . 9999)
        ->assertJson([
            'status' => 'No existe la categoría.'
        ])
        ->assertStatus(404);
    }

    // No puede acceder al recurso
    public function testUnauthorized()
    {
        $user = User::whereHas('role', function ($role) {
            $role->where('name', '<>', Role::EMPLOYEE);
        })->inRandomOrder()->first();
        $category = ProductCategory::inRandomOrder()->first();
        $this->be($user);
        $this->json('GET', 'api/v1/products/category/' . $category->id)
        ->assertJson([
            'status' => 'Usuario no autorizado.'
        ])
        ->assertStatus(401);
    }
}
