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

    public function __construct(array $config = [])
    {
        if (empty($config)) {
            $config = config('alocacao.room_allocation', []);
        }

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
        $minCapacities = self::minCapacitiesByBlock($rooms->keys()->all());

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

            $result[] = $this->buildRecord($class, (float) $class->estmtr, $allocations, $rooms, $epsilon, null, $this->expectedBlock($class), $minCapacities);
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

            $result[] = $this->buildRecord($representative, $demand, $allocations, $rooms, $epsilon, $masterId, $this->expectedBlockForGroup($children), $minCapacities);
        }

        return $result;
    }

    /**
     * Constrói um registro do breakdown para uma unidade de alocação.
     *
     * @param SchoolClass $class Turma representante (a própria para solo, o
     *                           mestre para fusões).
     * @param float $demand Demanda da unidade (estmtr da turma ou soma das
     *                       filhas da dobradinha).
     * @param array<int, int|null> $allocations Mapa [class_id => room_id].
     * @param Collection<int, Room> $rooms Salas indexadas por id.
     * @param float $epsilon Tolerância numérica.
     * @param int|null $recordId Id do registro (master_id para fusões; null
     *                            usa o id da turma representante).
     * @param string|null $expectedBlock Bloco de preferência da unidade (já
     *                                    computado considerando todas as filhas
     *                                    da fusão); null usa a turma represen-
     *                                    tante.
     * @param array{A: float|null, B: float|null} $minCapacities Menor capacidade
     *                                                            de cada bloco
     *                                                            entre as salas
     *                                                            referenciadas.
     */
    private function buildRecord(SchoolClass $class, float $demand, array $allocations, Collection $rooms, float $epsilon, ?int $recordId = null, ?string $expectedBlock = null, array $minCapacities = ['A' => null, 'B' => null]): array
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

            // A zona de conforto representa a faixa de FOLGA (assentos livres)
            // em relação à capacidade da sala.
            // min_percent = folga mínima exigida (ex: 10% livres = 90% ocupação)
            // max_percent = folga máxima permitida (ex: 50% livres = 50% ocupação)
            $minFolga = $this->comfortZoneMinPercent / 100.0;
            $maxFolga = $this->comfortZoneMaxPercent / 100.0;

            // Capacidade no limite de folga mínima (mais cheia permitida)
            $minComfortCapacity = $demand / (1.0 - $minFolga);
            // Capacidade no limite de folga máxima (mais vazia permitida)
            $maxComfortCapacity = $demand / (1.0 - $maxFolga);

            if ($capacity >= $minComfortCapacity - $epsilon && $capacity <= $maxComfortCapacity + $epsilon) {
                $inComfortZone = true;
            }

            if ($capacity > $maxComfortCapacity + $epsilon) {
                $waste = $capacity - $maxComfortCapacity;
            }

            // Zera o waste quando a turma está na menor sala possível do
            // bloco relevante e mesmo essa sala produziria folga acima do
            // máximo permitido — não há sala menor disponível para acomodá-la
            // dentro da zona de conforto, logo não houve desperdício.
            if ($waste > $epsilon && $this->isSmallestRoomWasteExempt($capacity, $expectedBlock, $minCapacities, $demand, $maxFolga, $epsilon)) {
                $waste = 0.0;
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
            'expected_block' => $expectedBlock,
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
     * Determina o bloco de preferência (soft) de uma turma standalone, ou null
     * quando ela não é sujeito de aderência de bloco.
     *
     * Modelo:
     *  - Pós-graduação prefere o Bloco A (pós no Bloco B é indesejável, mas
     *    aceitável).
     *  - Toda graduação prefere o Bloco B (graduação no
     *    Bloco A é indesejável, mas aceitável).
     */
    private function expectedBlock(SchoolClass $class): ?string
    {
        if ($class->tiptur === 'Pós Graduação') {
            return 'A';
        }

        if ($class->tiptur === 'Graduação') {
            return 'B';
        }

        return null;
    }

    /**
     * Determina o bloco de preferência de uma dobradinha a partir das turmas
     * filhas, ou null quando a unidade não é sujeito de aderência de bloco.
     *
     *  - Dobradinhas mistas (graduação + pós) não têm bloco de preferência:
     *    retornam null e ficam de fora da aderência e da matriz de bloco.
     *  - Dobradinha só de pós → Bloco A.
     *  - Dobradinha só de graduação → Bloco B.
     */
    private function expectedBlockForGroup(Collection $children): ?string
    {
        $hasPos = false;
        $hasGrad = false;

        foreach ($children as $child) {
            if ($child->tiptur === 'Pós Graduação') {
                $hasPos = true;
            } elseif ($child->tiptur === 'Graduação') {
                $hasGrad = true;
            }
        }

        if ($hasPos && $hasGrad) {
            return null;
        }

        if ($hasPos) {
            return 'A';
        }

        if ($hasGrad) {
            return 'B';
        }

        return null;
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

    /**
     * Retorna a menor capacidade de sala por bloco ('A' e 'B') entre as salas
     * cujos ids foram informados.
     *
     * Salas cujo nome não começa com 'A' ou 'B' são ignoradas. O agrupamento
     * por bloco usa a primeira letra do nome (mesma convenção de roomBlock).
     *
     * @param array<int, int> $roomIds
     * @return array{A: float|null, B: float|null}
     */
    public static function minCapacitiesByBlock(array $roomIds): array
    {
        $mins = ['A' => null, 'B' => null];

        if (empty($roomIds)) {
            return $mins;
        }

        foreach (Room::whereIn('id', $roomIds)->get() as $room) {
            $name = trim($room->nome ?? '');

            if ($name === '') {
                continue;
            }

            $letter = strtoupper($name[0]);

            if (! in_array($letter, ['A', 'B'], true)) {
                continue;
            }

            $assentos = (float) $room->assentos;

            if ($mins[$letter] === null || $assentos < $mins[$letter]) {
                $mins[$letter] = $assentos;
            }
        }

        return $mins;
    }

    /**
     * Determina a menor capacidade relevante para a unidade de alocação,
     * conforme o bloco de preferência:
     *  - 'A' (pós-graduação): menor capacidade do Bloco A;
     *  - 'B' (graduação): menor capacidade do Bloco B;
     *  - null (fusão mista): min entre as menores capacidades de A e B.
     *
     * @param array{A: float|null, B: float|null} $minCapacities
     */
    private function relevantMinCapacity(?string $expectedBlock, array $minCapacities): ?float
    {
        if ($expectedBlock === 'A') {
            return $minCapacities['A'] ?? null;
        }

        if ($expectedBlock === 'B') {
            return $minCapacities['B'] ?? null;
        }

        $available = array_filter(
            [$minCapacities['A'] ?? null, $minCapacities['B'] ?? null],
            fn ($v) => $v !== null
        );

        return empty($available) ? null : min($available);
    }

    /**
     * Verifica se o waste deve ser zerado porque a turma está na menor sala
     * possível do bloco relevante e mesmo essa sala produziria folga acima do
     * máximo permitido.
     *
     * @param array{A: float|null, B: float|null} $minCapacities
     */
    private function isSmallestRoomWasteExempt(float $capacity, ?string $expectedBlock, array $minCapacities, float $demand, float $maxFolga, float $epsilon): bool
    {
        $minCap = $this->relevantMinCapacity($expectedBlock, $minCapacities);

        if ($minCap === null) {
            return false;
        }

        if (abs($capacity - $minCap) > $epsilon) {
            return false;
        }

        return $demand < (1.0 - $maxFolga) * $minCap;
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
