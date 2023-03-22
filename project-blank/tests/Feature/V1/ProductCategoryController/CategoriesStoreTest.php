<?php

namespace Tests\Feature\V1\ProductCategoryController;

use App\Role;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CategoriesStoreTest extends TestCase
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
        $this->be($this->user);
        $this->json('GET', 'api/v1/categories/store')
        ->assertJson([
            'status' => 'Success'
        ])
        ->assertStatus(200);
    }

    // No puede acceder al recurso
    public function testUnauthorized()
    {
        $user = User::whereHas('role', function ($role) {
            $role->where('name', '<>', Role::EMPLOYEE);
        })->inRandomOrder()->first();
        $this->be($user);
        $this->json('GET', 'api/v1/categories/store')
        ->assertJson([
            'status' => 'Usuario no autorizado.'
        ])
        ->assertStatus(401);
    }
}
