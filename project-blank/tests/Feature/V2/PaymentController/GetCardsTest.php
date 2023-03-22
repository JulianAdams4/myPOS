<?php

namespace Tests\Feature\V2\PaymentController;

use App\Role;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class GetCardsTest extends TestCase
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
        $this->json('GET', 'api/v2/store/cards')
        ->assertJson([
            'status' => 'Tarjetas'
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
        $this->json('GET', 'api/v2/store/cards')
        ->assertJson([
            'status' => 'Usuario no autorizado.'
        ])
        ->assertStatus(401);
    }
}
