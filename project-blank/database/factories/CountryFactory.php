<?php

use Faker\Generator as Faker;

$factory->define(App\Country::class, function (Faker $faker) {
    return [
        'name' => $faker->country,
        'code' => $faker->countryCode,
    ];
});
