<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use App\Role;
use App\Permission;

class AddAdminFranchisePermissionsToPermissions extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $role  = Role::where('name', 'admin_franchise')->first();

        Permission::updateOrCreate([
            'role_id' => $role->id,
            'type' => 'module',
            'identifier' => 'manage-franchises',
            'label' => 'Franquicias'
        ]);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        $role  = Role::where('name', 'admin_franchise')->first();
        Permission::where('role_id', $role->id)->delete();
    }
}
