<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddColumnsForCompanysToBillingsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('billings', function (Blueprint $table) {
            $table->boolean('is_company')->nullable();
            $table->integer('company_checkdigit')->nullable();

            $table->unsignedInteger('document_type')->nullable();
            $table->foreign('document_type')->references('id')->on('integrations_document_types');
            
            $table->boolean('company_pay_iva')->nullable();
            
            $table->string('city')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('billings', function (Blueprint $table) {
            $table->dropColumn('is_company');
            $table->dropColumn('company_checkdigit');

            $table->dropForeign(['document_type']);
            $table->dropColumn('document_type');
            
            $table->dropColumn('company_pay_iva');
            
            $table->dropColumn('city');
        });
    }
}
