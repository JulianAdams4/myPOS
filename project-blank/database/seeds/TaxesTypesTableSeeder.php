<?php

use App\TaxesTypes;
use Illuminate\Database\Seeder;

class TaxesTypesTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        TaxesTypes::firstOrCreate([
            'country' => 'CO',
            'name' => 'IVA',
            'code' => '0',
        ]);

        TaxesTypes::firstOrCreate([
            'country' => 'CO',
            'name' => 'Retefuente',
            'code' => '1',
        ]);

        TaxesTypes::firstOrCreate([
            'country' => 'CO',
            'name' => 'ReteICA',
            'code' => '2',
        ]);

        TaxesTypes::firstOrCreate([
            'country' => 'CO',
            'name' => 'ReteIVA',
            'code' => '3',
        ]);

        TaxesTypes::firstOrCreate([
            'country' => 'CO',
            'name' => 'ImpoConsumo',
            'code' => '4',
        ]);

        TaxesTypes::firstOrCreate([
            'country' => 'MX',
            'name' => 'IVA',
            'code' => '5',
        ]);

        TaxesTypes::firstOrCreate([
            'country' => 'EC',
            'name' => 'IVA',
            'code' => '6',
        ]);
    }
}
