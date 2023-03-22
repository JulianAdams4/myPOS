<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use App\Role;
use App\Permission;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class AddPermissionsToProductionSection extends Migration
{
    const TYPE = "module";
    const IDENTIFIER = "production";
    const LABEL = "Producción";

    /**
     * Run the migrations.
     */
    public function up()
    {
        // Check if exists role
        $storeAdminRole = Role::where('name', 'admin_store')->first();
        if (!$storeAdminRole) {
            // If it's not exist, run seeders manually (probably all seeders)
        } else {
            $productionStoreAdminModule = Permission::where('type', AddPermissionsToProductionSection::TYPE)
                ->where('identifier', AddPermissionsToProductionSection::IDENTIFIER)
                ->where('label', AddPermissionsToProductionSection::LABEL)
                ->first();
            if (!$productionStoreAdminModule) {
                // Run specific seeder
                Artisan::call('db:seed', ['--class' => 'StoreAdminProductionPermission']);
            }
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        $productionStoreAdminModule = Permission::where('type', AddPermissionsToProductionSection::TYPE)
            ->where('identifier', AddPermissionsToProductionSection::IDENTIFIER)
            ->where('label', AddPermissionsToProductionSection::LABEL)
            ->first();
        if ($productionStoreAdminModule) {
            $productionStoreAdminModule->del();
        }
    }
}
