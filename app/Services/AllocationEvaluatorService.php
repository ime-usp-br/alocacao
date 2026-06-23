<?php

namespace App\Services;

use App\Models\Room;
use App\Models\SchoolClass;
use App\Models\SchoolTerm;
use Illuminate\Support\Collection;

class AllocationEvaluatorService
{
    private float $comfortZoneMinPercent;
    private float $comfortZoneMaxPercent;

    public function __construct()
    {
        $config = config('alocacao.room_allocation', []);

        $this->comfortZoneMinPercent = (float) ($config['comfort_zone_min_percent'] ?? 10.0);
        $this->comfortZoneMaxPercent = (float) ($config['comfort_zone_max_percent'] ?? 25.0);
    }

    /**
     * Calcula os KPIs de avaliação para um mapa bruto de alocações.
     *
     * O serviço é puro (stateless): não realiza nenhuma escrita no banco de dados.
     * Apenas lê as instâncias de SchoolClass e Room pertinentes ao semestre e
     * processa o resultado em memória.
     *
     * @param SchoolTerm $schoolTerm Semestre letivo avaliado.
     * @param array<int, int|null> $allocations Mapa [school_class_id => room_id].
     * @param float|null $solveTimeSeconds Tempo de resolução (opcional).
     * @return array{
     *     allocation_rate: float,
     *     comfort_zone_rate: float,
     *     avg_waste_per_class: float,
     *     avg_claustrophobia_per_class: float,
     *     block_adherence_rate: float,
     *     solve_time_seconds: float|null
     * }
     */
    public function evaluate(SchoolTerm $schoolTerm, array $allocations, ?float $solveTimeSeconds = null): array
    {
        $breakdown = $this->breakdown($schoolTerm, $allocations);

        $eligibleCount = count($breakdown);

        $allocated = array_filter($breakdown, fn ($r) => $r['allocated']);
        $allocatedCount = count($allocated);

        $allocationRate = $this->safeRate($allocatedCount, $eligibleCount);

        $comfortZoneCount = 0;
        $wasteSum = 0.0;
        $claustrophobiaSum = 0.0;
        foreach ($allocated as $r) {
            if ($r['in_comfort_zone']) {
                $comfortZoneCount++;
            }
            $wasteSum += $r['waste'];
            $claustrophobiaSum += $r['claustrophobia'];
        }

        $comfortZoneRate = $this->safeRate($comfortZoneCount, $allocatedCount);
        $avgWaste = $this->safeAverage($wasteSum, $allocatedCount);
        $avgClaustrophobia = $this->safeAverage($claustrophobiaSum, $allocatedCount);

        $blockAdherent = 0;
        $blockSubject = 0;
        foreach ($allocated as $r) {
            if ($r['expected_block'] === null) {
                continue;
            }
            $blockSubject++;
            if ($r['actual_block'] === $r['expected_block']) {
                $blockAdherent++;
            }
        }

        $blockAdherenceRate = $this->safeRate($blockAdherent, $blockSubject);

        return [
            'allocation_rate' => $allocationRate,
            'comfort_zone_rate' => $comfortZoneRate,
            'avg_waste_per_class' => $avgWaste,
            'avg_claustrophobia_per_class' => $avgClaustrophobia,
            'block_adherence_rate' => $blockAdherenceRate,
            'solve_time_seconds' => $solveTimeSeconds,
        ];
    }

    /**
     * Retorna o breakdown por unidade de alocação elegível (stateless, sem
     * escrita no DB).
     *
     * Uma unidade de alocação é uma turma standalone (sem fusão) OU uma
     * dobradinha inteira (fusão) — esta última conta como 1 registro, com
     * demanda = soma dos estmtr das turmas filhas, comparada uma única vez
     * contra a capacidade da sala compartilhada. Isto espelha o modelo de
     * grupos do solver (RoomAllocationPayloadBuilder::resolveGroups) e a
     * contagem do legado (ProcessLegacyRoomDistribution::countTotalAllocationUnits),
     * eliminando a contagem múltipla de dobradinhas que inflava
     * artificialmente os KPIs e distorcia waste/claustrophobia/comfort zone.
     *
     * O identificador do registro (class_id) é o id da turma mestre da fusão
     * (fusion.master_id), o mesmo group_id usado pelo solver e a chave pela
     * qual o mapa de alocações (coletado com precedência do mestre) resolve a
     * sala. Turmas sem fusão usam o próprio id. Isso garante que a análise
     * pareada (intersect por class_id) case legado e solver para a mesma
     * dobradinha.
     *
     * @return array<int, array{
     *     class_id: int,
     *     allocated: bool,
     *     demand: float|null,
     *     capacity: float|null,
     *     occupancy_ratio: float|null,
     *     waste: float,
     *     claustrophobia: float,
     *     in_comfort_zone: bool,
     *     expected_block: string|null,
     *     actual_block: string|null,
     *     room_id: int|null,
     * }>
     */
    public function breakdown(SchoolTerm $schoolTerm, array $allocations): array
    {
        $classes = $this->loadClasses($schoolTerm);
        $rooms = $this->loadRooms($allocations);

        $epsilon = 1e-9;
        $result = [];

        $nonExternal = $classes->filter(fn (SchoolClass $class) => ! $class->externa);

        $solo = $nonExternal->filter(fn (SchoolClass $class) => $class->fusion_id === null);
        $fused = $nonExternal->filter(fn (SchoolClass $class) => $class->fusion_id !== null)
            ->groupBy('fusion_id');

        foreach ($solo as $class) {
            if (! $this->isEligible($class)) {
                continue;
            }

            $result[] = $this->buildRecord($class, (float) $class->estmtr, $allocations, $rooms, $epsilon);
        }

        foreach ($fused as $children) {
            $fusion = $children->first()->fusion;
            $masterId = $fusion && $fusion->master_id
                ? (int) $fusion->master_id
                : (int) $children->min('id');

            $demand = 0.0;
            foreach ($children as $child) {
                if ($child->estmtr !== null && $child->estmtr > 0) {
                    $demand += (float) $child->estmtr;
                }
            }

            if ($demand <= 0.0) {
                continue;
            }

            $representative = $children->firstWhere('id', $masterId)
                ?? $children->sortBy('id')->first();

            $result[] = $this->buildRecord($representative, $demand, $allocations, $rooms, $epsilon, $masterId);
        }

        return $result;
    }

    /**
     * Constrói um registro do breakdown para uma unidade de alocação.
     *
     * @param SchoolClass $class Turma representante (a própria para solo, o
     *                           mestre para fusões) — usada para bloco esperado.
     * @param float $demand Demanda da unidade (estmtr da turma ou soma das
     *                       filhas da dobradinha).
     * @param array<int, int|null> $allocations Mapa [class_id => room_id].
     * @param Collection<int, Room> $rooms Salas indexadas por id.
     * @param float $epsilon Tolerância numérica.
     * @param int|null $recordId Id do registro (master_id para fusões; null
     *                            usa o id da turma representante).
     */
    private function buildRecord(SchoolClass $class, float $demand, array $allocations, Collection $rooms, float $epsilon, ?int $recordId = null): array
    {
        $classId = $recordId ?? $class->id;

        $roomId = $allocations[$classId] ?? null;
        $allocated = $roomId !== null && $rooms->has((int) $roomId);

        $capacity = null;
        $occupancyRatio = null;
        $waste = 0.0;
        $claustrophobia = 0.0;
        $inComfortZone = false;
        $actualBlock = null;

        if ($allocated) {
            $room = $rooms->get((int) $roomId);
            $capacity = (float) $room->assentos;
            $occupancyRatio = $capacity > 0 ? ($demand / $capacity) : null;

            $minMargin = $demand * ($this->comfortZoneMinPercent / 100.0);
            $maxMargin = $demand * ($this->comfortZoneMaxPercent / 100.0);
            $minComfortCapacity = $demand + $minMargin;
            $maxComfortCapacity = $demand + $maxMargin;

            if ($capacity >= $minComfortCapacity - $epsilon && $capacity <= $maxComfortCapacity + $epsilon) {
                $inComfortZone = true;
            }

            if ($capacity > $maxComfortCapacity + $epsilon) {
                $waste = $capacity - $maxComfortCapacity;
            }

            if ($capacity < $minComfortCapacity - $epsilon) {
                $claustrophobia = $minComfortCapacity - $capacity;
            }

            $actualBlock = $this->roomBlock($room);
        }

        return [
            'class_id' => $classId,
            'allocated' => $allocated,
            'demand' => $demand,
            'capacity' => $capacity,
            'occupancy_ratio' => $occupancyRatio,
            'waste' => $waste,
            'claustrophobia' => $claustrophobia,
            'in_comfort_zone' => $inComfortZone,
            'expected_block' => $this->expectedBlock($class),
            'actual_block' => $actualBlock,
            'room_id' => $allocated ? (int) $roomId : null,
        ];
    }

    /**
     * Carrega as turmas do semestre com os relacionamentos necessários,
     * evitando N+1 queries.
     */
    private function loadClasses(SchoolTerm $schoolTerm): Collection
    {
        return SchoolClass::whereBelongsTo($schoolTerm)
            ->with(['courseinformations', 'fusion'])
            ->orderBy('id')
            ->get()
            ->keyBy('id');
    }

    /**
     * Carrega as salas referenciadas pelo mapa de alocações em uma única query.
     */
    private function loadRooms(array $allocations): Collection
    {
        $roomIds = collect($allocations)
            ->filter(fn ($roomId) => $roomId !== null)
            ->map(fn ($roomId) => (int) $roomId)
            ->unique()
            ->values()
            ->all();

        if (empty($roomIds)) {
            return collect();
        }

        return Room::whereIn('id', $roomIds)->get()->keyBy('id');
    }

    /**
     * Uma turma é elegível para alocação quando possui demanda (estmtr > 0)
     * e não é externa (turmas externas não são alocáveis pelo sistema).
     */
    private function isEligible(SchoolClass $class): bool
    {
        return ! $class->externa && $class->estmtr !== null && $class->estmtr > 0;
    }

    /**
     * Determina o bloco esperado para a turma, ou null quando ela não está
     * sujeita a restrição geográfica.
     */
    private function expectedBlock(SchoolClass $class): ?string
    {
        if ($class->tiptur === 'Pós Graduação') {
            return 'B';
        }

        if ($class->tiptur === 'Graduação' && $this->isFreshman($class)) {
            return 'A';
        }

        return null;
    }

    /**
     * Identifica calouros pela mesma régua do payload builder: Graduação com
     * course_information obrigatória (tipobg = 'O') do 1º ou 2º semestre ideal.
     */
    private function isFreshman(SchoolClass $class): bool
    {
        foreach ($class->courseinformations as $ci) {
            if ($ci->tipobg === 'O' && in_array($ci->numsemidl, ['1', '2'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extrai o bloco da sala a partir da primeira letra do nome (ex.: "A101" => "A").
     */
    private function roomBlock(Room $room): ?string
    {
        $name = trim($room->nome);

        if ($name === '') {
            return null;
        }

        $letter = strtoupper($name[0]);

        return in_array($letter, ['A', 'B'], true) ? $letter : null;
    }

    private function safeRate(int $numerator, int $denominator): float
    {
        if ($denominator <= 0) {
            return 0.0;
        }

        return ($numerator / $denominator) * 100.0;
    }

    private function safeAverage(float $sum, int $count): float
    {
        if ($count <= 0) {
            return 0.0;
        }

        return $sum / $count;
    }
}
