<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class ModifyCustomersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('customers', function (Blueprint $table) {
            //
            $table->dropForeign('customers_company_id_foreign');
            $table->dropForeign('customers_user_id_foreign');
            $table->dropColumn([
                'company_id',
                'user_id',
                'access_token',
                'provider',
                'provider_token',
                'verification_token',
                'active',
                'status'
            ]);
            $table->string('name');
            $table->string('last_name');
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('customers', function (Blueprint $table) {
            //
        });
    }
}
