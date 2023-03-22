<?php

use Faker\Generator as Faker;

$factory->define(App\Company::class, function (Faker $faker) {
    return [
        'address' => $faker->streetAddress,
        'name' => $faker->company,
        'identifier' => $faker->bothify('?#?#?#?#?#'),
        'contact' => $faker->name . ' ' . $faker->lastName,
        'TIN' => $faker->numerify('#############'),
    ];
});
