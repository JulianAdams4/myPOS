<?php

namespace Tests\Feature\SuperAdmin\CompanyController;

use App\Role;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class GetCardsTest extends TestCase
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
        $this->be($this->user);
        $this->json('GET', 'api/cards')
        ->assertStatus(200);
    }

    // No puede acceder al recurso
    public function testUnauthorized()
    {
        $user = User::whereHas('role', function ($role) {
            $role->where('name', '<>', Role::ADMIN);
        })->inRandomOrder()->first();
        $this->be($user);
        $this->json('GET', 'api/cards')
        ->assertStatus(401);
    }
}
