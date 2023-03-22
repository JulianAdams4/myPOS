<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateStockTransfersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        /*
        Status:
            0 - Pendiente
            1 - Aceptado
            2 - Editado
        */
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('destination_store_id');
            $table->foreign('destination_store_id')->references('id')
                    ->on('stores')->onDelete('cascade');
            $table->unsignedInteger('origin_store_id');
            $table->foreign('origin_store_id')->references('id')
                    ->on('stores')->onDelete('cascade');
            $table->unsignedInteger('processed_by_id')->index()->nullable();
            $table->unsignedInteger('destination_stock_id')->index()->nullable();
            $table->unsignedInteger('origin_stock_id');
            $table->foreign('origin_stock_id')->references('id')
                    ->on('component_stock')->onDelete('cascade');
            $table->float('quantity', 8, 2);
            $table->float('accepted_quantity', 8, 2)->nullable();
            $table->unsignedTinyInteger('status')->index()->default(0);
            $table->string('reason')->nullable();
            $table->dateTime('processed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('stock_transfers');
    }
}
