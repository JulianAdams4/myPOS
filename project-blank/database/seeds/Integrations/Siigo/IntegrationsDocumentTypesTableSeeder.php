<?php

use Illuminate\Database\Seeder;
use Flynsarmy\CsvSeeder\CsvSeeder;

class IntegrationsDocumentTypesTableSeeder extends CsvSeeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function __construct()
	{
        $this->table = 'integrations_document_types';
        $this->csv_delimiter = ';';
        $this->filename = base_path().'/database/seeds/Integrations/Siigo/csvs/document_types.csv';
	}

    public function run()
    {
    	// Recommended when importing larger CSVs
        DB::disableQueryLog();
		parent::run();
    }
}
