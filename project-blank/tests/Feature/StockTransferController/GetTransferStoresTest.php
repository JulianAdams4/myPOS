<?php

namespace Tests\Feature\StockTransferController;

use App\Role;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class GetTransferStoresTest extends TestCase
{
    use DatabaseTransactions;

    protected $user;

    public function setUp()
    {
        parent::setUp();
        $this->user = User::whereHas('role', function ($role) {
            $role->where('name', Role::ADMIN_STORE);
        })->inRandomOrder()->first();
    }

    // Envía todos los datos correctamente
    public function testSuccess()
    {
        $this->be($this->user);
        $this->json('GET', 'api/stock_transfers/get_transfer_stores')
        ->assertJson([
            'status' => 'Tiendas encontradas',
        ])->assertStatus(200);
    }

    // No puede acceder al recurso
    public function testUnauthorized()
    {
        $user = User::whereHas('role', function ($role) {
            $role->where('name', '<>', Role::ADMIN_STORE);
        })->inRandomOrder()->first();
        $this->be($user);
        $this->json('GET', 'api/stock_transfers/get_transfer_stores')
        ->assertStatus(401);
    }

    // No tiene permisos (module)
    public function testModuleNoPermission()
    {
        $this->user->modules()->where('identifier', 'stock-transfers')->delete();
        $this->be($this->user);
        $this->json('GET', 'api/stock_transfers/get_transfer_stores')
        ->assertStatus(403);
    }

    // No tiene permisos (action)
    public function testActionNoPermission()
    {
        $this->user->actions()->where('identifier', 'create-transfers')->delete();
        $this->be($this->user);
        $this->json('GET', 'api/stock_transfers/get_transfer_stores')
        ->assertStatus(403);
    }
}
