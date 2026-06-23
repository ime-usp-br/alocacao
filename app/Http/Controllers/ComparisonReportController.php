<?php

namespace App\Http\Controllers;

use App\Models\ComparisonReport;
use App\Models\Room;
use App\Models\SchoolClass;
use App\Services\AllocationEvaluatorService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class ComparisonReportController extends Controller
{
    /**
     * Display a listing of comparison reports.
     */
    public function index(Request $request)
    {
        if (! Auth::check() || ! Auth::user()->hasRole('Administrador')) {
            abort(403);
        }

        $reports = ComparisonReport::with(['schoolTerm', 'baseAllocationState'])
            ->orderByDesc('id')
            ->paginate(20);

        $summaryByTerm = $this->buildCrossRunSummary($reports->getCollection());

        return view('comparisonreports.index', compact('reports', 'summaryByTerm'));
    }

    /**
     * Display a single comparison report with the benchmarking dashboard
     * (Delta Cards, Radar Chart, Scatter Plot, Distributional Analysis,
     * Paired Comparison, Block Contingency and Agreement Analysis).
     */
    public function show($id)
    {
        if (! Auth::check() || ! Auth::user()->hasRole('Administrador')) {
            abort(403);
        }

        $report = ComparisonReport::with(['schoolTerm', 'baseAllocationState'])
            ->findOrFail($id);

        $scatterData = $this->buildScatterData($report);

        $comfortZone = [
            'min_percent' => (float) config('alocacao.room_allocation.comfort_zone_min_percent', 10.0),
            'max_percent' => (float) config('alocacao.room_allocation.comfort_zone_max_percent', 25.0),
        ];

        $analytics = $this->buildAnalytics($report);

        return view('comparisonreports.show', compact('report', 'scatterData', 'comfortZone', 'analytics'));
    }

    /**
     * Constroi os pontos (demanda, capacidade) para o grafico de dispersao,
     * cruzando os mapas brutos de alocacao com as turmas e salas do semestre.
     *
     * Dobradinhas (fusões) colapsam em um único ponto: demanda = soma dos
     * estmtr das filhas, capacidade = sala compartilhada (resolvida via
     * master_id, mesma chave do mapa de alocações). Isto evita N pontos
     * empilhados em (demanda individual, capacidade da sala cheia) para uma
     * mesma dobradinha.
     *
     * @return array{legacy: array<int, array{x: float, y: float}>, solver: array<int, array{x: float, y: float}>}
     */
    protected function buildScatterData(ComparisonReport $report): array
    {
        $classes = SchoolClass::whereBelongsTo($report->schoolTerm)
            ->with('fusion')
            ->orderBy('id')
            ->get()
            ->keyBy('id');

        $roomIds = collect()
            ->merge($report->legacy_raw_allocations ?? [])
            ->merge($report->solver_raw_allocations ?? [])
            ->filter(fn ($roomId) => $roomId !== null)
            ->map(fn ($roomId) => (int) $roomId)
            ->unique()
            ->values()
            ->all();

        $rooms = empty($roomIds) ? collect() : Room::whereIn('id', $roomIds)->get()->keyBy('id');

        return [
            'legacy' => $this->scatterPoints($report->legacy_raw_allocations, $classes, $rooms),
            'solver' => $this->scatterPoints($report->solver_raw_allocations, $classes, $rooms),
        ];
    }

    /**
     * Mapeia um conjunto de alocacoes brutas em pontos (x=demanda, y=capacidade),
     * descartando unidades sem demanda ou salas inexistentes. Dobradinhas
     * viram um único ponto com demanda somada.
     *
     * @param  array<int, int|null>|null  $allocations
     * @return array<int, array{x: float, y: float}>
     */
    protected function scatterPoints(?array $allocations, Collection $classes, Collection $rooms): array
    {
        $points = [];

        if (empty($allocations)) {
            return $points;
        }

        $solo = $classes->filter(fn ($c) => $c->fusion_id === null);
        $fused = $classes->filter(fn ($c) => $c->fusion_id !== null)->groupBy('fusion_id');

        foreach ($solo as $class) {
            if (! $class->estmtr || $class->estmtr <= 0) {
                continue;
            }

            $point = $this->scatterPointFor((int) $class->id, (float) $class->estmtr, $allocations, $rooms);
            if ($point !== null) {
                $points[] = $point;
            }
        }

        foreach ($fused as $children) {
            $fusion = $children->first()->fusion;
            $masterId = $fusion && $fusion->master_id
                ? (int) $fusion->master_id
                : (int) $children->min('id');

            $demand = 0.0;
            foreach ($children as $child) {
                if ($child->estmtr && $child->estmtr > 0) {
                    $demand += (float) $child->estmtr;
                }
            }

            if ($demand <= 0) {
                continue;
            }

            $point = $this->scatterPointFor($masterId, $demand, $allocations, $rooms);
            if ($point !== null) {
                $points[] = $point;
            }
        }

        return $points;
    }

    /**
     * Resolve um ponto (demanda, capacidade) para uma unidade de alocação
     * alocada, ou null quando não alocada / sala inexistente.
     *
     * @param  array<int, int|null>  $allocations
     * @return array{x: float, y: float}|null
     */
    protected function scatterPointFor(int $classId, float $demand, array $allocations, Collection $rooms): ?array
    {
        $roomId = $allocations[$classId] ?? null;

        if ($roomId === null) {
            return null;
        }

        $room = $rooms->get((int) $roomId);

        if (! $room) {
            return null;
        }

        return [
            'x' => $demand,
            'y' => (float) $room->assentos,
        ];
    }

    /**
     * Computa o payload analytics completo (breakdown, sumários pareados,
     * histogramas, matrizes de contingência e concordância).
     */
    protected function buildAnalytics(ComparisonReport $report): array
    {
        if (! $report->schoolTerm) {
            return [];
        }

        $evaluator = new AllocationEvaluatorService();
        $legacyBreakdown = $evaluator->breakdown($report->schoolTerm, $report->legacy_raw_allocations ?? []);
        $solverBreakdown = $evaluator->breakdown($report->schoolTerm, $report->solver_raw_allocations ?? []);

        $legacyById = collect($legacyBreakdown)->keyBy('class_id');
        $solverById = collect($solverBreakdown)->keyBy('class_id');

        // --- Summary stats per engine (allocated only) ---
        $legacyAllocated = array_filter($legacyBreakdown, fn ($r) => $r['allocated']);
        $solverAllocated = array_filter($solverBreakdown, fn ($r) => $r['allocated']);

        $summary = [
            'legacy' => $this->engineSummary($legacyAllocated),
            'solver' => $this->engineSummary($solverAllocated),
        ];

        // --- Paired analysis (same class ids, allocated by both) ---
        $paired = [];
        $commonIds = $legacyById->keys()->intersect($solverById->keys())->all();
        foreach ($commonIds as $cid) {
            $l = $legacyById->get($cid);
            $s = $solverById->get($cid);
            if (! $l['allocated'] || ! $s['allocated']) {
                continue;
            }
            $paired[] = [
                'class_id' => $cid,
                'demand' => $l['demand'],
                'occupancy_legacy' => $l['occupancy_ratio'],
                'occupancy_solver' => $s['occupancy_ratio'],
                'diff_occupancy' => $s['occupancy_ratio'] - $l['occupancy_ratio'],
                'waste_legacy' => $l['waste'],
                'waste_solver' => $s['waste'],
                'diff_waste' => $s['waste'] - $l['waste'],
                'claustrophobia_legacy' => $l['claustrophobia'],
                'claustrophobia_solver' => $s['claustrophobia'],
                'diff_claustrophobia' => $s['claustrophobia'] - $l['claustrophobia'],
                'room_changed' => $l['room_id'] !== $s['room_id'],
                'capacity_legacy' => $l['capacity'],
                'capacity_solver' => $s['capacity'],
                'capacity_delta' => $s['capacity'] - $l['capacity'],
                'block_transition' => ($l['actual_block'] ?? 'none') . '→' . ($s['actual_block'] ?? 'none'),
                'expected_block' => $l['expected_block'],
            ];
        }

        $pairedStats = $this->computePairedStats($paired);

        // --- Histograms (server-side bins) ---
        $occBinsLegacy = $this->buildHistogramBins(array_column($legacyAllocated, 'occupancy_ratio'), 0.0, 0.1, 20);
        $occBinsSolver = $this->buildHistogramBins(array_column($solverAllocated, 'occupancy_ratio'), 0.0, 0.1, 20);
        $occBins = [
            'labels' => $occBinsLegacy['labels'],
            'legacy' => $occBinsLegacy['counts'],
            'solver' => $occBinsSolver['counts'],
        ];
        $diffOccBins = $this->buildHistogramBins(array_column($paired, 'diff_occupancy'), -1.0, 0.1, 20);
        $diffWasteBins = $this->buildHistogramBins(array_column($paired, 'diff_waste'), -30.0, 3.0, 20);
        $diffClausBins = $this->buildHistogramBins(array_column($paired, 'diff_claustrophobia'), -30.0, 3.0, 20);

        // --- Block contingency tables ---
        $blockCategories = ['A', 'B', 'Outro', 'Não alocada'];
        $legacyBlockMatrix = $this->buildBlockMatrix($legacyBreakdown);
        $solverBlockMatrix = $this->buildBlockMatrix($solverBreakdown);

        // --- Agreement / transition ---
        $sameRoom = 0;
        $changedRoom = 0;
        $capDeltas = [];
        $blockTransitions = [];
        foreach ($paired as $p) {
            if ($p['room_changed']) {
                $changedRoom++;
                $capDeltas[] = $p['capacity_delta'];
            } else {
                $sameRoom++;
            }
            $bt = $p['block_transition'];
            $blockTransitions[$bt] = ($blockTransitions[$bt] ?? 0) + 1;
        }
        $totalPaired = count($paired);
        $agreementRate = $totalPaired > 0 ? ($sameRoom / $totalPaired) * 100.0 : 0.0;

        $capacityDeltaBins = [
            'labels' => ['< -30', '-30 a -20', '-20 a -10', '-10 a 0', '0', '0 a 10', '10 a 20', '20 a 30', '> 30'],
            'legacy' => array_fill(0, 9, 0),
            'solver' => array_fill(0, 9, 0),
        ];
        foreach ($capDeltas as $d) {
            $idx = $this->capacityDeltaBinIndex($d);
            $capacityDeltaBins['solver'][$idx]++;
        }

        return [
            'summary' => $summary,
            'paired' => [
                'records' => $paired,
                'stats' => $pairedStats,
                'n_pairs' => $totalPaired,
                'same_room' => $sameRoom,
                'changed_room' => $changedRoom,
                'agreement_rate' => $agreementRate,
            ],
            'histograms' => [
                'occupancy' => $occBins,
                'diff_occupancy' => $diffOccBins,
                'diff_waste' => $diffWasteBins,
                'diff_claustrophobia' => $diffClausBins,
            ],
            'block_contingency' => [
                'categories' => $blockCategories,
                'legacy' => $legacyBlockMatrix,
                'solver' => $solverBlockMatrix,
            ],
            'agreement' => [
                'capacity_delta_bins' => $capacityDeltaBins,
                'block_transitions' => $blockTransitions,
            ],
        ];
    }

    /**
     * Sumário estatístico por motor sobre turmas alocadas.
     */
    protected function engineSummary(array $allocatedRecords): array
    {
        $occupancy = array_column($allocatedRecords, 'occupancy_ratio');
        $waste = array_column($allocatedRecords, 'waste');
        $claustrophobia = array_column($allocatedRecords, 'claustrophobia');

        return [
            'occupancy_ratio' => $this->computeSummaryStats($occupancy),
            'waste' => $this->computeSummaryStats($waste),
            'claustrophobia' => $this->computeSummaryStats($claustrophobia),
        ];
    }

    /**
     * Estatísticas descritivas: n, mean, sd, median, q1, q3, min, max.
     * Filtra nulls.
     */
    protected function computeSummaryStats(array $values): array
    {
        $clean = array_values(array_filter($values, fn ($v) => $v !== null));
        $n = count($clean);
        if ($n === 0) {
            return [
                'n' => 0, 'mean' => null, 'sd' => null,
                'median' => null, 'q1' => null, 'q3' => null,
                'min' => null, 'max' => null,
            ];
        }

        sort($clean);
        $mean = array_sum($clean) / $n;
        $sd = $this->sampleSd($clean, $mean);
        $median = $this->percentile($clean, 0.5);
        $q1 = $this->percentile($clean, 0.25);
        $q3 = $this->percentile($clean, 0.75);

        return [
            'n' => $n,
            'mean' => $mean,
            'sd' => $sd,
            'median' => $median,
            'q1' => $q1,
            'q3' => $q3,
            'min' => $clean[0],
            'max' => $clean[$n - 1],
        ];
    }

    protected function sampleSd(array $sorted, float $mean): float
    {
        $n = count($sorted);
        if ($n <= 1) {
            return 0.0;
        }
        $sumSq = 0.0;
        foreach ($sorted as $v) {
            $d = $v - $mean;
            $sumSq += $d * $d;
        }

        return sqrt($sumSq / ($n - 1));
    }

    protected function percentile(array $sorted, float $p): float
    {
        $n = count($sorted);
        if ($n === 0) {
            return 0.0;
        }
        if ($n === 1) {
            return $sorted[0];
        }
        $idx = ($n - 1) * $p;
        $lo = (int) floor($idx);
        $hi = (int) ceil($idx);
        $frac = $idx - $lo;
        if ($lo === $hi) {
            return $sorted[$lo];
        }

        return $sorted[$lo] * (1 - $frac) + $sorted[$hi] * $frac;
    }

    /**
     * Estatísticas pareadas sobre diffs (occupancy, waste, claustrophobia).
     */
    protected function computePairedStats(array $paired): array
    {
        $stats = [];
        foreach (['diff_occupancy', 'diff_waste', 'diff_claustrophobia'] as $key) {
            $vals = array_column($paired, $key);
            $clean = array_values(array_filter($vals, fn ($v) => $v !== null));
            $n = count($clean);
            if ($n === 0) {
                $stats[$key] = [
                    'n' => 0, 'mean_diff' => null, 'sd_diff' => null,
                    'se_diff' => null, 'ci95_lower' => null, 'ci95_upper' => null,
                    'median_diff' => null, 'n_positive' => 0, 'n_negative' => 0,
                ];
                continue;
            }
            $mean = array_sum($clean) / $n;
            $sd = $this->sampleSd($clean, $mean);
            $se = $sd / sqrt($n);
            $z = 1.96; // normal approx (n large)
            $ciLo = $mean - $z * $se;
            $ciHi = $mean + $z * $se;
            sort($clean);
            $median = $this->percentile($clean, 0.5);
            $pos = count(array_filter($clean, fn ($v) => $v > 0));
            $neg = count(array_filter($clean, fn ($v) => $v < 0));

            $stats[$key] = [
                'n' => $n,
                'mean_diff' => $mean,
                'sd_diff' => $sd,
                'se_diff' => $se,
                'ci95_lower' => $ciLo,
                'ci95_upper' => $ciHi,
                'median_diff' => $median,
                'n_positive' => $pos,
                'n_negative' => $neg,
            ];
        }

        return $stats;
    }

    /**
     * Constrói bins de histograma com range fixo [start, start+step*bins).
     * Valores fora do range caem no primeiro/último bin (clamp).
     */
    protected function buildHistogramBins(array $values, float $start, float $step, int $binCount): array
    {
        $counts = array_fill(0, $binCount, 0);
        foreach ($values as $v) {
            if ($v === null) {
                continue;
            }
            $idx = (int) floor(($v - $start) / $step);
            if ($idx < 0) {
                $idx = 0;
            } elseif ($idx >= $binCount) {
                $idx = $binCount - 1;
            }
            $counts[$idx]++;
        }
        $labels = [];
        for ($i = 0; $i < $binCount; $i++) {
            $lo = $start + $i * $step;
            $hi = $lo + $step;
            $labels[] = number_format($lo, 1, ',', '.') . '–' . number_format($hi, 1, ',', '.');
        }

        return ['labels' => $labels, 'counts' => $counts];
    }

    /**
     * Matriz de contingência de bloco.
     * Linhas: expected_block (A = pós-graduação, B = graduação).
     * Colunas: actual_block (A, B, Outro, Não alocada).
     * Dobradinhas mistas (grad + pós, sem preferência) ficam de fora pois têm
     * expected_block null.
     */
    protected function buildBlockMatrix(array $breakdown): array
    {
        $rows = ['A', 'B'];
        $cols = ['A', 'B', 'Outro', 'Não alocada'];
        $matrix = [];
        foreach ($rows as $r) {
            $matrix[$r] = array_fill_keys($cols, 0);
        }
        foreach ($breakdown as $rec) {
            $exp = $rec['expected_block'];
            if ($exp === null) {
                continue;
            }
            if ($rec['allocated']) {
                $act = $rec['actual_block'];
                $col = in_array($act, ['A', 'B'], true) ? $act : 'Outro';
            } else {
                $col = 'Não alocada';
            }
            $matrix[$exp][$col]++;
        }

        return $matrix;
    }

    protected function capacityDeltaBinIndex(float $delta): int
    {
        if ($delta < -30) {
            return 0;
        }
        if ($delta < -20) {
            return 1;
        }
        if ($delta < -10) {
            return 2;
        }
        if ($delta < 0) {
            return 3;
        }
        if ($delta == 0) {
            return 4;
        }
        if ($delta <= 10) {
            return 5;
        }
        if ($delta <= 20) {
            return 6;
        }
        if ($delta <= 30) {
            return 7;
        }

        return 8;
    }

    /**
     * Agregação cross-run por semestre (somente relatórios concluídos).
     *
     * @param Collection<int, ComparisonReport> $reports
     * @return array<int, array>
     */
    protected function buildCrossRunSummary(Collection $reports): array
    {
        $grouped = $reports->where('status', 'completed')->groupBy('school_term_id');
        $summary = [];

        $metricKeys = [
            'allocation_rate',
            'comfort_zone_rate',
            'avg_waste_per_class',
            'avg_claustrophobia_per_class',
            'block_adherence_rate',
            'solve_time_seconds',
        ];

        foreach ($grouped as $termId => $items) {
            $termName = $items->first()->schoolTerm
                ? $items->first()->schoolTerm->year . '.' . $items->first()->schoolTerm->period
                : 'Term ' . $termId;

            $perEngine = [
                'legacy' => [],
                'solver' => [],
            ];

            foreach ($items as $report) {
                foreach (['legacy', 'solver'] as $engine) {
                    $metrics = $report->{$engine . '_metrics'} ?? [];
                    foreach ($metricKeys as $mk) {
                        $v = $metrics[$mk] ?? null;
                        if ($v !== null) {
                            $perEngine[$engine][$mk][] = (float) $v;
                        }
                    }
                }
            }

            $engineSummary = [];
            foreach (['legacy', 'solver'] as $engine) {
                $engineSummary[$engine] = [];
                foreach ($metricKeys as $mk) {
                    $vals = $perEngine[$engine][$mk] ?? [];
                    $n = count($vals);
                    if ($n === 0) {
                        $engineSummary[$engine][$mk] = ['n' => 0, 'mean' => null, 'sd' => null];
                        continue;
                    }
                    $mean = array_sum($vals) / $n;
                    $sd = $this->sampleSd($vals, $mean);
                    $engineSummary[$engine][$mk] = [
                        'n' => $n,
                        'mean' => $mean,
                        'sd' => $sd,
                    ];
                }
            }

            $summary[$termId] = [
                'term_name' => $termName,
                'n_reports' => $items->count(),
                'engines' => $engineSummary,
            ];
        }

        return $summary;
    }
}
