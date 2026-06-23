<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddSolverConfigToComparisonReportsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('comparison_reports', function (Blueprint $table) {
            $table->json('solver_config')->nullable()->after('base_allocation_state_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('comparison_reports', function (Blueprint $table) {
            $table->dropColumn('solver_config');
        });
    }
}
