<?php

use Carbon\Carbon;
use Illuminate\Database\Seeder;

use App\Country;
use App\City;

class CitiesEcuadorSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $cities = [
            "Guayaquil",
            "Quito",
            "Cuenca",
            "Ambato",
            "Portoviejo",
            "Machala",
            "Manta",
            "Sangolquí",
            "Esmeraldas",
            "Riobamba",
            "Ibarra",
            "Loja",
            "Milagro",
            "Latacunga",
            "Tulcán",
            "Babahoyo",
            "Azogues",
            "Chone",
            "Salinas",
            "Jipijapa",
            "Tena",
            "Cayambe",
            "Guaranda",
            "Puyo",
            "Macas",
            "San Lorenzo de Esmeraldas",
            "Piñas",
            "San Gabriel",
            "Zamora",
            "Alausí",
            "Muisne",
            "Macará",
            "Valdez",
            "Santa Cruz",
            "Puerto Baquerizo Moreno",
            "Puerto Villamil",
            "Yaupi",
            "Santa Elena",
            "Santo Domingo de los Colorados",
            "Puerto Francisco de Orellana",
            "Nueva Loja"
        ];
        $country = Country::where("name", "Ecuador")->first();
        foreach ($cities as $city) {
            $cityExist = City::where('name', $city)
                ->where('country_id', $country->id)
                ->first();

            if ($cityExist == null) {
                DB::table('cities')->insert([
                    'name' => $city,
                    'country_id' => $country->id,
                    'code' => "-",
                    'created_at' => Carbon::now()->toDateTimeString(),
                    'updated_at' => Carbon::now()->toDateTimeString(),
                ]);
            }
        }
    }
}
