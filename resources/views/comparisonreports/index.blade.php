@extends('main')

@section('title', 'Comparação de Algoritmos')

@section('content')
  @parent
  <div id="layout_conteudo">
    <div class="justify-content-center">
        <div class="col-md-12">
            <h1 class='text-center mb-5'>Comparação de Algoritmos</h1>

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
