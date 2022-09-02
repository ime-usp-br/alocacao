<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UpdateTitleAndBodyToObservationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('observations', function (Blueprint $table) {
            $table->string("title", 256)->change();
            $table->string("body", 8192)->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('observations', function (Blueprint $table) {
            $table->string("title")->change();
            $table->string("body", 512)->change();
        });
    }
}
