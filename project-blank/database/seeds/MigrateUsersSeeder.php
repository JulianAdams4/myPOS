<?php

use App\AdminStore;
use App\Employee;
use App\User;
use App\Role;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class MigrateUsersSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $roleEmployee = Role::where('name', 'employee')->first();
        foreach (Employee::all() as $employee) {
            DB::table('users')->insert([
                'role_id' => $roleEmployee->id,
                'name' => $employee->name,
                'email' => $employee->email,
                'email_verified_at' => Carbon::now()->toDateTimeString(),
                'password' => $employee->password,
                'api_token' => str_random(60),
                'active' => 1,
                'created_at' => Carbon::now()->toDateTimeString(),
                'updated_at' => Carbon::now()->toDateTimeString(),
            ]);
            $user = User::where('email', $employee->email)->first();
            $employee->user_id = $user->id;
            $employee->save();
        }
        $roleAdminStore = Role::where('name', 'admin_store')->first();
        foreach (AdminStore::all() as $store) {
            DB::table('users')->insert([
                'role_id' => $roleAdminStore->id,
                'name' => $store->name,
                'email' => $store->email,
                'email_verified_at' => Carbon::now()->toDateTimeString(),
                'password' => $store->password,
                'api_token' => str_random(60),
                'active' => 1,
                'created_at' => Carbon::now()->toDateTimeString(),
                'updated_at' => Carbon::now()->toDateTimeString(),
            ]);
            $user = User::where('email', $store->email)->first();
            DB::table('employees')->insert([
                'store_id' => $store->store->id,
                'user_id' => $user->id,
                'name' => $store->name,
                'email' => $store->email,
                'password' => $store->password,
                'type_employee' => 1,
                'created_at' => Carbon::now()->toDateTimeString(),
                'updated_at' => Carbon::now()->toDateTimeString(),
            ]);
        }
    }
}
