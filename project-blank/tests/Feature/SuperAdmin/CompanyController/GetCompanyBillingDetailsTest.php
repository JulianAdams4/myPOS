<?php

namespace Tests\Feature\SuperAdmin\CompanyController;

use App\Role;
use App\User;
use App\Company;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class GetCompanyBillingDetailsTest extends TestCase
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
        $company = Company::inRandomOrder()->first();
        $this->be($this->user);
        $this->json('GET', 'api/company/' . $company->id . '/billing-details')
        ->assertStatus(200);
    }

    // No puede acceder al recurso
    public function testUnauthorized()
    {
        $company = Company::inRandomOrder()->first();
        $user = User::whereHas('role', function ($role) {
            $role->where('name', '<>', Role::ADMIN);
        })->inRandomOrder()->first();
        $this->be($user);
        $this->json('GET', 'api/company/' . $company->id . '/billing-details')
        ->assertStatus(401);
    }
}
