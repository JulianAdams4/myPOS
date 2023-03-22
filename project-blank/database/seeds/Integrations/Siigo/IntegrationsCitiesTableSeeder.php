<?php

use Illuminate\Database\Seeder;
use Flynsarmy\CsvSeeder\CsvSeeder;

class IntegrationsCitiesTableSeeder extends CsvSeeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function __construct()
	{
        $this->table = 'integrations_cities';
        $this->csv_delimiter = ';';
        $this->filename = base_path().'/database/seeds/Integrations/Siigo/csvs/cities.csv';
	}

    public function run()
    {
    	// Recommended when importing larger CSVs
        DB::disableQueryLog();
		parent::run();
    }
}
