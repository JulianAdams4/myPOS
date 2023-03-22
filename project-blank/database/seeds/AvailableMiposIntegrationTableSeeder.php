<?php

use Illuminate\Database\Seeder;
use App\AvailableMyposIntegration;

class AvailableMyposIntegrationTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        AvailableMyposIntegration::firstOrCreate([
            'type' => 'delivery',
            'code_name' => 'uber_eats',
            'name' => 'Uber Eats',
        ]);

        AvailableMyposIntegration::firstOrCreate([
            'type' => 'delivery',
            'code_name' => 'rappi',
            'name' => 'Rappi',
            'anton_integration' => 2,
        ]);

        AvailableMyposIntegration::firstOrCreate([
            'type' => 'delivery',
            'code_name' => 'postmates',
            'name' => 'Postmates',
        ]);

        AvailableMyposIntegration::firstOrCreate([
            'type' => 'delivery',
            'code_name' => 'sin_delantal',
            'name' => 'SinDelantal',
        ]);

        AvailableMyposIntegration::firstOrCreate([
            'type' => 'delivery',
            'code_name' => 'domicilios.com',
            'name' => 'Domicilios.com',
        ]);

        AvailableMyposIntegration::firstOrCreate([
            'type' => 'delivery',
            'code_name' => 'ifood',
            'name' => 'iFood',
        ]);

        AvailableMyposIntegration::firstOrCreate([
            'type' => 'subscription_food',
            'code_name' => 'meniu',
            'name' => 'Meniu',
        ]);

        AvailableMyposIntegration::firstOrCreate([
            'type' => 'no_integration',
            'code_name' => 'delivery',
            'name' => 'Delivery',
        ]);

        AvailableMyposIntegration::firstOrCreate([
            'type' => 'pos',
            'code_name' => 'aloha',
            'name' => 'Aloha',
        ]);

        AvailableMyposIntegration::firstOrCreate([
            'type' => 'coupons',
            'code_name' => 'groupon',
            'name' => 'Groupon',
        ]);

        AvailableMyposIntegration::firstOrCreate([
            'type' => 'lets_eat',
            'code_name' => 'delivery',
            'name' => "Let's Eat",
        ]);

        AvailableMyposIntegration::firstOrCreate([
            'type' => 'backoffice',
            'code_name' => 'siigo',
            'name' => 'siigo',
        ]);

        AvailableMyposIntegration::firstOrCreate([
            'type' => 'backoffice',
            'code_name' => 'facturama',
            'name' => 'Facturama',
        ]);

        AvailableMyposIntegration::firstOrCreate([
            'type' => 'wallet',
            'code_name' => 'mercado_pago',
            'name' => 'Mercado Pago',
        ]);

        AvailableMyposIntegration::firstOrCreate([
            'type' => 'bono',
            'code_name' => 'bonos_sodexo_bigpass',
            'name' => 'Bonos Sodexo Bigpass',
        ]);

        AvailableMyposIntegration::firstOrCreate([
            'type' => 'wallet',
            'code_name' => 'exito',
            'name' => 'Exito',
        ]);
    }
}
