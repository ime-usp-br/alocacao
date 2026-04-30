<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddHistoricalAvgColumnsToSchoolClassesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('school_classes', function (Blueprint $table) {
            $table->timestamp('historical_avg_applied_at')->nullable()->after('estmtr');
            $table->json('historical_avg_metadata')->nullable()->after('historical_avg_applied_at');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('school_classes', function (Blueprint $table) {
            $table->dropColumn(['historical_avg_applied_at', 'historical_avg_metadata']);
        });
    }
}
