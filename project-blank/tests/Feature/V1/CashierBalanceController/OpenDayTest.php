<?php

namespace Tests\Feature\V1\CashierBalanceController;

use Carbon\Carbon;
use App\Employee;
use App\Role;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class OpenDayTest extends TestCase
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

    // Envía todos los datos correctamente.
    public function testSuccessOpenDay()
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
    }

    // Usuario no autorizado intenta abrir caja.
    public function testUnauthorized()
    {
        $user = User::whereHas('role', function ($role) {
            $role->where('name', '<>', Role::EMPLOYEE);
        })->inRandomOrder()->first();
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

        $this->be($user);
        $this->json('POST', 'api/v1/cashier/balance/open/day', $data)
        ->assertStatus(401);
    }

    // La caja ya está abierta.
    public function testCashierBalanceAlreadyOpen()
    {
        $now = Carbon::now();
        $dateOpen = $now->format('Y-m-d');
        $hourOpen = $now->format('H:i:s');
        $valuePreviousClose = rand(25000, 50000);
        $valueOpen = rand(0, 15000);

        $data = [
            'date_open' => $dateOpen,
            'hour_open' => $hourOpen,
            'value_previous_close' => $valuePreviousClose,
            'value_open' => $valueOpen,
        ];

        $this->be($this->user);
        $this->json('POST', 'api/v1/cashier/balance/open/day', $data)
        ->assertJson([
            'msg' => 'Información guardada con éxito',
            'results' => [
                'value_previous_close' => 0,
                'value_open' => $valueOpen
            ],
        ])->assertStatus(200);
        $this->json('POST', 'api/v1/cashier/balance/open/day', $data)
        ->assertJson([
            'msg' => '¡La caja ya está abierta!',
        ])->assertStatus(400);
    }

    // No tiene los permisos necesarios (module).
    public function testModuleNoPermission()
    {
        $this->user->modules()->where('identifier', 'cashier-balance')->delete();
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
        ->assertStatus(403);
    }

    // No tiene los permisos necesarios (action).
    public function testActionNoPermission()
    {
        $this->user->actions()->where('identifier', 'open-cashier-balance')->delete();
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
        ->assertStatus(403);
    }
}
