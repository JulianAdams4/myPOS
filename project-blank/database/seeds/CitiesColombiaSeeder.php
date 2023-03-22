<?php

use Carbon\Carbon;
use Illuminate\Database\Seeder;

use App\Country;
use App\City;

class CitiesColombiaSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $cities = [
            "Bogotá",
            "Medellín",
            "Cali",
            "Barranquilla",
            "Bucaramanga",
            "Cartagena",
            "Cúcuta",
            "Soledad",
            "Pereira",
            "Santa Marta",
            "Ibagué",
            "Pasto",
            "Manizales",
            "Villavicencio",
            "Neiva",
            "Armenia",
            "Valledupar",
            "Montería",
            "Sincelejo",
            "Popayán",
            "Buenaventura",
            "Barrancabermeja",
            "Tuluá",
            "Tunja",
            "Cartago",
            "Ríohacha",
            "Ciénaga",
            "Florencia",
            "Girardot",
            "Sogamoso",
            "Pupiales",
            "Duitama",
            "Magangué",
            "Quibdó",
            "Tumaco",
            "Ocaña",
            "Arauca",
            "Sabanalarga",
            "Yopal",
            "El Carmen de Bolívar",
            "Leticia",
            "San Andrés",
            "Garzón",
            "El Banco",
            "Chiquinquirá",
            "Pamplona",
            "Lorica",
            "Turbo",
            "Arjona",
            "Honda",
            "Yarumal",
            "Puerto Berrío",
            "Túquerres",
            "Tame",
            "Tolú",
            "Socorro",
            "Ayapel",
            "Campoalegre",
            "San José del Guaviare",
            "Mocoa",
            "Sonsón",
            "Puerto López",
            "San Marcos",
            "Guapi",
            "Puerto Carreño",
            "Mitú",
            "Orocué",
            "Nuquí",
            "Juradó",
            "San Vicente del Caguán",
            "Inírida"

        ];
        $country = Country::where("name", "Colombia")->first();
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
