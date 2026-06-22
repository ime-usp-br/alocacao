@extends('main')

@section('title', 'Comparação #' . $report->id)

@php
    $legacy = $report->legacy_metrics ?? [];
    $solver = $report->solver_metrics ?? [];
    $isCompleted = $report->status === 'completed';
    $hasSolver = !empty($solver);

    // Define quais KPIs sao "quanto maior, melhor" e quais sao "quanto menor, melhor".
    $higherBetter = ['allocation_rate', 'comfort_zone_rate', 'block_adherence_rate'];
    $lowerBetter = ['avg_waste_per_class', 'avg_claustrophobia_per_class', 'solve_time_seconds'];

    $kpiLabels = [
        'allocation_rate'              => 'Taxa de Alocação',
        'comfort_zone_rate'            => 'Zona de Conforto',
        'avg_waste_per_class'          => 'Desperdício Médio',
        'avg_claustrophobia_per_class' => 'Claustrofobia Média',
        'block_adherence_rate'         => 'Aderência de Bloco',
        'solve_time_seconds'           => 'Tempo de Resolução (s)',
    ];

    $kpiUnits = [
        'allocation_rate'              => '%',
        'comfort_zone_rate'            => '%',
        'avg_waste_per_class'          => ' assentos',
        'avg_claustrophobia_per_class' => ' assentos',
        'block_adherence_rate'         => '%',
        'solve_time_seconds'           => ' s',
    ];

    $formatMetric = function ($key, $value) {
        if ($value === null) {
            return '-';
        }
        $decimals = in_array($key, ['solve_time_seconds', 'avg_waste_per_class', 'avg_claustrophobia_per_class'], true) ? 2 : 1;

        return number_format((float) $value, $decimals, ',', '.');
    };

    // Textos explicativos exibidos em tooltips nativas do Bootstrap 4.
    $kpiTooltips = [
        'allocation_rate'              => 'Percentual de turmas que o algoritmo conseguiu alocar em alguma sala.',
        'comfort_zone_rate'            => 'Percentual de turmas alocadas em salas que possuem a margem ideal de assentos livres (entre 10% e 25% a mais que a demanda).',
        'avg_waste_per_class'          => 'Média de assentos vazios que excedem o limite máximo de 25% de folga nas turmas alocadas. Quanto menor, melhor.',
        'avg_claustrophobia_per_class' => 'Média de assentos faltantes para atingir a margem de segurança de 10% de folga nas turmas alocadas. Quanto menor, melhor.',
        'block_adherence_rate'         => 'Percentual de turmas que respeitaram a restrição geográfica (ex: Calouros no Bloco A, Pós-graduação no Bloco B).',
        'solve_time_seconds'           => 'Tempo total em segundos gasto pelo algoritmo para encontrar a solução. Quanto menor, melhor.',
    ];
@endphp

@section('content')
  @parent
  <div id="layout_conteudo">
    <div class="justify-content-center">
        <div class="col-md-12">
            <h1 class='text-center mb-4'>Comparação de Algoritmos #{{ $report->id }}</h1>

            <div class="mb-4">
                <a href="{{ route('comparison-reports.index') }}" class="btn btn-outline-secondary btn-sm">&larr; Voltar</a>
            </div>

            <div class="mb-4">
                <div class="row">
                    <div class="col-6 col-md-3 mb-3">
                        <div class="card text-center h-100">
                            <div class="card-body py-2 d-flex justify-content-between align-items-center" style="font-size:14px;">
                                <span class="text-muted text-uppercase fw-semibold small">Status</span>
                                <span>
                                    @if ($report->status === 'completed')
                                        <span class="badge badge-success">concluído</span>
                                    @elseif ($report->status === 'processing')
                                        <span class="badge badge-warning">processando</span>
                                    @elseif ($report->status === 'failed')
                                        <span class="badge badge-danger">falhou</span>
                                    @else
                                        <span class="badge badge-secondary">{{ $report->status }}</span>
                                    @endif
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3 mb-3">
                        <div class="card h-100">
                            <div class="card-body py-2 d-flex justify-content-between align-items-center" style="font-size:14px;">
                                <span class="text-muted text-uppercase fw-semibold small">Semestre</span>
                                <span>{{ $report->schoolTerm ? $report->schoolTerm->year . '.' . $report->schoolTerm->period : '-' }}</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3 mb-3">
                        <div class="card h-100">
                            <div class="card-body py-2 d-flex justify-content-between align-items-center" style="font-size:14px;">
                                <span class="text-muted text-uppercase fw-semibold small">Estado Base</span>
                                <span>{{ $report->baseAllocationState ? $report->baseAllocationState->name : '-' }}</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3 mb-3">
                        <div class="card h-100">
                            <div class="card-body py-2 d-flex justify-content-between align-items-center" style="font-size:14px;">
                                <span class="text-muted text-uppercase fw-semibold small">Criado em</span>
                                <span>{{ $report->created_at ? $report->created_at->format('d/m/Y H:i:s') : '-' }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            @if (!$isCompleted)
                <div class="alert alert-info">
                    @if ($report->status === 'processing')
                        O relatório ainda está processando. As métricas do Solver serão preenchidas quando o microsserviço retornar via webhook.
                    @else
                        Este relatório não foi concluído com sucesso (status: <strong>{{ $report->status }}</strong>).
                    @endif
                    @if (!empty($legacy))
                        As métricas da heurística legada estão disponíveis abaixo.
                    @endif
                </div>
            @endif

            @if (!empty($legacy) || $hasSolver)
                {{-- ============================ --}}
                {{-- Cards de Variação (Deltas)  --}}
                {{-- ============================ --}}
                <h3 class="mb-3">Variação Relativa (Legado vs. Solver)</h3>
                <p class="text-muted small mb-3">A variação (%) representa a diferença do Solver em relação ao Legado. Verde = melhoria; vermelho = piora.</p>
                <div class="row mb-5">
                    @foreach ($kpiLabels as $key => $label)
                        @php
                            $legacyVal = $legacy[$key] ?? null;
                            $solverVal = $solver[$key] ?? null;
                            $higherIsBetter = in_array($key, $higherBetter, true);
                            $deltaClass = 'secondary';
                            $deltaLabel = '-';

                            if ($legacyVal !== null && $solverVal !== null) {
                                $legacyF = (float) $legacyVal;
                                $solverF = (float) $solverVal;
                                $deltaPct = null;
                                if (abs($legacyF) < 1e-9) {
                                    // Legado igual a 0: se solver também for 0 => igual;
                                    // se solver > 0 => "infinito" (representado como +∞/-∞ conforme métrica).
                                    if (abs($solverF) < 1e-9) {
                                        $deltaPct = 0.0;
                                    } else {
                                        $deltaPct = $higherIsBetter ? INF : -INF;
                                    }
                                } else {
                                    $deltaPct = (($solverF - $legacyF) / abs($legacyF)) * 100;
                                }

                                $improved = $higherIsBetter ? ($deltaPct > 0) : ($deltaPct < 0);
                                $worsened = $higherIsBetter ? ($deltaPct < 0) : ($deltaPct > 0);
                                if (abs($deltaPct) < 1e-9) {
                                    $deltaClass = 'secondary';
                                    $deltaLabel = 'igual';
                                } elseif (is_infinite($deltaPct)) {
                                    $deltaClass = $improved ? 'success' : 'danger';
                                    $deltaLabel = ($deltaPct > 0 ? '+' : '-') . '∞%';
                                } elseif ($improved) {
                                    $deltaClass = 'success';
                                    $deltaLabel = ($deltaPct > 0 ? '+' : '') . number_format($deltaPct, 1, ',', '.') . '%';
                                } elseif ($worsened) {
                                    $deltaClass = 'danger';
                                    $deltaLabel = ($deltaPct > 0 ? '+' : '') . number_format($deltaPct, 1, ',', '.') . '%';
                                }
                            }
                        @endphp
                        <div class="col-12 col-md-6 col-lg-4 mb-3">
                            <div class="card h-100 border-{{ $deltaClass }}">
                                <div class="card-body">
                                    <div class="text-muted small text-uppercase fw-semibold mb-2" data-toggle="tooltip" data-placement="top" title="{{ $kpiTooltips[$key] ?? '' }}">{{ $label }}</div>
                                    <div class="d-flex justify-content-between align-items-end">
                                        <div>
                                            <div class="small text-muted">Legado</div>
                                            <div class="fs-5 fw-bold">{{ $formatMetric($key, $legacyVal) . ($legacyVal !== null ? $kpiUnits[$key] : '') }}</div>
                                        </div>
                                        <div class="text-center px-2">
                                            <span class="badge badge-{{ $deltaClass }}">{{ $deltaLabel }}</span>
                                        </div>
                                        <div class="text-right">
                                            <div class="small text-muted">Solver</div>
                                            <div class="fs-5 fw-bold {{ $hasSolver ? '' : 'text-muted' }}">{{ $formatMetric($key, $solverVal) . ($solverVal !== null ? $kpiUnits[$key] : '') }}</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                @if ($hasSolver)
                    {{-- ============================ --}}
                    {{-- Gráfico Radar (Teia)       --}}
                    {{-- ============================ --}}
                    <h3 class="mb-3" data-toggle="tooltip" data-placement="top" title="Pontuação normalizada de 0 a 100: o algoritmo com melhor desempenho em cada eixo recebe 100.">Cobertura de Qualidade (Radar Normalizado 0-100)</h3>
                    <div class="card mb-5">
                        <div class="card-body">
                            <div style="position: relative; height: 450px;">
                                <canvas id="radarChart"></canvas>
                            </div>
                        </div>
                    </div>

                    {{-- ============================ --}}
                    {{-- Scatter Plot Demanda x Cap --}}
                    {{-- ============================ --}}
                    <h3 class="mb-3" data-toggle="tooltip" data-placement="top" title="Cada ponto representa uma turma alocada, posicionada pela demanda (alunos) x capacidade (cadeiras) da sala escolhida. A área sombreada é a Zona de Conforto.">Dispersão Demanda x Capacidade</h3>
                    <div class="card mb-4">
                        <div class="card-body">
                            <p class="text-muted small mb-2">
                                Pontos <span style="color:#dc3545; font-weight:bold;">vermelhos</span> = Heurística Legada;
                                <span style="color:#007bff; font-weight:bold;">azuis</span> = Solver CP-SAT.
                                A área sombreada representa a Zona de Conforto (margens de {{ $comfortZone['min_percent'] }}% a {{ $comfortZone['max_percent'] }}%).
                            </p>
                            <div style="position: relative; height: 500px;">
                                <canvas id="scatterChart"></canvas>
                            </div>
                        </div>
                    </div>
                @endif
            @else
                <p class="text-center text-muted">Ainda não há métricas disponíveis para este relatório.</p>
            @endif
        </div>
    </div>
  </div>
@endsection

@section('javascripts_bottom')
@parent
@if ($hasSolver)
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
(function () {
    const legacyMetrics = @json($legacy);
    const solverMetrics = @json($solver);
    const scatterData   = @json($scatterData);
    const comfortZone   = @json($comfortZone);

    // ---------------------------------------------------------------
    // Normalizacao 0-100 para o Radar Chart (Relative Max/Min Scaling).
    // Comparativo justo de trade-off: em CADA eixo o vencedor encosta na
    // borda externa (100) e o perdedor é plotado proporcionalmente.
    //
    //  - Métricas "maior melhor": score = (valor / maxVal) * 100, onde
    //    maxVal = Math.max(legado, solver). O vencedor (maior) recebe 100.
    //    Se ambos forem 0 => score 0 (empate em zero => sem destaque).
    //
    //  - Métricas "menor melhor": score = (minVal / valor) * 100, onde
    //    minVal = Math.min(legado, solver). O vencedor (menor) recebe 100.
    //    Se o valor for 0 => ausência de desperdício/claustrofobia/tempo
    //    é perfeição => score 100.
    // ---------------------------------------------------------------
    const higherBetter = ['allocation_rate', 'comfort_zone_rate', 'block_adherence_rate'];
    const lowerBetter  = ['avg_waste_per_class', 'avg_claustrophobia_per_class', 'solve_time_seconds'];
    const axes = [
        'allocation_rate',
        'comfort_zone_rate',
        'block_adherence_rate',
        'avg_waste_per_class',
        'avg_claustrophobia_per_class',
        'solve_time_seconds',
    ];
    const axisLabels = [
        'Taxa de Alocação',
        'Zona de Conforto',
        'Aderência de Bloco',
        'Eficiência de Ocupação',
        'Adequação de Espaço',
        'Velocidade de Resolução',
    ];

    // Unidades/formatadores para exibir o valor bruto na tooltip do radar.
    const rawUnits = {
        'allocation_rate':              { suffix: '%', decimals: 1 },
        'comfort_zone_rate':            { suffix: '%', decimals: 1 },
        'block_adherence_rate':         { suffix: '%', decimals: 1 },
        'avg_waste_per_class':          { suffix: ' assentos', decimals: 2 },
        'avg_claustrophobia_per_class': { suffix: ' assentos', decimals: 2 },
        'solve_time_seconds':           { suffix: ' s', decimals: 2 },
    };

    function val(metrics, key) {
        const v = metrics[key];
        return (v === null || v === undefined) ? null : Number(v);
    }

    function formatRaw(key, v) {
        if (v === null) return 'n/a';
        const cfg = rawUnits[key] || { suffix: '', decimals: 2 };
        return v.toFixed(cfg.decimals).replace('.', ',') + cfg.suffix;
    }

    // Guarda os valores brutos para uso nas tooltips (mantém a ordem de axes).
    const legacyRaw = [];
    const solverRaw = [];
    axes.forEach(function (key, i) {
        legacyRaw[i] = val(legacyMetrics, key);
        solverRaw[i] = val(solverMetrics, key);
    });

    // Único loop de processamento: preenche legacyNorm e solverNorm com
    // normalização relativa (max para higherBetter, min para lowerBetter).
    const legacyNorm = [];
    const solverNorm = [];
    axes.forEach(function (key, i) {
        const lv = legacyRaw[i];
        const sv = solverRaw[i];
        const isHigher = higherBetter.indexOf(key) !== -1;

        if (isHigher) {
            // "Quanto maior, melhor": proporção direta em relação ao máximo.
            const maxVal = Math.max(
                (lv === null ? -Infinity : lv),
                (sv === null ? -Infinity : sv)
            );
            function scaleHigh(v) {
                if (v === null) return 0;
                // Ambos 0 => empate em zero => sem destaque (score 0).
                if (maxVal <= 1e-9) return 0;
                return Math.max(0, Math.min(100, (v / maxVal) * 100));
            }
            legacyNorm[i] = scaleHigh(lv);
            solverNorm[i] = scaleHigh(sv);
        } else {
            // "Quanto menor, melhor": proporção inversa em relação ao mínimo.
            const minVal = Math.min(
                (lv === null ? Infinity : lv),
                (sv === null ? Infinity : sv)
            );
            function scaleLow(v) {
                if (v === null) return 0;
                // Valor 0 => perfeição (ausência de desperdício/claustrofobia/tempo).
                if (v <= 1e-9) return 100;
                // Se o mínimo for 0: o vencedor (0) já recebeu 100 acima;
                // o perdedor (>0) recebe 0, pois não há divisor válido.
                if (minVal <= 1e-9) return 0;
                return Math.max(0, Math.min(100, (minVal / v) * 100));
            }
            legacyNorm[i] = scaleLow(lv);
            solverNorm[i] = scaleLow(sv);
        }
    });

    if (typeof Chart !== 'undefined') {
        // ----- Radar Chart -----
        new Chart(document.getElementById('radarChart'), {
            type: 'radar',
            data: {
                labels: axisLabels,
                datasets: [
                    {
                        label: 'Legado',
                        data: legacyNorm,
                        borderColor: 'rgba(220, 53, 69, 1)',
                        backgroundColor: 'rgba(220, 53, 69, 0.2)',
                        borderWidth: 2,
                        pointBackgroundColor: 'rgba(220, 53, 69, 1)',
                    },
                    {
                        label: 'Solver CP-SAT',
                        data: solverNorm,
                        borderColor: 'rgba(0, 123, 255, 1)',
                        backgroundColor: 'rgba(0, 123, 255, 0.2)',
                        borderWidth: 2,
                        pointBackgroundColor: 'rgba(0, 123, 255, 1)',
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    r: {
                        min: 0,
                        max: 100,
                        ticks: { stepSize: 20 },
                        pointLabels: { font: { size: 12 } },
                    },
                },
                plugins: {
                    legend: { position: 'top' },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                const i = ctx.dataIndex;
                                const key = axes[i];
                                const raw = (ctx.datasetIndex === 0) ? legacyRaw[i] : solverRaw[i];
                                const score = ctx.parsed.r;
                                return ctx.dataset.label + ': ' + formatRaw(key, raw) +
                                    ' (Score normalizado de 0-100: ' + Number(score.toFixed(1)).toLocaleString('pt-BR') + ')';
                            },
                        },
                    },
                },
            },
        });

        // ----- Scatter Plot -----
        // A Zona de Conforto segue a mesma régua do AllocationEvaluatorService:
        // margens aditivas relativas à DEMANDA, ou seja:
        //   capacidade_min = demanda * (1 + minPercent/100)
        //   capacidade_max = demanda * (1 + maxPercent/100)
        // Amostramos o eixo X (demanda) para desenhar o polígono sombreado.
        const minPct = comfortZone.min_percent;
        const maxPct = comfortZone.max_percent;
        const yMinSlope = 1 + minPct / 100;
        const yMaxSlope = 1 + maxPct / 100;

        // Determina o range de demanda a partir dos pontos disponíveis.
        let maxDemand = 0;
        ['legacy', 'solver'].forEach(function (src) {
            (scatterData[src] || []).forEach(function (p) {
                if (p.x > maxDemand) maxDemand = p.x;
            });
        });
        if (maxDemand <= 0) maxDemand = 100;

        const zoneSteps = 50;
        const zoneUpper = [];
        const zoneLower = [];
        for (let i = 0; i <= zoneSteps; i++) {
            const x = (maxDemand * 1.1) * (i / zoneSteps);
            zoneUpper.push({ x: x, y: x * yMaxSlope });
            zoneLower.push({ x: x, y: x * yMinSlope });
        }
        // Polígono sombreado: sobe pela reta superior e desce pela reta inferior.
        const zonePolygon = zoneUpper.concat(zoneLower.slice().reverse());

        new Chart(document.getElementById('scatterChart'), {
            type: 'scatter',
            data: {
                datasets: [
                    {
                        label: 'Zona de Conforto',
                        data: zonePolygon,
                        backgroundColor: 'rgba(40, 167, 69, 0.15)',
                        borderColor: 'rgba(40, 167, 69, 0.5)',
                        borderWidth: 1,
                        showLine: true,
                        fill: true,
                        pointRadius: 0,
                        order: 3,
                    },
                    {
                        label: 'Legado',
                        data: scatterData.legacy || [],
                        backgroundColor: 'rgba(220, 53, 69, 0.7)',
                        borderColor: 'rgba(220, 53, 69, 1)',
                        pointRadius: 4,
                        order: 1,
                    },
                    {
                        label: 'Solver CP-SAT',
                        data: scatterData.solver || [],
                        backgroundColor: 'rgba(0, 123, 255, 0.7)',
                        borderColor: 'rgba(0, 123, 255, 1)',
                        pointRadius: 4,
                        order: 2,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        type: 'linear',
                        position: 'bottom',
                        title: { display: true, text: 'Demanda (alunos)' },
                        min: 0,
                    },
                    y: {
                        type: 'linear',
                        title: { display: true, text: 'Capacidade (cadeiras)' },
                        min: 0,
                    },
                },
                plugins: {
                    legend: { position: 'top' },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                const d = ctx.raw;
                                return ctx.dataset.label + ': (' + d.x + ', ' + d.y + ')';
                            },
                        },
                    },
                },
            },
        });
    }
})();
</script>
@endif
<script>
    // Inicializa tooltips nativas do Bootstrap 4 nesta página.
    if (typeof jQuery !== 'undefined') {
        jQuery(function ($) {
            $('[data-toggle="tooltip"]').tooltip();
        });
    }
</script>
@endsection
