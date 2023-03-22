<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use App\Product;
use Carbon\Carbon;

class AddSoftDeleteToProductsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('products', function (Blueprint $table) {
            $table->softDeletes();
        });
        $oldData = Product::where('status', 0)->get();
        foreach ($oldData as $product) {
            $product->deleted_at = Carbon::now()->toDateTimeString();
            $product->save();
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
}
