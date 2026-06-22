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
        $classes = $this->loadClasses($schoolTerm);
        $rooms = $this->loadRooms($allocations);

        $eligible = $classes->filter(fn (SchoolClass $class) => $this->isEligible($class));

        $allocated = $eligible->filter(function (SchoolClass $class) use ($allocations, $rooms) {
            $roomId = $allocations[$class->id] ?? null;

            return $roomId !== null && $rooms->has((int) $roomId);
        })->values();

        $allocationRate = $this->safeRate($allocated->count(), $eligible->count());

        [$comfortZoneCount, $wasteSum, $claustrophobiaSum] = $this->computeSpatialMetrics(
            $allocated,
            $allocations,
            $rooms
        );

        $comfortZoneRate = $this->safeRate($comfortZoneCount, $allocated->count());
        $avgWaste = $this->safeAverage($wasteSum, $allocated->count());
        $avgClaustrophobia = $this->safeAverage($claustrophobiaSum, $allocated->count());

        [$blockAdherent, $blockSubject] = $this->computeBlockAdherence(
            $allocated,
            $allocations,
            $rooms
        );

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
     * Carrega as turmas do semestre com os relacionamentos necessários,
     * evitando N+1 queries.
     */
    private function loadClasses(SchoolTerm $schoolTerm): Collection
    {
        return SchoolClass::whereBelongsTo($schoolTerm)
            ->with('courseinformations')
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
     * Calcula as métricas espaciais (zona de conforto, desperdício e claustrofobia).
     *
     * @return array{0: int, 1: float, 2: float} [comfortZoneCount, wasteSum, claustrophobiaSum]
     */
    private function computeSpatialMetrics(Collection $allocated, array $allocations, Collection $rooms): array
    {
        $comfortZoneCount = 0;
        $wasteSum = 0.0;
        $claustrophobiaSum = 0.0;

        // Margens aditivas (demanda + percentual) evitam artefatos de ponto
        // flutuante que surgiriam ao usar fatores multiplicativos (ex.: 1.1).
        $epsilon = 1e-9;

        foreach ($allocated as $class) {
            $roomId = (int) $allocations[$class->id];
            $room = $rooms->get($roomId);

            $demand = (float) $class->estmtr;
            $capacity = (float) $room->assentos;

            $minMargin = $demand * ($this->comfortZoneMinPercent / 100.0);
            $maxMargin = $demand * ($this->comfortZoneMaxPercent / 100.0);
            $minComfortCapacity = $demand + $minMargin;
            $maxComfortCapacity = $demand + $maxMargin;

            if ($capacity >= $minComfortCapacity - $epsilon && $capacity <= $maxComfortCapacity + $epsilon) {
                $comfortZoneCount++;
            }

            if ($capacity > $maxComfortCapacity + $epsilon) {
                $wasteSum += $capacity - $maxComfortCapacity;
            }

            if ($capacity < $minComfortCapacity - $epsilon) {
                $claustrophobiaSum += $minComfortCapacity - $capacity;
            }
        }

        return [$comfortZoneCount, $wasteSum, $claustrophobiaSum];
    }

    /**
     * Calcula a aderência às restrições de bloco.
     *
     * Regra (régua isomórfica documentada na arquitetura):
     *  - Calouros (Graduação obrigatória do 1º/2º semestre ideal) → Bloco A.
     *  - Pós-Graduação → Bloco B.
     *
     * Turmas não sujeitas a nenhuma restrição de bloco não compõem o denominador.
     *
     * @return array{0: int, 1: int} [adherentCount, subjectCount]
     */
    private function computeBlockAdherence(Collection $allocated, array $allocations, Collection $rooms): array
    {
        $adherent = 0;
        $subject = 0;

        foreach ($allocated as $class) {
            $expectedBlock = $this->expectedBlock($class);

            if ($expectedBlock === null) {
                continue;
            }

            $subject++;

            $room = $rooms->get((int) $allocations[$class->id]);
            $actualBlock = $this->roomBlock($room);

            if ($actualBlock === $expectedBlock) {
                $adherent++;
            }
        }

        return [$adherent, $subject];
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
