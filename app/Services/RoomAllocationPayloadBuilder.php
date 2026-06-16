<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Fusion;
use App\Models\SchoolClass;
use App\Models\SchoolTerm;
use Illuminate\Support\Collection;

class RoomAllocationPayloadBuilder
{
    /**
     * Retorna o schema version esperado pelo solver.
     */
    public static function schemaVersion(): string
    {
        return '1.0.0';
    }

    /**
     * Constroi o payload JSON para o microservico Python OR-Tools.
     *
     * @param SchoolTerm $schoolTerm Semestre letivo alvo.
     * @param array<int> $roomIds IDs das salas disponiveis para alocacao.
     * @param array<string, mixed> $overrides Sobrescritas para o bloco config (opcional).
     * @return array<string, mixed> Array estruturado pronto para json_encode.
     */
    public function build(SchoolTerm $schoolTerm, array $roomIds, array $overrides = []): array
    {
        $allClasses = SchoolClass::whereBelongsTo($schoolTerm)
            ->with(['classschedules', 'fusion', 'courseinformations', 'room'])
            ->orderBy('id')
            ->get();

        $canonical = $this->canonicalize($allClasses);

        $validSuffixes = Course::all()->pluck('sufixo_codtur')->toArray();

        $groups = $this->resolveGroups($canonical, $validSuffixes, $roomIds);

        $timeslots = $this->buildTimeslotCatalog($groups);

        $timeslotIndexMap = [];
        foreach ($timeslots as $index => $ts) {
            $timeslotIndexMap[$ts['label']] = $index;
        }

        $finalGroups = [];
        foreach ($groups as $group) {
            $group['timeslot_ids'] = array_map(
                fn ($label) => $timeslotIndexMap[$label],
                $group['timeslot_labels']
            );
            sort($group['timeslot_ids']);
            unset($group['timeslot_labels']);
            $finalGroups[] = $group;
        }

        usort($finalGroups, fn ($a, $b) => $a['id'] <=> $b['id']);

        $rooms = \App\Models\Room::whereIn('id', $roomIds)
            ->orderBy('id')
            ->get()
            ->map(fn ($room) => [
                'id' => $room->id,
                'name' => $room->nome,
                'capacity' => $room->assentos,
            ])
            ->values()
            ->all();

        return [
            'meta' => $this->buildMeta($schoolTerm),
            'config' => $this->buildConfig($overrides),
            'timeslots' => $timeslots,
            'rooms' => $rooms,
            'groups' => $finalGroups,
        ];
    }

    /**
     * Aplica filtros de escopo e ordenacao deterministica.
     *
     * @param Collection $classes
     * @return Collection
     */
    private function canonicalize(Collection $classes): Collection
    {
        return $classes
            ->reject(fn ($class) => $class->externa)
            ->reject(fn ($class) => $class->coddis === 'MAE0116')
            ->sortBy('id')
            ->values();
    }

    /**
     * Agrupa turmas por fusion_id. Turmas sem fusao viram grupos solo.
     *
     * @param Collection $schoolClasses
     * @return array
     */
    private function resolveGroups(Collection $schoolClasses, array $validSuffixes, array $availableRoomIds): array
    {
        $solo = $schoolClasses->whereNull('fusion_id');
        $fused = $schoolClasses->whereNotNull('fusion_id')->groupBy('fusion_id');

        $groups = [];

        foreach ($solo as $class) {
            $groups[] = $this->buildSingleGroup([$class], null, $validSuffixes, $availableRoomIds);
        }

        foreach ($fused as $fusionId => $classes) {
            $fusion = $classes->first()->fusion;
            $groups[] = $this->buildSingleGroup($classes->all(), $fusion, $validSuffixes, $availableRoomIds);
        }

        return $groups;
    }

    /**
     * Resolve se o grupo é composto por calouros do IME e retorna
     * os dados do cohort, se aplicável.
     *
     * @param array $classes
     * @param array $validSuffixes
     * @return array|null ['suffix' => string, 'semester' => string] ou null
     */
    private function resolveFreshmenCohort(array $classes, array $validSuffixes): ?array
    {
        foreach ($classes as $class) {
            if ($class->tiptur !== 'Graduação') {
                continue;
            }

            $suffix = substr($class->codtur, -2);

            if (! in_array($suffix, $validSuffixes, true)) {
                continue;
            }

            foreach ($class->courseinformations as $ci) {
                if ($ci->tipobg === 'O' && in_array($ci->numsemidl, ['1', '2'], true)) {
                    return ['suffix' => $suffix, 'semester' => $ci->numsemidl];
                }
            }
        }

        return null;
    }

    /**
     * Constroi o descriptor de um grupo (single ou fusion).
     *
     * @param array $classes
     * @param Fusion|null $fusion
     * @return array
     */
    private function buildSingleGroup(array $classes, ?Fusion $fusion, array $validSuffixes, array $availableRoomIds): array
    {
        usort($classes, fn ($a, $b) => $a->id <=> $b->id);

        $classIds = array_map(fn ($c) => $c->id, $classes);
        $representative = $classes[0];

        $tiptur = 'Pós Graduação';
        foreach ($classes as $class) {
            if ($class->tiptur === 'Graduação') {
                $tiptur = 'Graduação';
                break;
            }
        }

        $estmtrs = array_map(fn ($c) => $c->estmtr, $classes);
        $hasNull = in_array(null, $estmtrs, true);
        $validEstmtrs = array_filter($estmtrs, fn ($v) => $v !== null);
        $demand = array_sum($validEstmtrs);

        $timeslotLabels = $fusion
            ? $this->buildMergedTimeslotLabels($classes)
            : $this->buildSimpleTimeslotLabels($classes);

        $groupId = $fusion && $fusion->master_id ? $fusion->master_id : min($classIds);

        $cohortData = $this->resolveFreshmenCohort($classes, $validSuffixes);
        $isFreshmen = $cohortData !== null;
        $sameRoomCohort = $isFreshmen ? "cohort_{$cohortData['suffix']}_sem_{$cohortData['semester']}" : null;

        $rawRoomId = $fusion && $fusion->master ? $fusion->master->room_id : $representative->room_id;
        $preassignedRoomId = ($rawRoomId !== null && in_array($rawRoomId, $availableRoomIds, true))
            ? $rawRoomId
            : null;

        return [
            'id' => $groupId,
            'type' => $fusion ? 'fusion' : 'single',
            'class_ids' => $classIds,
            'coddis' => $representative->coddis,
            'codtur' => $representative->codtur,
            'nomdis' => $representative->nomdis,
            'tiptur' => $tiptur,
            'demand' => $demand,
            'has_null_enrollment' => $hasNull,
            'timeslot_labels' => $timeslotLabels,
            'preassigned_room_id' => $preassignedRoomId,
            'same_room_cohort' => $sameRoomCohort,
            'is_freshmen' => $isFreshmen,
        ];
    }

    /**
     * Constroi o catalogo global de timeslots unicos.
     *
     * @param array $groups
     * @return array
     */
    private function buildTimeslotCatalog(array $groups): array
    {
        $labels = [];
        foreach ($groups as $group) {
            foreach ($group['timeslot_labels'] as $label) {
                $labels[$label] = true;
            }
        }

        $allLabels = array_keys($labels);
        sort($allLabels);

        $timeslots = [];
        foreach ($allLabels as $index => $label) {
            $parts = explode('_', $label);
            $timeslots[] = [
                'id' => $index,
                'label' => $label,
                'day' => $parts[0],
                'start' => substr($parts[1], 0, 2) . ':' . substr($parts[1], 2, 2),
                'end' => substr($parts[2], 0, 2) . ':' . substr($parts[2], 2, 2),
            ];
        }

        return $timeslots;
    }

    /**
     * Canoniza um horario em label string deterministico.
     *
     * @param string $day
     * @param string $start
     * @param string $end
     * @return string
     */
    private function timeslotToLabel(string $day, string $start, string $end): string
    {
        $startClean = str_replace(':', '', $start);
        $endClean = str_replace(':', '', $end);
        return "{$day}_{$startClean}_{$endClean}";
    }

    /**
     * Coleta labels de horario sem merge (usado para turmas solo).
     *
     * @param array $classes
     * @return array
     */
    private function buildSimpleTimeslotLabels(array $classes): array
    {
        $timeslotLabels = [];
        foreach ($classes as $class) {
            foreach ($class->classschedules as $schedule) {
                $label = $this->timeslotToLabel(
                    $schedule->diasmnocp,
                    $schedule->horent,
                    $schedule->horsai
                );
                $timeslotLabels[$label] = true;
            }
        }
        $timeslotLabels = array_keys($timeslotLabels);
        sort($timeslotLabels);

        return $timeslotLabels;
    }

    /**
     * Coleta e mescla labels de horario para fusoes.
     *
     * Agrupa por dia da semana, ordena por inicio e mescla intervalos
     * que se sobrepõem ou sao contiguos.
     *
     * @param array $classes
     * @return array
     */
    private function buildMergedTimeslotLabels(array $classes): array
    {
        $byDay = [];
        foreach ($classes as $class) {
            foreach ($class->classschedules as $schedule) {
                $day = $schedule->diasmnocp;
                $start = (int) str_replace(':', '', $schedule->horent);
                $end = (int) str_replace(':', '', $schedule->horsai);

                $byDay[$day][] = [
                    'start' => $start,
                    'end' => $end,
                    'labelStart' => str_replace(':', '', $schedule->horent),
                    'labelEnd' => str_replace(':', '', $schedule->horsai),
                ];
            }
        }

        $timeslotLabels = [];
        foreach ($byDay as $day => $intervals) {
            usort($intervals, fn ($a, $b) => $a['start'] <=> $b['start']);

            $merged = [];
            foreach ($intervals as $interval) {
                if (empty($merged)) {
                    $merged[] = $interval;
                    continue;
                }

                $last = &$merged[count($merged) - 1];
                if ($interval['start'] <= $last['end']) {
                    if ($interval['end'] > $last['end']) {
                        $last['end'] = $interval['end'];
                        $last['labelEnd'] = $interval['labelEnd'];
                    }
                } else {
                    $merged[] = $interval;
                }
            }

            foreach ($merged as $m) {
                $timeslotLabels["{$day}_{$m['labelStart']}_{$m['labelEnd']}"] = true;
            }
        }

        $timeslotLabels = array_keys($timeslotLabels);
        sort($timeslotLabels);

        return $timeslotLabels;
    }

    /**
     * Monta o bloco de configuracao do solver.
     *
     * @param array $overrides
     * @return array
     */
    private function buildConfig(array $overrides): array
    {
        $defaults = config('alocacao.room_allocation', []);
        return array_merge($defaults, $overrides);
    }

    /**
     * Monta o bloco de metadados do payload.
     *
     * @param SchoolTerm $schoolTerm
     * @return array
     */
    private function buildMeta(SchoolTerm $schoolTerm): array
    {
        return [
            'version' => self::schemaVersion(),
            'school_term_id' => $schoolTerm->id,
            'generated_at' => now()->toIso8601String(),
            'builder_class' => self::class,
        ];
    }
}
