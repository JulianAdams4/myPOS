<?php

use App\Checkin;
use App\CheckinType;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddDatesToCheckinsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('checkins', function (Blueprint $table) {
            $table->timestamp('checkin')->nullable();
            $table->timestamp('checkout')->nullable();
        });

        Checkin::chunk(200, function ($checkins) {
            foreach ($checkins as $checkin) {
                if (!$checkin->isEntry()) {
                    continue;
                }

                $checkin->checkin = $checkin->created_at;
                $checkin->save();
            }
        });

        Checkin::chunk(200, function ($checkins) {
            foreach ($checkins as $checkin) {
                if (!$checkin->isExit()) {
                    continue;
                }

                $lastCheckin = Checkin::where('employee_id', $checkin->employee_id)
                    ->where('id', '<', $checkin->id)
                    ->orderBy('id', 'desc')
                    ->first();

                if ($lastCheckin == null) {
                    continue;
                }

                $lastCheckin->checkout = $checkin->created_at;
                $lastCheckin->save();
            }
        });

        Checkin::whereNull('checkin')->delete();

        Schema::table('checkins', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('checkins', function (Blueprint $table) {
            $table->dropColumn('checkin');
            $table->dropColumn('checkout');
        });
    }
}
