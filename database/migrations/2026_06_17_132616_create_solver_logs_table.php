<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSolverLogsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('solver_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_term_id')->constrained()->onDelete('cascade');
            $table->string('job_id')->unique();
            $table->json('payload');
            $table->json('response')->nullable();
            $table->string('status', 50)->nullable();
            $table->unsignedInteger('allocations_count')->default(0);
            $table->unsignedInteger('unassigned_count')->default(0);
            $table->unsignedInteger('manual_count')->default(0);
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('responded_at')->nullable();
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
        Schema::dropIfExists('solver_logs');
    }
}
