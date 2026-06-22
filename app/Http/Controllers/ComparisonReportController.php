<?php

namespace App\Http\Controllers;

use App\Models\ComparisonReport;
use App\Models\Room;
use App\Models\SchoolClass;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

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

        return view('comparisonreports.index', compact('reports'));
    }

    /**
     * Display a single comparison report with the benchmarking dashboard
     * (Delta Cards, Radar Chart and Scatter Plot of Demanda x Capacidade).
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

        return view('comparisonreports.show', compact('report', 'scatterData', 'comfortZone'));
    }

    /**
     * Constroi os pontos (demanda, capacidade) para o grafico de dispersao,
     * cruzando os mapas brutos de alocacao com as turmas e salas do semestre.
     *
     * @return array{legacy: array<int, array{x: float, y: float}>, solver: array<int, array{x: float, y: float}>}
     */
    protected function buildScatterData(ComparisonReport $report): array
    {
        $classes = SchoolClass::whereBelongsTo($report->schoolTerm)
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
     * descartando turmas sem demanda ou salas inexistentes.
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

        foreach ($allocations as $classId => $roomId) {
            if ($roomId === null) {
                continue;
            }

            $class = $classes->get((int) $classId);
            $room = $rooms->get((int) $roomId);

            if (! $class || ! $room) {
                continue;
            }

            if (! $class->estmtr || $class->estmtr <= 0) {
                continue;
            }

            $points[] = [
                'x' => (float) $class->estmtr,
                'y' => (float) $room->assentos,
            ];
        }

        return $points;
    }
}
