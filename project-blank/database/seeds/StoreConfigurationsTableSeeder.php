<?php

use App\StoreConfig;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class StoreConfigurationsTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $checkKeys = array(
            'id',
            'store_id',
            'created_at',
            'updated_at'
        );

        /**
         * With this seeder we can read all record from store_config and
         * with that we can migrate the data to a new table, is this case
         * in the value field exist a validation for the different data
         */
        foreach (StoreConfig::all() as $storeConfig) {
            $store_id = $storeConfig->store_id;
            foreach ($storeConfig->toArray() as $k => $v) {
                if (!in_array($k, $checkKeys)) {
                    $type = gettype($v);
                    DB::table('store_configurations')->insert([
                        'store_id' => $store_id,
                        'key' => $k,
                        'value' => is_string($v) ? (substr($v, 0, 1) == "[" || substr($v, 0, 1) == "{" ? $v : '"' . $v . '"') : '"' . strval($v) . '"',
                        'created_at' => Carbon::now()->toDateTimeString(),
                        'updated_at' => Carbon::now()->toDateTimeString(),
                    ]);
                }
            }
        }
    }
}
