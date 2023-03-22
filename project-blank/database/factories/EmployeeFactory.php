<?php

use Faker\Generator as Faker;

$factory->define(App\Employee::class, function (Faker $faker) {
    $store = factory(App\Store::class)->create();
    return [
        'store_id' => $store->id,
        'name' => 'Employee ' . $store->name,
        'email' => $faker->email,
        'password' => bcrypt('123456'),
        'created_at' => now(),
        'updated_at' => now(),
        'type_employee' => 3,
    ];
});
