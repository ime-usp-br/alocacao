<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPosReplicadoKeysToSchoolClassesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('school_classes', function (Blueprint $table) {
            $table->integer('numseqdis')->unsigned()->nullable()->after('coddis')->comment('Sequência da disciplina no Replicado (Pós-Graduação)');
            $table->integer('numofe')->unsigned()->nullable()->after('numseqdis')->comment('Número do oferecimento no Replicado (Pós-Graduação)');
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
            $table->dropColumn(['numseqdis', 'numofe']);
        });
    }
}
