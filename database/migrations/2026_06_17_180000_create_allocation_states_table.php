<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAllocationStatesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('allocation_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_term_id')->constrained('school_terms')->cascadeOnDelete();
            $table->string('name');
            $table->json('allocations');
            $table->foreignId('solver_log_id')->nullable()->constrained('solver_logs')->nullOnDelete();
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
        Schema::dropIfExists('allocation_states');
    }
}
