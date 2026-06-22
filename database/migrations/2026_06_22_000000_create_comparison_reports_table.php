<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateComparisonReportsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('comparison_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_term_id')->constrained('school_terms')->cascadeOnDelete();
            $table->foreignId('base_allocation_state_id')->constrained('allocation_states')->cascadeOnDelete();
            $table->string('status', 20)->default('processing');
            $table->json('legacy_metrics')->nullable();
            $table->json('solver_metrics')->nullable();
            $table->json('legacy_raw_allocations')->nullable();
            $table->json('solver_raw_allocations')->nullable();
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
        Schema::dropIfExists('comparison_reports');
    }
}
