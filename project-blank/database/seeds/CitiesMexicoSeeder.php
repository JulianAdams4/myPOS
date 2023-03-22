<?php

use Carbon\Carbon;
use Illuminate\Database\Seeder;

use App\Country;
use App\City;

class CitiesMexicoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $cities = [
            "Guadalajara",
            "Monterrey",
            "Puebla",
            "Tijuana",
            "Toluca",
            "León de los Aldama",
            "Ciudad Juárez",
            "Torreon",
            "Ciudad Nezahualcóyotl",
            "San Luis Potosí",
            "Mérida",
            "Querétaro",
            "Mexicali",
            "Aguascalientes",
            "Tampico",
            "Cuernavaca",
            "Culiacán",
            "Chihuahua",
            "Saltillo",
            "Acapulco de Juárez",
            "Morelia",
            "Hermosillo",
            "Veracruz",
            "Cancún",
            "Matamoros",
            "Oaxaca",
            "Tlaxcala",
            "Mazatán",
            "Reynosa",
            "Durango",
            "Xalapa",
            "Villahermosa",
            "Gomez Palacio",
            "Celaya",
            "Mazatlán",
            "Orizaba",
            "Nuevo Laredo",
            "Irapuato",
            "Pachuca",
            "Tepic",
            "Ciudad Obregón",
            "Coatzacoalcos",
            "Ciudad Victoria",
            "Uruapan",
            "Ensenada",
            "Poza Rica de Hidalgo",
            "Zumpango",
            "Los Mochis",
            "Tehuacán",
            "Monclova",
            "Zacatecas",
            "Colima",
            "Tapachula",
            "Córdoba",
            "Zamora",
            "Campeche",
            "Minatitlan",
            "Salamanca",
            "Ciudad Madero",
            "La Paz",
            "Puerto Vallarta",
            "San Cristóbal de las Casas",
            "Chilpancingo",
            "Nogales",
            "Teziutlan",
            "Ciudad Lázaro Cárdenas",
            "Ciudad del Carmen",
            "Chetumal",
            "San Juan del Río",
            "Ejido Piedras Negras",
            "Navojoa",
            "Ciudad Valles",
            "Delicias",
            "Guanajuato",
            "Manzanillo",
            "Tuxpilla",
            "Escuintla",
            "El Fresno",
            "Heroica Guaymas",
            "Guasavito"
        ];
        $country = Country::where("name", "México")->first();
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
