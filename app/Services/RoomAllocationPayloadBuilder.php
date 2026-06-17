<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Fusion;
use App\Models\SchoolClass;
use App\Models\SchoolTerm;
use Illuminate\Support\Collection;

class RoomAllocationPayloadBuilder
{
    private HistoricalEnrollmentService $historicalService;
    private bool $usesInjectedHistoricalService;

    public function __construct(?HistoricalEnrollmentService $historicalService = null)
    {
        $this->usesInjectedHistoricalService = $historicalService !== null;
        $this->historicalService = $historicalService ?? app(HistoricalEnrollmentService::class);
    }

    /**
     * Retorna o schema version esperado pelo solver.
     */
    public static function schemaVersion(): string
    {
        return '1.1.0';
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
        $roomIds = array_map('intval', $roomIds);

        if (! $this->usesInjectedHistoricalService) {
            $historicalOverrides = array_filter([
                'historical_estimation_method' => $overrides['historical_estimation_method'] ?? null,
                'historical_threshold_percent' => isset($overrides['historical_threshold_percent']) ? (float) $overrides['historical_threshold_percent'] : null,
                'historical_lookback_years' => isset($overrides['historical_lookback_years']) ? (int) $overrides['historical_lookback_years'] : null,
                'historical_min_years' => isset($overrides['historical_min_years']) ? (int) $overrides['historical_min_years'] : null,
                'historical_cap' => isset($overrides['historical_cap']) ? (int) $overrides['historical_cap'] : null,
                'historical_stddev_multiplier' => isset($overrides['historical_stddev_multiplier']) ? (float) $overrides['historical_stddev_multiplier'] : null,
            ], fn ($value) => $value !== null);

            $this->historicalService = new HistoricalEnrollmentService($historicalOverrides);
        }

        $allClasses = SchoolClass::whereBelongsTo($schoolTerm)
            ->with(['classschedules', 'fusion.master', 'fusion.master.room', 'courseinformations', 'room'])
            ->orderBy('id')
            ->get();

        $canonical = $this->canonicalize($allClasses);

        $validSuffixes = Course::all()->pluck('sufixo_codtur')->toArray();

        $groups = $this->resolveGroups($canonical, $validSuffixes);

        // Once a group has a manual allocation, it must not be forced into a
        // same-room cohort anymore; the manual room takes precedence.
        foreach ($groups as &$group) {
            if ($group['preassigned_room_id'] !== null) {
                $group['same_room_cohort'] = null;
            }
        }
        unset($group);

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

        $extraRoomIds = [];
        foreach ($finalGroups as $group) {
            $pid = $group['preassigned_room_id'];
            if ($pid !== null && ! in_array($pid, $roomIds, true)) {
                $extraRoomIds[] = $pid;
            }
        }
        $extraRoomIds = array_values(array_unique($extraRoomIds));

        $roomModels = \App\Models\Room::orderBy('id')->get();

        $rooms = [];
        foreach ($roomModels as $room) {
            $rooms[] = [
                'id' => $room->id,
                'name' => $room->nome,
                'capacity' => $room->assentos,
                'available_for_auto' => in_array($room->id, $roomIds, true),
            ];
        }

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
    private function resolveGroups(Collection $schoolClasses, array $validSuffixes): array
    {
        $solo = $schoolClasses->whereNull('fusion_id');
        $fused = $schoolClasses->whereNotNull('fusion_id')->groupBy('fusion_id');

        $groups = [];

        foreach ($solo as $class) {
            $groups[] = $this->buildSingleGroup([$class], null, $validSuffixes);
        }

        foreach ($fused as $fusionId => $classes) {
            $fusion = $classes->first()->fusion;
            $groups[] = $this->buildSingleGroup($classes->all(), $fusion, $validSuffixes);
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
    private function buildSingleGroup(array $classes, ?Fusion $fusion, array $validSuffixes): array
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

        $adjustedDemands = [];
        $hasNull = false;
        $historicalAdjustmentApplied = false;
        $historicalAdjustmentMetadata = [];

        foreach ($classes as $class) {
            if ($class->estmtr === null) {
                $hasNull = true;
                continue;
            }

            $adjusted = $this->historicalService->calculateAdjustedDemand($class);
            $adjustedDemands[] = $adjusted['demand'];

            if ($adjusted['applied']) {
                $historicalAdjustmentApplied = true;
                $metadata = $adjusted['metadata'] ?? [];
                $historicalAdjustmentMetadata[] = array_merge([
                    'class_id' => $class->id,
                    'coddis' => $class->coddis,
                    'codtur' => $class->codtur,
                    'estmtr' => $class->estmtr,
                    'adjusted_demand' => $adjusted['demand'],
                ], $metadata);
            }
        }

        $demand = array_sum($adjustedDemands);

        $timeslotLabels = $fusion
            ? $this->buildMergedTimeslotLabels($classes)
            : $this->buildSimpleTimeslotLabels($classes);

        $groupId = $fusion && $fusion->master_id ? $fusion->master_id : min($classIds);

        $cohortData = $this->resolveFreshmenCohort($classes, $validSuffixes);
        $isFreshmen = $cohortData !== null;
        $sameRoomCohort = $isFreshmen ? "cohort_{$cohortData['suffix']}_sem_{$cohortData['semester']}" : null;

        if ($fusion) {
            $rawRoomId = $fusion->master && $fusion->master->room_id
                ? $fusion->master->room_id
                : null;
            if ($rawRoomId === null) {
                foreach ($classes as $c) {
                    if ($c->room_id !== null) {
                        $rawRoomId = $c->room_id;
                        break;
                    }
                }
            }
        } else {
            $rawRoomId = $representative->room_id;
        }
        $preassignedRoomId = $rawRoomId;

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
            'historical_adjustment_applied' => $historicalAdjustmentApplied,
            'historical_adjustment_metadata' => $historicalAdjustmentMetadata ?: null,
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
