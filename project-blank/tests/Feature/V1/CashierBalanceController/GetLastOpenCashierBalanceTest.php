<?php

namespace Tests\Feature\V1\CashierBalanceController;

use Carbon\Carbon;
use App\Employee;
use App\CashierBalance;
use App\Traits\TimezoneHelper;
use App\Role;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class GetLastOpenCashierBalanceTest extends TestCase
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

    // Encuentra caja abierta
    public function testGetExistingOpenCashierBalance()
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
        ])->assertStatus(200);
        $cashierBalance = CashierBalance::where('store_id', $this->employee->store->id)
                            ->whereNull('date_close')
                            ->first();
        $this->json('GET', 'api/v1/cashier/balance')
        ->assertJson([
            'status' => 'Success',
            'results' => $cashierBalance->toArray(),
        ])->assertStatus(200);
    }

    // No encuentra caja abierta, no hay datos de la ultima caja cerrada
    public function testNoOpenCashierBalanceFound()
    {
        $now = TimezoneHelper::localizedNowDateForStore($this->employee->store);
        $lastCashierBalance = [
            "date_open" => str_replace('-', '/', $now->toDateString()),
            "hour_open" => Carbon::createFromFormat('Y-m-d H:i:s', $now)->format('H:i'),
            "value_previous_close" => '0',
            "value_open" => null,
            "observation" => "",
        ];
        $this->be($this->user);
        $this->json('GET', 'api/v1/cashier/balance')
        ->assertJson([
            'status' => 'Success',
            'results' => $lastCashierBalance,
        ])->assertStatus(200);
    }

    // No encuentra caja abierta, usa datos de la ultima caja cerrada
    public function testNoOpenCashierBalanceFoundUseLast()
    {
        $then = Carbon::now()->subHours(10);
        $dateOpen = $then->format('Y-m-d');
        $hourOpen = $then->format('H:i:s');
        $valuePreviousClose = rand(25000, 50000);
        $valueOpen = rand(0, 15000);
        $observation = 'Testing';

        $openData = [
            'date_open' => $dateOpen,
            'hour_open' => $hourOpen,
            'value_previous_close' => $valuePreviousClose,
            'value_open' => $valueOpen,
            'observation' => $observation,
        ];

        $this->be($this->user);
        $this->json('POST', 'api/v1/cashier/balance/open/day', $openData)
        ->assertJson([
            'msg' => 'Información guardada con éxito',
        ])->assertStatus(200);

        $now = Carbon::now();
        $dateClose = $now->format('Y-m-d');
        $hourClose = $now->format('H:i:s');
        $valueClose = rand(25000, 50000);
        $expenses = [
            [
                'name' => str_random(10),
                'value' => rand(100, 500),
            ],
            [
                'name' => str_random(10),
                'value' => rand(100, 500),
            ],
            [
                'name' => str_random(10),
                'value' => rand(100, 500),
            ],
        ];
        $closeData = [
            'date_close' => $dateClose,
            'hour_close' => $hourClose,
            'value_close' => $valueClose,
            'expenses' => $expenses,
            'print_balance' => true,
        ];
        $this->json('POST', 'api/v1/cashier/balance/close/day', $closeData)
        ->assertJson([
            'msg' => 'Información guardada con éxito',
            'results' => null
        ])->assertStatus(200);
        $checkOpenData = [
            'value_open' => $openData['value_open'],
            'observation' => $openData['observation']
        ];
        $this->assertDatabaseHas('cashier_balances', $checkOpenData);
        $closedData = array_merge($checkOpenData, [
            'value_close' => $valueOpen,
        ]);
        $this->assertDatabaseHas('cashier_balances', $closedData);
        $cashierBalance = CashierBalance::latest()->first();
        $totalExpenses = 0;
        foreach ($expenses as $expense) {
            $this->assertDatabaseHas('expenses_balances', [
                'cashier_balance_id' => $cashierBalance->id,
                'name' => $expense['name'],
                'value' => $expense['value'],
            ]);
            $totalExpenses += $expense['value'];
        }
        $now = TimezoneHelper::localizedNowDateForStore($this->employee->store);

        $valPreviousClose = $cashierBalance->value_close - $totalExpenses;

        if ($valPreviousClose < 0) {
            $valPreviousClose = 0;
        }

        $lastCashierBalance = [
            "date_open" => str_replace('-', '/', $now->toDateString()),
            "hour_open" => Carbon::createFromFormat('Y-m-d H:i:s', $now)->format('H:i'),
            "value_previous_close" => $valPreviousClose,
            "value_open" => null,
            "observation" => "",
        ];
        $this->json('GET', 'api/v1/cashier/balance')
        ->assertJson([
            'status' => 'Success',
            'results' => $lastCashierBalance,
        ])->assertStatus(200);
    }
}
