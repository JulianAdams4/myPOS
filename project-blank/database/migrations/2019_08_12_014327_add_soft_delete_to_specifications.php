<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use App\Specification;
use Carbon\Carbon;

class AddSoftDeleteToSpecifications extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // $oldData = Specification::where('status', 0)->get();
        Schema::table('specifications', function (Blueprint $table) {
            $table->softDeletes();
        });
        // foreach ($oldData as $specification) {
        //     $specification->deleted_at = Carbon::now()->toDateTimeString();
        //     $specification->save();
        // }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('specifications', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
}
