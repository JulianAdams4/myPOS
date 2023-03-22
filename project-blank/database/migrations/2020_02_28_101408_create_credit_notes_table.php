<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateCreditNotesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('store_configs', function (Blueprint $table) {
            $table->dropColumn('allow_revoke_orders');
        });
        Schema::create('credit_notes', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('credit_sequence')->default(1);
            $table->unsignedInteger('order_id')->nullable(); // Order has Billing ID
            $table->unsignedInteger('company_id')->nullable(false); // For checking company sequence
            $table->decimal('value', 14, 4);
            $table->enum('type', ['total','partial'])->default('total');
            $table->boolean('consume_inventory')->default(false);
            $table->text('observations')->nullable(false);
            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('orders');
            $table->foreign('company_id')->references('id')->on('companies');
            $table->unique(['credit_sequence', 'company_id']); // Unique by company
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('store_configs', function (Blueprint $table) {
            $table->boolean('allow_revoke_orders')->default(false);
        });
        Schema::dropIfExists('credit_notes');
    }
}
