<?php

use App\Permission;
use App\Role;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class ModuleEmployeesPermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Admin Store Modules and Actions.
        $storeAdminRole = Role::with('users')->where('name', 'admin_store')->first();
        $storeAdminModules = [
            [
                'type' => 'module',
                'identifier' => 'manage-employees',
                'label' => 'Empleados',
                'actions' => []
            ],
        ];
        foreach ($storeAdminModules as $storeAdminModule) {
            DB::table('permissions')->insert([
                'role_id' => $storeAdminRole->id,
                'module_id' => null,
                'type' => $storeAdminModule['type'],
                'identifier' => $storeAdminModule['identifier'],
                'label' => $storeAdminModule['label'],
                'created_at' => Carbon::now()->toDateTimeString(),
                'updated_at' => Carbon::now()->toDateTimeString(),
            ]);
            $newStoreAdminModule = Permission::where('type', $storeAdminModule['type'])
                ->where('identifier', $storeAdminModule['identifier'])
                ->where('label', $storeAdminModule['label'])->first();
            $actions = $storeAdminModule['actions'];
            foreach ($actions as $action) {
                DB::table('permissions')->insert([
                    'role_id' => $storeAdminRole->id,
                    'module_id' => $newStoreAdminModule->id,
                    'type' => $action['type'],
                    'identifier' => $action['identifier'],
                    'label' => $action['label'],
                    'created_at' => Carbon::now()->toDateTimeString(),
                    'updated_at' => Carbon::now()->toDateTimeString(),
                ]);
            }
        }
        $storeAdminRole->load('permissions');
        foreach ($storeAdminRole->users as $storeAdmin) {
            $storeAdmin->permissions()->sync($storeAdminRole->permissions);
        }
    }
}
