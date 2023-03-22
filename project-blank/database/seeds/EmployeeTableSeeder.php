<?php

use App\User;
use App\Store;
use App\Role;
use Illuminate\Support\Facades\Hash;
use Illuminate\Database\Seeder;
use Carbon\Carbon;

class EmployeeTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $role = Role::where('name', 'employee')->first();
        $faker = Faker\Factory::create();
        foreach (Store::all() as $index => $store) {
            $email = 'employee' . ($index + 1) . '@xxx.xxx';
            $name = $faker->name;
            $password = Hash::make('123456');
            DB::table('users')->insert([
                'role_id' => $role->id,
                'name' => $name,
                'email' => $email,
                'email_verified_at' => Carbon::now()->toDateTimeString(),
                'password' => $password,
                'api_token' => str_random(60),
                'active' => 1,
                'created_at' => Carbon::now()->toDateTimeString(),
                'updated_at' => Carbon::now()->toDateTimeString(),
            ]);
            $user = User::where('email', $email)->first();
            DB::table('employees')->insert([
                'store_id' => $store->id,
                'user_id' => $user->id,
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'type_employee' => 3,
                'created_at' => Carbon::now()->toDateTimeString(),
                'updated_at' => Carbon::now()->toDateTimeString(),
            ]);
        }
    }
}
