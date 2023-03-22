<?php

use App\Company;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class MetricUnitTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        foreach (Company::all() as $company) {
            if (rand(1, 10) > 3) {
                DB::table('metric_units')->insert([
                    'company_id' => $company->id,
                    'name' => 'Gramos',
                    'short_name' => 'g',
                    'status' => 1,
                    'created_at' => Carbon::now()->toDateTimeString(),
                    'updated_at' => Carbon::now()->toDateTimeString(),
                ]);
            }
            if (rand(1, 10) > 3) {
                DB::table('metric_units')->insert([
                    'company_id' => $company->id,
                    'name' => 'Unidades',
                    'short_name' => 'unidades',
                    'status' => 1,
                    'created_at' => Carbon::now()->toDateTimeString(),
                    'updated_at' => Carbon::now()->toDateTimeString(),
                ]);
            }
            if (rand(1, 10) > 7) {
                DB::table('metric_units')->insert([
                    'company_id' => $company->id,
                    'name' => 'Fundas',
                    'short_name' => 'fundas',
                    'status' => 1,
                    'created_at' => Carbon::now()->toDateTimeString(),
                    'updated_at' => Carbon::now()->toDateTimeString(),
                ]);
            }
            if (rand(1, 10) > 7) {
                DB::table('metric_units')->insert([
                    'company_id' => $company->id,
                    'name' => 'Baldes',
                    'short_name' => 'baldes',
                    'status' => 1,
                    'created_at' => Carbon::now()->toDateTimeString(),
                    'updated_at' => Carbon::now()->toDateTimeString(),
                ]);
            }
            if (rand(1, 10) > 5) {
                DB::table('metric_units')->insert([
                    'company_id' => $company->id,
                    'name' => 'Mililitros',
                    'short_name' => 'ml',
                    'status' => 1,
                    'created_at' => Carbon::now()->toDateTimeString(),
                    'updated_at' => Carbon::now()->toDateTimeString(),
                ]);
            }
            if (rand(1, 10) > 4) {
                DB::table('metric_units')->insert([
                    'company_id' => $company->id,
                    'name' => 'Litros',
                    'short_name' => 'l',
                    'status' => 1,
                    'created_at' => Carbon::now()->toDateTimeString(),
                    'updated_at' => Carbon::now()->toDateTimeString(),
                ]);
            }
            if (rand(1, 10) > 3) {
                DB::table('metric_units')->insert([
                    'company_id' => $company->id,
                    'name' => 'Kilogramos',
                    'short_name' => 'kg',
                    'status' => 1,
                    'created_at' => Carbon::now()->toDateTimeString(),
                    'updated_at' => Carbon::now()->toDateTimeString(),
                ]);
            }
            if (rand(1, 10) > 7) {
                DB::table('metric_units')->insert([
                    'company_id' => $company->id,
                    'name' => 'Piezas',
                    'short_name' => 'piezas',
                    'status' => 1,
                    'created_at' => Carbon::now()->toDateTimeString(),
                    'updated_at' => Carbon::now()->toDateTimeString(),
                ]);
            }
        }
    }
}
