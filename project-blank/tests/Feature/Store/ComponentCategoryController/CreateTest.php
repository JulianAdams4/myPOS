<?php

namespace Tests\Feature\Store\ComponentCategoryController;

use App\ComponentCategory;
use App\Employee;
use App\Role;
use App\User;
use App\Store;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CreateTest extends TestCase
{
    use DatabaseTransactions;

    protected $store;
    protected $employee;
    protected $user;

    public function setUp()
    {
        parent::setUp();
        $this->store = Store::whereHas('configs', function ($config) {
            $config->whereNull('inventory_store_id');
        })->inRandomOrder()->first();
        $this->employee = Employee::where('store_id', $this->store->id)
        ->whereHas('user.role', function ($role) {
            $role->where('name', Role::ADMIN_STORE);
        })->with('user')->first();
        $this->user = $this->employee->user;
    }

    // Envía todos los datos correctamente
    public function testSuccess()
    {
        $data = ['name' => str_random(12)];
        $this->be($this->user);
        $this->json('POST', 'api/component/category', $data)
        ->assertJson([
            'status' => 'Categoría creada exitosamente',
        ])->assertStatus(200);
    }

    // Categoría ya existe
    public function testCategoryAlreadyExists()
    {
        $category = ComponentCategory::where('company_id', $this->store->company_id)->inRandomOrder()->first();
        $data = ['name' => $category->name];
        $this->be($this->user);
        $this->json('POST', 'api/component/category', $data)
        ->assertJson([
            'status' => 'Esta categoría ya existe',
        ])->assertStatus(400);
    }

    // Usuario no autorizado (rol)
    public function testUnauthorized()
    {
        $user = User::whereHas('role', function ($role) {
            $role->where('name', '<>', Role::ADMIN_STORE);
        })->inRandomOrder()->first();
        $data = ['name' => str_random(12)];
        $this->be($user);
        $this->json('POST', 'api/component/category', $data)
        ->assertStatus(401);
    }

    // No tiene permisos (module)
    public function testModuleNoPermission()
    {
        $this->user->modules()->where('identifier', 'inventory')->delete();
        $data = ['name' => str_random(12)];
        $this->be($this->user);
        $this->json('POST', 'api/component/category', $data)
        ->assertStatus(403);
    }

    // No tiene permisos (action)
    public function testActionNoPermission()
    {
        $this->user->actions()->where('identifier', 'create-component-category')->delete();
        $data = ['name' => str_random(12)];
        $this->be($this->user);
        $this->json('POST', 'api/component/category', $data)
        ->assertStatus(403);
    }

    public function testStoreHasCentralizedInventory()
    {
        $store = Store::whereHas('configs', function ($config) {
            $config->whereNotNull('inventory_store_id');
        })->inRandomOrder()->first();
        $employee = Employee::where('store_id', $store->id)
        ->whereHas('user.role', function ($role) {
            $role->where('name', Role::ADMIN_STORE);
        })->with('user')->first();
        $user = $employee->user;
        $data = ['name' => str_random(12)];
        $this->be($user);
        $this->json('POST', 'api/component/category', $data)
        ->assertJson([
            'status' => 'No tiene acceso a esta funcionalidad.',
        ])->assertStatus(404);
    }
}
