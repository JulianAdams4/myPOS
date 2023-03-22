<?php

use Faker\Generator as Faker;

$factory->define(App\City::class, function (Faker $faker) {
    $country = factory(App\Country::class)->create();
    return [
        'country_id' => $country->id,
        'name' => $faker->city,
        'code' => $faker->stateAbbr,
    ];
});
