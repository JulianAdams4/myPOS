<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use App\ProductCategory;
use Carbon\Carbon;

class AddSoftDeleteToProductCategoriesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->softDeletes();
        });
        $oldData = ProductCategory::where('status', 0)->get();
        foreach ($oldData as $productCategory) {
            $productCategory->deleted_at = Carbon::now()->toDateTimeString();
            $productCategory->save();
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
}
