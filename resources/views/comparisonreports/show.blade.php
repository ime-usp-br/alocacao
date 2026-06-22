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

    $fmt = function ($v, $d = 2) {
        if ($v === null) {
            return '-';
        }
        return number_format((float) $v, $d, ',', '.');
    };
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
                    <h3 class="mb-3" data-toggle="tooltip" data-placement="top" title="Pontuação normalizada de 0 a 100: o algoritmo com melhor desempenho em cada eixo recebe 100. Escala comparativa (não absoluta).">Cobertura de Qualidade (Radar Normalizado 0–100)</h3>
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
                    <h3 class="mb-3" data-toggle="tooltip" data-placement="top" title="Cada ponto representa uma turma alocada, posicionada pela demanda (alunos) x capacidade (cadeiras) da sala escolhida. A área sombreada é a Zona de Conforto. Reta y=x = capacidade exatamente igual à demanda.">Dispersão Demanda x Capacidade</h3>
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

                    {{-- ============================ --}}
                    {{-- Tabela Sumária Estatística --}}
                    {{-- ============================ --}}
                    <h3 class="mb-3">Sumário Estatístico por Motor (Turmas Alocadas)</h3>
                    <div class="card mb-5">
                        <div class="card-body table-responsive">
                            <table class="table table-sm table-bordered text-center" style="font-size:13px;">
                                <thead class="thead-light">
                                    <tr>
                                        <th>Motor</th>
                                        <th>Métrica</th>
                                        <th>n</th>
                                        <th>Média</th>
                                        <th>DP</th>
                                        <th>Mediana</th>
                                        <th>Q1</th>
                                        <th>Q3</th>
                                        <th>Mín</th>
                                        <th>Máx</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach (['legacy' => 'Legado', 'solver' => 'Solver CP-SAT'] as $engKey => $engLabel)
                                        @php $engSum = $analytics['summary'][$engKey] ?? []; @endphp
                                        @foreach (['occupancy_ratio' => 'Razão de Ocupação', 'waste' => 'Desperdício', 'claustrophobia' => 'Claustrofobia'] as $metKey => $metLabel)
                                            @php $s = $engSum[$metKey] ?? []; @endphp
                                            <tr>
                                                @if ($loop->first)
                                                    <td rowspan="3" class="align-middle fw-bold">{{ $engLabel }}</td>
                                                @endif
                                                <td class="text-left">{{ $metLabel }}</td>
                                                <td>{{ $s['n'] ?? 0 }}</td>
                                                <td>{{ $fmt($s['mean'] ?? null, 3) }}</td>
                                                <td>{{ $fmt($s['sd'] ?? null, 3) }}</td>
                                                <td>{{ $fmt($s['median'] ?? null, 3) }}</td>
                                                <td>{{ $fmt($s['q1'] ?? null, 3) }}</td>
                                                <td>{{ $fmt($s['q3'] ?? null, 3) }}</td>
                                                <td>{{ $fmt($s['min'] ?? null, 3) }}</td>
                                                <td>{{ $fmt($s['max'] ?? null, 3) }}</td>
                                            </tr>
                                        @endforeach
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    {{-- ============================ --}}
                    {{-- Boxplot Ocupação           --}}
                    {{-- ============================ --}}
                    <h3 class="mb-3">Estatísticas de Posição da Ocupação</h3>
                    <div class="card mb-5">
                        <div class="card-body">
                            <div style="position: relative; height: 350px;">
                                <canvas id="summaryBarChart"></canvas>
                            </div>
                        </div>
                    </div>

                    {{-- ============================ --}}
                    {{-- Histograma Ocupação        --}}
                    {{-- ============================ --}}
                    <h3 class="mb-3">Histograma da Razão de Ocupação</h3>
                    <div class="card mb-5">
                        <div class="card-body">
                            <div style="position: relative; height: 350px;">
                                <canvas id="occHistogramChart"></canvas>
                            </div>
                        </div>
                    </div>

                    {{-- ============================ --}}
                    {{-- Análise Pareada            --}}
                    {{-- ============================ --}}
                    <h3 class="mb-3">Análise Pareada (Solver − Legado)</h3>
                    <p class="text-muted small mb-3">
                        Desenho pareado: mesmas turmas, mesmo estado base.
                        IC 95% via aproximação normal (z = 1,96).
                        Sinal positivo em <em>ocupação</em> = sala mais cheia no Solver;
                        positivo em <em>desperdício/claustrofobia</em> = piora no Solver.
                    </p>

                    <div class="row mb-2">
                        <div class="col-md-4 mb-3">
                            <div class="card h-100">
                                <div class="card-body">
                                    <h6 class="text-muted text-uppercase small fw-bold mb-3">Diferença de Ocupação</h6>
                                    <div style="position: relative; height: 260px;">
                                        <canvas id="diffOccupancyChart"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <div class="card h-100">
                                <div class="card-body">
                                    <h6 class="text-muted text-uppercase small fw-bold mb-3">Diferença de Desperdício</h6>
                                    <div style="position: relative; height: 260px;">
                                        <canvas id="diffWasteChart"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <div class="card h-100">
                                <div class="card-body">
                                    <h6 class="text-muted text-uppercase small fw-bold mb-3">Diferença de Claustrofobia</h6>
                                    <div style="position: relative; height: 260px;">
                                        <canvas id="diffClaustrophobiaChart"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card mb-5">
                        <div class="card-body table-responsive">
                            <table class="table table-sm table-bordered text-center" style="font-size:13px;">
                                <thead class="thead-light">
                                    <tr>
                                        <th>Métrica</th>
                                        <th>n pares</th>
                                        <th>Média do diff</th>
                                        <th>DP do diff</th>
                                        <th>EP do diff</th>
                                        <th>IC 95% inferior</th>
                                        <th>IC 95% superior</th>
                                        <th>Mediana do diff</th>
                                        <th>Positivos (Solver melhor)</th>
                                        <th>Negativos (Legado melhor)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach (['diff_occupancy' => 'Ocupação', 'diff_waste' => 'Desperdício', 'diff_claustrophobia' => 'Claustrofobia'] as $dk => $dl)
                                        @php $ps = $analytics['paired']['stats'][$dk] ?? []; @endphp
                                        <tr>
                                            <td class="text-left">{{ $dl }}</td>
                                            <td>{{ $ps['n'] ?? 0 }}</td>
                                            <td>{{ $fmt($ps['mean_diff'] ?? null, 4) }}</td>
                                            <td>{{ $fmt($ps['sd_diff'] ?? null, 4) }}</td>
                                            <td>{{ $fmt($ps['se_diff'] ?? null, 4) }}</td>
                                            <td>{{ $fmt($ps['ci95_lower'] ?? null, 4) }}</td>
                                            <td>{{ $fmt($ps['ci95_upper'] ?? null, 4) }}</td>
                                            <td>{{ $fmt($ps['median_diff'] ?? null, 4) }}</td>
                                            <td>{{ $ps['n_positive'] ?? 0 }}</td>
                                            <td>{{ $ps['n_negative'] ?? 0 }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    {{-- ============================ --}}
                    {{-- Matriz de Contingência Bloco --}}
                    {{-- ============================ --}}
                    <h3 class="mb-3">Matriz de Contingência de Bloco</h3>
                    <p class="text-muted small mb-3">
                        Linhas = bloco esperado (calouros → A; pós-graduação → B).
                        Colunas = bloco real da sala alocada.
                        Células coloridas pela frequência relativa.
                    </p>
                    <div class="row mb-5">
                        @foreach (['legacy' => 'Legado', 'solver' => 'Solver CP-SAT'] as $engKey => $engLabel)
                            <div class="col-md-6 mb-3">
                                <div class="card h-100">
                                    <div class="card-body">
                                        <h6 class="text-muted text-uppercase small fw-bold mb-3">{{ $engLabel }}</h6>
                                        <table class="table table-sm table-bordered text-center" style="font-size:13px;">
                                            <thead class="thead-light">
                                                <tr>
                                                    <th>Esperado \ Real</th>
                                                    @foreach ($analytics['block_contingency']['categories'] as $cat)
                                                        <th>{{ $cat }}</th>
                                                    @endforeach
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @php $matrix = $analytics['block_contingency'][$engKey] ?? []; $maxVal = 0; @endphp
                                                @foreach ($matrix as $row)
                                                    @foreach ($row as $v)
                                                        @php if ($v > $maxVal) $maxVal = $v; @endphp
                                                    @endforeach
                                                @endforeach
                                                @foreach ($matrix as $exp => $row)
                                                    <tr>
                                                        <td class="font-weight-bold">{{ $exp }}</td>
                                                        @foreach ($row as $act => $v)
                                                            @php
                                                                $intensity = $maxVal > 0 ? ($v / $maxVal) : 0;
                                                                $red = 255;
                                                                $green = (int) round(255 * (1 - $intensity));
                                                                $blue = (int) round(255 * (1 - $intensity));
                                                                $color = "rgb({$red},{$green},{$blue})";
                                                                $textColor = $intensity > 0.5 ? '#fff' : '#212529';
                                                            @endphp
                                                            <td style="background-color: {{ $color }}; color: {{ $textColor }};">{{ $v }}</td>
                                                        @endforeach
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    {{-- ============================ --}}
                    {{-- Concordância / Transição   --}}
                    {{-- ============================ --}}
                    <h3 class="mb-3">Concordância e Transições de Sala</h3>
                    <div class="row mb-4">
                        <div class="col-md-4 mb-3">
                            <div class="card text-center h-100 border-success">
                                <div class="card-body">
                                    <div class="text-muted small text-uppercase fw-semibold mb-2">Mesma Sala</div>
                                    <div class="fs-4 fw-bold">{{ $analytics['paired']['same_room'] ?? 0 }}</div>
                                    <div class="small text-muted">{{ $fmt($analytics['paired']['agreement_rate'] ?? null, 1) }}% de concordância</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <div class="card text-center h-100 border-warning">
                                <div class="card-body">
                                    <div class="text-muted small text-uppercase fw-semibold mb-2">Sala Trocada</div>
                                    <div class="fs-4 fw-bold">{{ $analytics['paired']['changed_room'] ?? 0 }}</div>
                                    <div class="small text-muted">turmas com room_id diferente</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <div class="card text-center h-100 border-info">
                                <div class="card-body">
                                    <div class="text-muted small text-uppercase fw-semibold mb-2">Pares Alocadas</div>
                                    <div class="fs-4 fw-bold">{{ $analytics['paired']['n_pairs'] ?? 0 }}</div>
                                    <div class="small text-muted">turmas alocadas por ambos</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card mb-5">
                        <div class="card-body">
                            <h6 class="text-muted text-uppercase small fw-bold mb-3">Distribuição da Variação de Capacidade (Solver − Legado) nas Turmas que Trocaram de Sala</h6>
                            <div style="position: relative; height: 320px;">
                                <canvas id="capacityDeltaChart"></canvas>
                            </div>
                        </div>
                    </div>

                    @if (!empty($analytics['agreement']['block_transitions']))
                        <div class="card mb-5">
                            <div class="card-body table-responsive">
                                <h6 class="text-muted text-uppercase small fw-bold mb-3">Transições de Bloco (Legado → Solver)</h6>
                                <table class="table table-sm table-bordered text-center" style="font-size:13px;">
                                    <thead class="thead-light">
                                        <tr><th>Transição</th><th>Contagem</th></tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($analytics['agreement']['block_transitions'] as $trans => $cnt)
                                            <tr>
                                                <td class="text-left">{{ $trans }}</td>
                                                <td>{{ $cnt }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endif
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
    const analytics     = @json($analytics);

    // Helper: format number pt-BR
    function fmt(v, d) {
        if (v === null || v === undefined) return 'n/a';
        return Number(v).toFixed(d).replace('.', ',');
    }

    // -------------------------------------------------------------
    // Normalizacao 0-100 para o Radar Chart (Relative Max/Min Scaling).
    // -------------------------------------------------------------
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

    const legacyRaw = [];
    const solverRaw = [];
    axes.forEach(function (key, i) {
        legacyRaw[i] = val(legacyMetrics, key);
        solverRaw[i] = val(solverMetrics, key);
    });

    const legacyNorm = [];
    const solverNorm = [];
    axes.forEach(function (key, i) {
        const lv = legacyRaw[i];
        const sv = solverRaw[i];
        const isHigher = higherBetter.indexOf(key) !== -1;

        if (isHigher) {
            const maxVal = Math.max(
                (lv === null ? -Infinity : lv),
                (sv === null ? -Infinity : sv)
            );
            function scaleHigh(v) {
                if (v === null) return 0;
                if (maxVal <= 1e-9) return 0;
                return Math.max(0, Math.min(100, (v / maxVal) * 100));
            }
            legacyNorm[i] = scaleHigh(lv);
            solverNorm[i] = scaleHigh(sv);
        } else {
            const minVal = Math.min(
                (lv === null ? Infinity : lv),
                (sv === null ? Infinity : sv)
            );
            function scaleLow(v) {
                if (v === null) return 0;
                if (v <= 1e-9) return 100;
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

        // ----- Scatter Plot (melhorado) -----
        const minPct = comfortZone.min_percent;
        const maxPct = comfortZone.max_percent;
        const yMinSlope = 1 + minPct / 100;
        const yMaxSlope = 1 + maxPct / 100;

        let maxDemand = 0;
        ['legacy', 'solver'].forEach(function (src) {
            (scatterData[src] || []).forEach(function (p) {
                if (p.x > maxDemand) maxDemand = p.x;
            });
        });
        if (maxDemand <= 0) maxDemand = 100;
        const plotMax = maxDemand * 1.1;

        const zoneSteps = 50;
        const zoneUpper = [];
        const zoneLower = [];
        const lineMinComfort = [];
        const lineMaxComfort = [];
        const lineYX = [];
        for (let i = 0; i <= zoneSteps; i++) {
            const x = plotMax * (i / zoneSteps);
            zoneUpper.push({ x: x, y: x * yMaxSlope });
            zoneLower.push({ x: x, y: x * yMinSlope });
            lineMinComfort.push({ x: x, y: x * yMinSlope });
            lineMaxComfort.push({ x: x, y: x * yMaxSlope });
            lineYX.push({ x: x, y: x });
        }
        const zonePolygon = zoneUpper.concat(zoneLower.slice().reverse());

        new Chart(document.getElementById('scatterChart'), {
            type: 'scatter',
            data: {
                datasets: [
                    {
                        label: 'Zona de Conforto',
                        data: zonePolygon,
                        backgroundColor: 'rgba(40, 167, 69, 0.12)',
                        borderColor: 'rgba(40, 167, 69, 0.4)',
                        borderWidth: 1,
                        showLine: true,
                        fill: true,
                        pointRadius: 0,
                        order: 5,
                    },
                    {
                        label: 'y = x (capacidade = demanda)',
                        data: lineYX,
                        borderColor: 'rgba(108, 117, 125, 0.6)',
                        borderWidth: 1,
                        borderDash: [6, 4],
                        showLine: true,
                        pointRadius: 0,
                        fill: false,
                        order: 4,
                    },
                    {
                        label: 'Fronteira inferior conforto',
                        data: lineMinComfort,
                        borderColor: 'rgba(40, 167, 69, 0.5)',
                        borderWidth: 1,
                        borderDash: [4, 4],
                        showLine: true,
                        pointRadius: 0,
                        fill: false,
                        order: 3,
                    },
                    {
                        label: 'Fronteira superior conforto',
                        data: lineMaxComfort,
                        borderColor: 'rgba(40, 167, 69, 0.5)',
                        borderWidth: 1,
                        borderDash: [4, 4],
                        showLine: true,
                        pointRadius: 0,
                        fill: false,
                        order: 2,
                    },
                    {
                        label: 'Legado',
                        data: scatterData.legacy || [],
                        backgroundColor: 'rgba(220, 53, 69, 0.45)',
                        borderColor: 'rgba(220, 53, 69, 1)',
                        pointRadius: 3,
                        pointHoverRadius: 6,
                        order: 1,
                    },
                    {
                        label: 'Solver CP-SAT',
                        data: scatterData.solver || [],
                        backgroundColor: 'rgba(0, 123, 255, 0.45)',
                        borderColor: 'rgba(0, 123, 255, 1)',
                        pointRadius: 3,
                        pointHoverRadius: 6,
                        order: 0,
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
                                const occ = d.x > 0 ? (d.x / d.y).toFixed(2) : 'n/a';
                                return ctx.dataset.label + ': (' + d.x + ', ' + d.y + ') — ocupação ' + occ;
                            },
                        },
                    },
                },
            },
        });

        // ----- Estatísticas de Posição (Bar Chart Horizontal) -----
        const sumLegacy = analytics.summary.legacy.occupancy_ratio || {};
        const sumSolver = analytics.summary.solver.occupancy_ratio || {};
        new Chart(document.getElementById('summaryBarChart'), {
            type: 'bar',
            data: {
                labels: ['Mínimo', 'Q1', 'Mediana', 'Q3', 'Máximo'],
                datasets: [
                    {
                        label: 'Legado',
                        data: [
                            sumLegacy.min,
                            sumLegacy.q1,
                            sumLegacy.median,
                            sumLegacy.q3,
                            sumLegacy.max,
                        ],
                        backgroundColor: 'rgba(220, 53, 69, 0.6)',
                        borderColor: 'rgba(220, 53, 69, 1)',
                        borderWidth: 1,
                    },
                    {
                        label: 'Solver CP-SAT',
                        data: [
                            sumSolver.min,
                            sumSolver.q1,
                            sumSolver.median,
                            sumSolver.q3,
                            sumSolver.max,
                        ],
                        backgroundColor: 'rgba(0, 123, 255, 0.6)',
                        borderColor: 'rgba(0, 123, 255, 1)',
                        borderWidth: 1,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                scales: {
                    x: {
                        title: { display: true, text: 'Razão de Ocupação (demanda / capacidade)' },
                        min: 0,
                    },
                },
                plugins: {
                    legend: { position: 'top' },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                return ctx.dataset.label + ': ' + fmt(ctx.raw, 3);
                            },
                        },
                    },
                },
            },
        });

        // ----- Histograma Ocupação -----
        const occHist = analytics.histograms.occupancy || { labels: [], legacy: [], solver: [] };
        new Chart(document.getElementById('occHistogramChart'), {
            type: 'bar',
            data: {
                labels: occHist.labels,
                datasets: [
                    {
                        label: 'Legado',
                        data: occHist.legacy,
                        backgroundColor: 'rgba(220, 53, 69, 0.5)',
                        borderColor: 'rgba(220, 53, 69, 1)',
                        borderWidth: 1,
                    },
                    {
                        label: 'Solver CP-SAT',
                        data: occHist.solver,
                        backgroundColor: 'rgba(0, 123, 255, 0.5)',
                        borderColor: 'rgba(0, 123, 255, 1)',
                        borderWidth: 1,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: { title: { display: true, text: 'Faixa de Ocupação' } },
                    y: { title: { display: true, text: 'Contagem' }, beginAtZero: true },
                },
                plugins: { legend: { position: 'top' } },
            },
        });

        // ----- Histogramas Pareados (Diffs) -----
        function renderDiffBar(canvasId, histData, title, color) {
            if (!histData || !histData.labels) return;
            new Chart(document.getElementById(canvasId), {
                type: 'bar',
                data: {
                    labels: histData.labels,
                    datasets: [{
                        label: 'Frequência',
                        data: histData.counts,
                        backgroundColor: color,
                        borderColor: color.replace(/0\.5\)/, '1)'),
                        borderWidth: 1,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: { title: { display: true, text: title } },
                        y: { beginAtZero: true },
                    },
                    plugins: {
                        legend: { display: false },
                        annotation: { annotations: {} },
                    },
                },
            });
        }

        renderDiffBar('diffOccupancyChart', analytics.histograms.diff_occupancy, 'Diff Ocupação', 'rgba(108, 117, 125, 0.6)');
        renderDiffBar('diffWasteChart', analytics.histograms.diff_waste, 'Diff Desperdício', 'rgba(220, 53, 69, 0.5)');
        renderDiffBar('diffClaustrophobiaChart', analytics.histograms.diff_claustrophobia, 'Diff Claustrofobia', 'rgba(0, 123, 255, 0.5)');

        // ----- Capacity Delta Chart -----
        const capDelta = analytics.agreement.capacity_delta_bins || { labels: [], solver: [] };
        new Chart(document.getElementById('capacityDeltaChart'), {
            type: 'bar',
            data: {
                labels: capDelta.labels,
                datasets: [{
                    label: 'Turmas que trocaram de sala',
                    data: capDelta.solver,
                    backgroundColor: 'rgba(255, 193, 7, 0.6)',
                    borderColor: 'rgba(255, 193, 7, 1)',
                    borderWidth: 1,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                scales: {
                    x: { title: { display: true, text: 'Contagem' }, beginAtZero: true },
                },
                plugins: { legend: { display: false } },
            },
        });
    }
})();
</script>
@endif
<script>
    if (typeof jQuery !== 'undefined') {
        jQuery(function ($) {
            $('[data-toggle="tooltip"]').tooltip();
        });
    }
</script>
@endsection
