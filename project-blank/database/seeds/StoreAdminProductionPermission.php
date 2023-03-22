<?php

use App\Role;
use Carbon\Carbon;
use App\Permission;
use Illuminate\Database\Seeder;

class StoreAdminProductionPermission extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run()
    {
        $prevProductionPermission = Permission::where("type", "module")
            ->where("identifier", "production")
            ->where("label", "Producción")
            ->first();
        if (!$prevProductionPermission) {
            // Admin Store Role
            $storeAdminRole = Role::with('users')->where('name', 'admin_store')->first();
            // Permission for Production Module
            $productionPermission = [
                'role_id' => $storeAdminRole->id,
                'module_id' => null,
                'type' => "module",
                'identifier' => "production",
                'label' => "Producción",
                'created_at' => Carbon::now()->toDateTimeString(),
                'updated_at' => Carbon::now()->toDateTimeString(),
            ];
            // Create new permission
            DB::table('permissions')->insert($productionPermission);
            // Sync permissions
            $storeAdminRole->load('permissions');
            foreach ($storeAdminRole->users as $storeAdmin) {
                $storeAdmin->permissions()->sync($storeAdminRole->permissions);
            }
        }
    }
}
