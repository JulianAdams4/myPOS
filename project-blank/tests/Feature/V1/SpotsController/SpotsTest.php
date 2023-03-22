<?php

namespace Tests\Feature\V1\SpotsController;

use App\Role;
use App\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SpotsTest extends TestCase
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
                'observation' => $observation
            ],
        ])->assertStatus(200);
        $this->be($this->user);
        $this->json('GET', 'api/v1/spots')
        ->assertJson([
            'status' => 'Success'
        ])
        ->assertStatus(200);
    }

    // La caja no está abierta
    public function testCashierIsNotOpen()
    {
        $this->be($this->user);
        $this->json('GET', 'api/v1/spots')
        ->assertJson([
            'status' => 'Success',
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
        $this->json('GET', 'api/v1/spots')
        ->assertJson([
            'status' => 'Usuario no autorizado.'
        ])
        ->assertStatus(401);
    }
}
