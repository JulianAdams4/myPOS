<?php

use Illuminate\Database\Seeder;

class IntegrationsPaymentMeansTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        DB::table('integrations_payment_means')->insert([
            'integration_id' => 8,
            'name_integration' => 'siigo',
            'external_payment_mean_code' => 15852,
            'local_payment_mean_code' => 0,
        ]);
        DB::table('integrations_payment_means')->insert([
            'integration_id' => 8,
            'name_integration' => 'siigo',
            'external_payment_mean_code' => 15854,
            'local_payment_mean_code' => 1,
        ]);
        DB::table('integrations_payment_means')->insert([
            'integration_id' => 8,
            'name_integration' => 'siigo',
            'external_payment_mean_code' => 15855,
            'local_payment_mean_code' => 2,
        ]);
    }
}
