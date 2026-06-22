@extends('main')

@section('title', 'Comparação de Algoritmos')

@php
    $fmt = function ($v, $d = 1) {
        if ($v === null) return '-';
        return number_format((float) $v, $d, ',', '.');
    };
@endphp

@section('content')
  @parent
  <div id="layout_conteudo">
    <div class="justify-content-center">
        <div class="col-md-12">
            <h1 class='text-center mb-5'>Comparação de Algoritmos</h1>

            @if (!empty($summaryByTerm))
                <div class="mb-5">
                    <h4 class="mb-3">Resumo por Semestre (Cross-Run)</h4>
                    <p class="text-muted small">Média ± DP amostral sobre relatórios concluídos do mesmo semestre. Indica estabilidade run-to-run.</p>
                    @foreach ($summaryByTerm as $termId => $termSummary)
                        <div class="card mb-3">
                            <div class="card-header bg-light fw-bold">
                                {{ $termSummary['term_name'] }} — {{ $termSummary['n_reports'] }} relatório(s) concluído(s)
                            </div>
                            <div class="card-body table-responsive">
                                <table class="table table-sm table-bordered text-center" style="font-size:13px;">
                                    <thead class="thead-light">
                                        <tr>
                                            <th>Métrica</th>
                                            <th>Legado (média ± DP)</th>
                                            <th>Solver CP-SAT (média ± DP)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ([
                                            'allocation_rate' => 'Taxa de Alocação',
                                            'comfort_zone_rate' => 'Zona de Conforto',
                                            'avg_waste_per_class' => 'Desperdício Médio',
                                            'avg_claustrophobia_per_class' => 'Claustrofobia Média',
                                            'block_adherence_rate' => 'Aderência de Bloco',
                                            'solve_time_seconds' => 'Tempo de Resolução (s)',
                                        ] as $mk => $ml)
                                            <tr>
                                                <td class="text-left">{{ $ml }}</td>
                                                <td>
                                                    @php
                                                        $leg = $termSummary['engines']['legacy'][$mk] ?? [];
                                                        $legN = $leg['n'] ?? 0;
                                                    @endphp
                                                    @if ($legN > 0)
                                                        {{ $fmt($leg['mean'], 2) }} ± {{ $fmt($leg['sd'], 2) }}
                                                        <span class="text-muted">(n={{ $legN }})</span>
                                                    @else
                                                        <span class="text-muted">-</span>
                                                    @endif
                                                </td>
                                                <td>
                                                    @php
                                                        $sol = $termSummary['engines']['solver'][$mk] ?? [];
                                                        $solN = $sol['n'] ?? 0;
                                                    @endphp
                                                    @if ($solN > 0)
                                                        {{ $fmt($sol['mean'], 2) }} ± {{ $fmt($sol['sd'], 2) }}
                                                        <span class="text-muted">(n={{ $solN }})</span>
                                                    @else
                                                        <span class="text-muted">-</span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            @if ($reports->count() > 0)
                <table class="table table-bordered table-striped table-hover" style="font-size:14px;">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Semestre</th>
                            <th>Estado Base</th>
                            <th>Status</th>
                            <th>Criado em</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($reports as $report)
                            <tr>
                                <td>{{ $report->id }}</td>
                                <td>{{ $report->schoolTerm ? $report->schoolTerm->year . '.' . $report->schoolTerm->period : '-' }}</td>
                                <td>{{ $report->baseAllocationState ? $report->baseAllocationState->name : '-' }}</td>
                                <td>
                                    @if ($report->status === 'completed')
                                        <span class="badge badge-success">concluído</span>
                                    @elseif ($report->status === 'processing')
                                        <span class="badge badge-warning">processando</span>
                                    @elseif ($report->status === 'failed')
                                        <span class="badge badge-danger">falhou</span>
                                    @else
                                        <span class="badge badge-secondary">{{ $report->status }}</span>
                                    @endif
                                </td>
                                <td>{{ $report->created_at ? $report->created_at->format('d/m/Y H:i:s') : '-' }}</td>
                                <td class="text-center">
                                    <a href="{{ route('comparison-reports.show', $report) }}" class="btn btn-outline-dark btn-sm">Ver</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <div class="d-flex justify-content-center">
                    {{ $reports->links('pagination::bootstrap-4') }}
                </div>
            @else
                <p class="text-center">Nenhum relatório de comparação encontrado.</p>
            @endif
        </div>
    </div>
  </div>
@endsection
