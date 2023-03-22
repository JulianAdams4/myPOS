<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddExpireRefreshTypeAndScopeToStoreIntegrationTokensTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('store_integration_tokens', function (Blueprint $table) {
            $table->string('token_type')->nullable();
            $table->unsignedBigInteger('expires_in')->nullable();
            $table->string('refresh_token')->nullable();
            $table->string('scope')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('store_integration_tokens', function (Blueprint $table) {
            $table->dropColumn('token_type');
            $table->dropColumn('expires_in');
            $table->dropColumn('refresh_token');
            $table->dropColumn('scope');
        });
    }
}
