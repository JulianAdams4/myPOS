<?php

use Carbon\Carbon;
use App\StoreTax;
use App\Product;
use Illuminate\Database\Seeder;

class ProductTaxesTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        foreach (Product::with('category.company.stores')->get() as $product) {
            $stores = $product->category->company->stores;
            // No tiene impuestos
            if (rand(1, 10) > 8) {
                continue;
            }
            foreach ($stores as $store) {
                $taxes = StoreTax::where('store_id', $store->id)->where('type', '!=', 'invoice')->get();
                foreach ($taxes as $tax) {
                    if ($tax->type === 'additional' && rand(1, 10) < 7) {
                        continue;
                    }

                    DB::table('product_taxes')->insert([
                        'product_id' => $product->id,
                        'store_tax_id' => $tax->id,
                        'created_at' => Carbon::now()->toDateTimeString(),
                        'updated_at' => Carbon::now()->toDateTimeString(),
                    ]);
                }
            }
        }
    }
}
