<?php

namespace Tests\Feature\SuperAdmin\StoreController;

use App\Role;
use App\User;
use App\Store;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class GetLocationsTest extends TestCase
{
    use DatabaseTransactions;

    protected $admin;

    public function setUp()
    {
        parent::setUp();
        $this->user = User::whereHas('role', function ($role) {
            $role->where('name', Role::ADMIN);
        })->inRandomOrder()->first();
    }

    // Envía todos los datos correctamente
    public function testSuccess()
    {
        $store = Store::inRandomOrder()->first();
        $this->be($this->user);
        $this->json('GET', 'api/stores/' . $store->id . '/locations')
        ->assertStatus(200);
    }

    // No puede acceder al recurso
    public function testUnauthorized()
    {
        $store = Store::inRandomOrder()->first();
        $user = User::whereHas('role', function ($role) {
            $role->where('name', '<>', Role::ADMIN);
        })->inRandomOrder()->first();
        $this->be($user);
        $this->json('GET', 'api/stores/' . $store->id . '/locations')
        ->assertStatus(401);
    }
}
