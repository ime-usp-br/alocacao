<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Uspdev\Replicado\DB as ReplicadoDB;
use App\Models\SchoolClass;
use App\Models\SchoolTerm;

class PopulatePosReplicadoKeysInSchoolClasses extends Migration
{
    /**
     * Run the migration.
     *
     * @return void
     */
    public function up()
    {
        $classes = SchoolClass::where('tiptur', 'like', 'P%')
            ->whereNull('numseqdis')
            ->whereNull('numofe')
            ->get();

        foreach ($classes as $class) {
            $dtainiofe = \Carbon\Carbon::parse($class->getAttributes()['dtainitur'])->startOfDay()->format('Y-m-d H:i:s');
            $dtafimofe = \Carbon\Carbon::parse($class->getAttributes()['dtafimtur'])->startOfDay()->format('Y-m-d H:i:s');
            $sufixoEsperado = substr($class->codtur, 5);

            $query = " SELECT D.sgldis, D.numseqdis, O.numofe";
            $query .= " FROM DISCIPLINA AS D, OFERECIMENTO AS O";
            $query .= " WHERE D.sgldis = :sgldis";
            $query .= " AND O.sgldis = D.sgldis";
            $query .= " AND O.numseqdis = D.numseqdis";
            $query .= " AND O.fmtofe = :fmtofe";
            $query .= " AND O.dtainiofe = :dtainiofe";
            $query .= " AND O.dtafimofe = :dtafimofe";

            $params = [
                'sgldis' => $class->coddis,
                'fmtofe' => 'P',
                'dtainiofe' => $dtainiofe,
                'dtafimofe' => $dtafimofe,
            ];

            $rows = ReplicadoDB::fetchAll($query, $params);

            if (count($rows) === 1) {
                $class->numseqdis = $rows[0]['numseqdis'];
                $class->numofe = $rows[0]['numofe'];
                $class->save();
            } elseif (count($rows) > 1) {
                $match = null;
                foreach ($rows as $row) {
                    if ($sufixoEsperado === $row['numseqdis'] . $row['numofe']) {
                        $match = $row;
                        break;
                    }
                }

                if ($match) {
                    $class->numseqdis = $match['numseqdis'];
                    $class->numofe = $match['numofe'];
                    $class->save();
                } else {
                    \Log::warning('PopulatePosReplicadoKeys: múltiplos oferecimentos e nenhum bate com codtur', [
                        'school_class_id' => $class->id,
                        'coddis' => $class->coddis,
                        'codtur' => $class->codtur,
                    ]);
                }
            } else {
                \Log::warning('PopulatePosReplicadoKeys: oferecimento não encontrado', [
                    'school_class_id' => $class->id,
                    'coddis' => $class->coddis,
                    'codtur' => $class->codtur,
                ]);
            }
        }
    }

    /**
     * Reverse the migration.
     *
     * @return void
     */
    public function down()
    {
        SchoolClass::where('tiptur', 'like', 'P%')
            ->update([
                'numseqdis' => null,
                'numofe' => null,
            ]);
    }
}
