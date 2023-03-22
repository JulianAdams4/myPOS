<?php

use Illuminate\Database\Seeder;
use App\MetricUnit;
use App\UnitConversion;

class UnitConversionTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $litroToMililitro = 1000;
        $litroToOnzaUSA = 33.814;
        $gramoToKilogramo = 0.001;
        $onzaToGramo = 28.3495;

        $units = MetricUnit::all()->groupBy('company_id');
        foreach ($units as $companyId => $companyUnits) {
            $unitNames = $companyUnits->pluck('name')->toArray();
            $unitIds = $companyUnits->pluck('id')->toArray();
            // Conversión de Litro a Mililitro e inversa
            if ((in_array("Litros", $unitNames) || in_array("Litro", $unitNames))
                && (in_array("Mililitro", $unitNames) || in_array("Mililitros", $unitNames))
            ) {
                $indexOrigin = array_search("Litros", $unitNames) != false
                    ? array_search("Litros", $unitNames) : array_search("Litro", $unitNames);
                $indexDestination = array_search("Mililitros", $unitNames) != false
                    ? array_search("Mililitros", $unitNames) : array_search("Mililitro", $unitNames);
                $unitConversion = new UnitConversion();
                $unitConversion->unit_origin_id = $unitIds[$indexOrigin];
                $unitConversion->unit_destination_id = $unitIds[$indexDestination];
                $unitConversion->multiplier = $litroToMililitro;
                $unitConversion->save();
                $unitConversion = new UnitConversion();
                $unitConversion->unit_origin_id = $unitIds[$indexDestination];
                $unitConversion->unit_destination_id = $unitIds[$indexOrigin];
                $unitConversion->multiplier = 1 / $litroToMililitro;
                $unitConversion->save();
            }
            // Conversión de Litro a Onza e inversa
            if ((in_array("Litros", $unitNames) || in_array("Litro", $unitNames))
                && (in_array("Onzas", $unitNames))
            ) {
                $indexOrigin = array_search("Litros", $unitNames) != false
                    ? array_search("Litros", $unitNames) : array_search("Litro", $unitNames);
                $unitConversion = new UnitConversion();
                $unitConversion->unit_origin_id = $unitIds[$indexOrigin];
                $unitConversion->unit_destination_id = $unitIds[$indexDestination];
                $unitConversion->multiplier = $litroToOnzaUSA;
                $unitConversion->save();
                $unitConversion = new UnitConversion();
                $unitConversion->unit_origin_id = $unitIds[$indexDestination];
                $unitConversion->unit_destination_id = $unitIds[$indexOrigin];
                $unitConversion->multiplier = 1 / $litroToOnzaUSA;
                $unitConversion->save();
            }
            // Conversión de Mililitro a Onza e inversa
            if ((in_array("Onzas", $unitNames))
                && (in_array("Mililitro", $unitNames) || in_array("Mililitros", $unitNames))
            ) {
                $indexOrigin = array_search("Mililitros", $unitNames) != false
                    ? array_search("Mililitros", $unitNames) : array_search("Mililitro", $unitNames);
                $indexDestination = array_search("Onzas", $unitNames);
                $unitConversion = new UnitConversion();
                $unitConversion->unit_origin_id = $unitIds[$indexOrigin];
                $unitConversion->unit_destination_id = $unitIds[$indexDestination];
                $unitConversion->multiplier = (1 / $litroToMililitro) * $litroToOnzaUSA;
                $unitConversion->save();
                $unitConversion = new UnitConversion();
                $unitConversion->unit_origin_id = $unitIds[$indexDestination];
                $unitConversion->unit_destination_id = $unitIds[$indexOrigin];
                $unitConversion->multiplier = 1 / ((1 / $litroToMililitro) * $litroToOnzaUSA);
                $unitConversion->save();
            }
            // Conversión de gramos a kilogramos e inversa
            if ((in_array("Gramos", $unitNames) || in_array("Gramo", $unitNames))
                && (in_array("Kilogramos", $unitNames) || in_array("Kilogramo", $unitNames))
            ) {
                $indexOrigin = array_search("Gramos", $unitNames) != false
                    ? array_search("Gramos", $unitNames) : array_search("Gramo", $unitNames);
                $indexDestination = array_search("Kilogramos", $unitNames) != false
                    ? array_search("Kilogramos", $unitNames) : array_search("Kilogramo", $unitNames);
                $unitConversion = new UnitConversion();
                $unitConversion->unit_origin_id = $unitIds[$indexOrigin];
                $unitConversion->unit_destination_id = $unitIds[$indexDestination];
                $unitConversion->multiplier = $gramoToKilogramo;
                $unitConversion->save();
                $unitConversion = new UnitConversion();
                $unitConversion->unit_origin_id = $unitIds[$indexDestination];
                $unitConversion->unit_destination_id = $unitIds[$indexOrigin];
                $unitConversion->multiplier = 1 / $gramoToKilogramo;
                $unitConversion->save();
            }
            // Conversión de onzas a gramos e inversa
            if ((in_array("Gramos", $unitNames) || in_array("Gramo", $unitNames))
                && (in_array("Onzas", $unitNames))
            ) {
                $indexOrigin = array_search("Onzas", $unitNames);
                $indexDestination = array_search("Gramos", $unitNames) != false
                    ? array_search("Gramos", $unitNames) : array_search("Gramo", $unitNames);
                $unitConversion = new UnitConversion();
                $unitConversion->unit_origin_id = $unitIds[$indexOrigin];
                $unitConversion->unit_destination_id = $unitIds[$indexDestination];
                $unitConversion->multiplier = $onzaToGramo;
                $unitConversion->save();
                $unitConversion = new UnitConversion();
                $unitConversion->unit_origin_id = $unitIds[$indexDestination];
                $unitConversion->unit_destination_id = $unitIds[$indexOrigin];
                $unitConversion->multiplier = 1 / $onzaToGramo;
                $unitConversion->save();
            }
            // Conversión de onzas a kilogramos e inversa
            if ((in_array("Kilogramos", $unitNames) || in_array("Kilogramo", $unitNames))
                && (in_array("Onzas", $unitNames))
            ) {
                $indexOrigin = array_search("Onzas", $unitNames);
                $indexDestination = array_search("Kilogramos", $unitNames) != false
                ? array_search("Kilogramos", $unitNames) : array_search("Kilogramo", $unitNames);
                $unitConversion = new UnitConversion();
                $unitConversion->unit_origin_id = $unitIds[$indexOrigin];
                $unitConversion->unit_destination_id = $unitIds[$indexDestination];
                $unitConversion->multiplier = $onzaToGramo * $gramoToKilogramo;
                $unitConversion->save();
                $unitConversion = new UnitConversion();
                $unitConversion->unit_origin_id = $unitIds[$indexDestination];
                $unitConversion->unit_destination_id = $unitIds[$indexOrigin];
                $unitConversion->multiplier = 1 / ($onzaToGramo * $gramoToKilogramo);
                $unitConversion->save();
            }
        }
    }
}
