<?php

use App\Company;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class CompanyElectronicBillingDetailsTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $faker = Faker\Factory::create();
        foreach (Company::all() as $company) {
            DB::table('company_electronic_billing_details')->insert([
                'company_id' => $company->id,
                'data_for' => rand(1, 10) > 7 ? 'datil' : 'physical',
                'env_prod' => 1,
                'accounting_needed' => 0,
                'business_name' => $company->name,
                'tradename' => $company->name,
                'address' => $faker->address,
                'created_at' => Carbon::now()->toDateTimeString(),
                'updated_at' => Carbon::now()->toDateTimeString(),
            ]);
        }
    }
}
