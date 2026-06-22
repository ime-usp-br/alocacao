<?php

namespace App\Services;

use App\Models\SchoolClass;
use App\Models\SchoolTerm;

class ComparisonAllocationCollector
{
    /**
     * Coleta o mapa bruto [class_id => room_id] a partir do estado atual das
     * turmas no banco de dados, resolvendo o room_id com precedencia do mestre
     * de fusao (mesma regua adotada por AllocationStateService::capture).
     *
     * Esta e a UNICA politica de coleta do modulo de comparacao, garantindo
     * que ambos os motores (heuristica legada e solver CP-SAT) sejam avaliados
     * a partir de uma fonte unica de verdade — o banco de dados — eliminando
     * assimimetrismos entre os caminhos de coleta.
     *
     * @return array<int, int|null>
     */
    public function collectFromDatabase(SchoolTerm $schoolTerm): array
    {
        $allocations = [];

        $classes = SchoolClass::whereBelongsTo($schoolTerm)
            ->with(['fusion.master', 'fusion.schoolclasses'])
            ->get();

        foreach ($classes as $class) {
            $allocations[$class->id] = $this->resolveRoomId($class);
        }

        return $allocations;
    }

    /**
     * Escreve as alocacoes retornadas pelo solver diretamente em
     * `school_classes.room_id`, espelhando a politica do webhook de producao
     * (AllocationResultWebhookController).
     *
     * Para grupos de fusao, o `group_id` do solver corresponde ao `master_id`
     * da fusao; a escrita recai sobre a turma mestre, de forma que
     * `collectFromDatabase` — que da precedencia ao mestre — reconheca todas
     * as turmas filhas como alocadas. Isto elimina a assimetria que existia
     * quando o solver era avaliado diretamente do payload (onde as turmas
     * filhas de fusao apareciam como nao-alocadas).
     *
     * Grupos nao alocados (`unassigned_groups`) que ja possuem sala (travas
     * manuais do estado base) sao preservados, espelhando a heuristica
     * legada, que respeita alocacoes pre-existentes.
     *
     * @param array<int, array{group_id: int, room_id: int}> $assignments
     * @param array<int> $unassignedGroups
     * @return array{auto: int, manual: int}
     */
    public function applySolverAssignmentsToDatabase(array $assignments, array $unassignedGroups = []): array
    {
        $autoCount = 0;

        foreach ($assignments as $assignment) {
            SchoolClass::where('id', $assignment['group_id'])
                ->update(['room_id' => $assignment['room_id']]);
            $autoCount++;
        }

        $manualCount = 0;

        if (! empty($unassignedGroups)) {
            $alreadyAllocated = SchoolClass::whereIn('id', $unassignedGroups)
                ->whereNotNull('room_id')
                ->pluck('id')
                ->toArray();

            $toClear = array_diff($unassignedGroups, $alreadyAllocated);

            if (! empty($toClear)) {
                SchoolClass::whereIn('id', $toClear)->update(['room_id' => null]);
            }

            $manualCount = count($alreadyAllocated);
        }

        return ['auto' => $autoCount, 'manual' => $manualCount];
    }

    /**
     * Resolve o room_id da turma com precedencia do mestre de fusao.
     */
    private function resolveRoomId(SchoolClass $class): ?int
    {
        if ($class->fusion_id && $class->fusion) {
            $master = $class->fusion->master;

            if ($master && $master->room_id) {
                return $master->room_id;
            }

            foreach ($class->fusion->schoolclasses as $child) {
                if ($child->room_id) {
                    return $child->room_id;
                }
            }

            return null;
        }

        return $class->room_id;
    }
}
