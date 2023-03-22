<?php

use Carbon\Carbon;
use App\Company;
use Illuminate\Database\Seeder;

class CompanyTaxesTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        foreach (Company::all() as $company) {
            DB::table('company_taxes')->insert([
                'company_id' => $company->id,
                'name' => 'IVA',
                'percentage' => 12.00,
                'type' => 'included',
                'enabled' => true,
                'created_at' => Carbon::now()->toDateTimeString(),
                'updated_at' => Carbon::now()->toDateTimeString(),
            ]);
        }
    }
}
