<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use App\SpecificationCategory;
use Carbon\Carbon;

class AddSoftDeleteToSpecificationCategories extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // $oldData = SpecificationCategory::where('status', 0)->get();
        Schema::table('specification_categories', function (Blueprint $table) {
            $table->softDeletes();
        });
        // foreach ($oldData as $specificationCategory) {
        //     $specificationCategory->deleted_at = Carbon::now()->toDateTimeString();
        //     $specificationCategory->save();
        // }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('specification_categories', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
}
