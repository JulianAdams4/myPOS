<?php

use Carbon\Carbon;
use App\Component;
use Illuminate\Database\Seeder;

class ComponentStockTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $components = Component::with('category.company.stores')->get();
        foreach ($components as $component) {
            $stores = $component->category->company->stores;
            foreach ($stores as $store) {
                if (rand(1, 10) > 7) {
                    continue;
                }
                $stock = rand(10, 1000);
                $alertStock = intval($stock / 2);
                $minStock = intval($stock / 3);
                $maxStock = intval($stock * 2);
                $idealStock = intval($stock * 1.25);
                DB::table('component_stock')->insert([
                    'store_id' => $store->id,
                    'component_id' => $component->id,
                    'stock' => $stock,
                    'created_at' => Carbon::now()->toDateTimeString(),
                    'updated_at' => Carbon::now()->toDateTimeString(),
                ]);
            }
        }
    }
}
